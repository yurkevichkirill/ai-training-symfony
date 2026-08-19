<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthEvent;
use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes one `AuthEvent` row per call (AC-24), in a transaction that cannot
 * be undone by whatever business transaction is or is not in flight.
 *
 * **Why "reuse the injected EntityManager" is not good enough -- verified,
 * not assumed.** The obvious-looking implementation --
 * `$entityManager->persist($authEvent); $entityManager->flush();` against
 * whatever `EntityManagerInterface` gets autowired here -- was tested
 * directly against three call sites this task actually has:
 *
 * 1. `AuthEventSubscriber::onLoginFailure()`: no business transaction is
 *    open at all, so a plain flush on the shared EntityManager would work
 *    here specifically. But (2) and (3) below are real call sites too, and
 *    a recorder that only works when nothing else is happening is not the
 *    "own transaction scope, independent of whatever is in flight" this
 *    class is required to be.
 * 2. `PasswordResetService::complete()` calls `record()` for
 *    `PASSWORD_RESET_COMPLETED` from *inside* its own
 *    `EntityManagerInterface::wrapInTransaction()` callback. A flush on the
 *    same EntityManager there does not commit independently -- it is a
 *    nested operation on the *same DBAL Connection*, which only physically
 *    commits once the outer transaction's nesting level reaches zero. If
 *    anything after that flush throws, the whole thing -- including the
 *    just-written audit row -- rolls back with it.
 * 3. Confirmed empirically with a throwaway `KernelTestCase` probe (built
 *    while implementing this class, not committed): a transaction opened on
 *    the container's `doctrine.dbal.default_connection`, a *second*
 *    `EntityManager` obtained via `ManagerRegistry::resetManager()` (the
 *    same recovery trick `UserAccountService`/`EmailVerificationTokenService`/
 *    `PasswordResetService` use for the closed-EntityManager pitfall), a
 *    write through that second EntityManager, then a rollback on the
 *    *first* connection. The second EntityManager's write -- and even a
 *    bare `CREATE TABLE` run through it -- was undone by that rollback.
 *    `resetManager()` gives a fresh EntityManager and UnitOfWork, but
 *    `ManagerRegistry` still resolves the underlying `Connection` from the
 *    container's single shared `doctrine.dbal.default_connection` service,
 *    so both EntityManagers share one physical transaction whether or not
 *    either one ever calls `wrapInTransaction()` itself. A second
 *    `EntityManagerInterface` is therefore not sufficient; a second
 *    `Connection` is required.
 *
 * **The mechanism this class actually uses.** `record()` opens (once, then
 * reuses) a brand-new physical `Doctrine\DBAL\Connection` via
 * `DriverManager::getConnection()`, cloning the container connection's own
 * `getParams()` -- same host, database, credentials, driver -- and wraps it
 * in its own `Doctrine\ORM\EntityManager`, sharing the container's ORM
 * `Configuration` (metadata driver, proxy settings) since that is pure,
 * read-only mapping information safe to share across EntityManager
 * instances. Because this is a second physical connection/session to the
 * database, not a second object over the same one, it has its own
 * transaction: `flush()` here commits (or fails) entirely on its own,
 * regardless of any transaction open on the business connection, in either
 * direction -- confirmed with the same probe as point 3 above, run again
 * with a manually-constructed second `Connection` in place of
 * `resetManager()`: its write survived the business connection's rollback
 * every time.
 *
 * **The identity-map/readonly-id proxy hazard -- investigated, not
 * triggered.** `EmailVerificationTokenService::consume()` and
 * `PasswordResetService::complete()` both document a real bug: hydrating an
 * already-referenced `User` proxy a second time (via a JOIN or an
 * association fetch that forces full initialization) conflicts with
 * `User::$id`'s readonly, object-typed `Uuid`. `AuthEvent` maps the
 * identical `#[ORM\ManyToOne(targetEntity: User::class)]` shape, and this
 * class does resolve `$record->userId` into a `User` reference via
 * `$entityManager->getReference(User::class, $userId)`. The two situations
 * are not the same, though: this reference is only ever used to populate
 * `AuthEvent`'s foreign key for an **insert** -- nothing here ever calls a
 * getter on it, and Doctrine reads an uninitialized proxy's identifier
 * directly (it was written once, at `getReference()`'s own construction,
 * without triggering `__load()`) rather than re-hydrating it. The bug the
 * other two services hit requires the proxy to be fully re-initialized
 * (something calling a *non-identifier* accessor on it), which never
 * happens here. This is also structurally different from the earlier two
 * cases in one more way: this class's `EntityManager` is *always* freshly
 * constructed with an empty identity map (see above), so there is no other
 * object for this `User` row already sitting in it to collide with, even in
 * principle. Verified with a targeted regression test,
 * {@see \App\Tests\Service\AuthEventRecorderIdentityMapTest}: recording an
 * event against a user who was never independently loaded in this
 * process -- the same "genuinely fresh request" condition that reproduces
 * the bug for the other two services -- persists successfully.
 */
final class AuthEventRecorder
{
    private ?EntityManagerInterface $auditEntityManager = null;

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    public function record(AuthEventRecord $record): void
    {
        $entityManager = $this->independentEntityManager();

        $user = null !== $record->userId
            ? $entityManager->getReference(User::class, $record->userId)
            : null;

        $authEvent = new AuthEvent(
            $record->type->value,
            $record->outcome,
            new \DateTimeImmutable(),
            $user,
            $record->identifierAttempted,
            $record->ip,
            $record->userAgent,
            $record->context,
        );

        $entityManager->persist($authEvent);
        $entityManager->flush();

        // Nothing else in this process shares this EntityManager, so
        // clearing it costs nothing and keeps its identity map from
        // accumulating `User` references across a long-lived worker
        // process (a console command or Messenger consumer handling many
        // events in one run) that never otherwise touches it.
        $entityManager->clear();
    }

    /**
     * Returns this recorder's own EntityManager, over its own physical
     * Connection -- see the class docblock for why a second Connection,
     * not merely a second EntityManager, is what "independent" requires
     * here. Built lazily and cached for the lifetime of this service
     * instance: nothing about the connection parameters changes between
     * calls within one process, and opening a fresh database connection
     * per `record()` call would be wasteful for callers (like
     * `AuthEventSubscriber`) that may record more than one event per
     * request.
     */
    private function independentEntityManager(): EntityManagerInterface
    {
        if (null !== $this->auditEntityManager && $this->auditEntityManager->isOpen()) {
            return $this->auditEntityManager;
        }

        $businessManager = $this->managerRegistry->getManager();
        \assert($businessManager instanceof EntityManagerInterface);

        $businessConnection = $businessManager->getConnection();

        $independentConnection = DriverManager::getConnection(
            $businessConnection->getParams(),
            $businessConnection->getConfiguration(),
        );

        $this->auditEntityManager = new EntityManager($independentConnection, $businessManager->getConfiguration());

        return $this->auditEntityManager;
    }
}
