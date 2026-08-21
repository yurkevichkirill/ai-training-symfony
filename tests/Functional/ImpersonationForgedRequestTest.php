<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\ImpersonationEndReason;
use App\Enum\UserRole;
use App\Service\AccountLifecycleService;
use App\Service\Exception\ImpersonationNotPermittedException;
use App\Service\ImpersonationService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 (AC-3, AC-11, BR-001, BR-002) -- the forged-request half of the
 * refusal suite. `ImpersonationRefusalTest` proves the *UI* never offers a
 * forbidden switch and that a missing/garbage CSRF token is refused; this
 * class attacks `SwitchUserListener` directly, on the three carriers it
 * actually reads (`?_switch_user`, the POST body, and the `_switch_user`
 * **header**), plus the service guard behind them.
 *
 * Every case here is a request an attacker or a curious Super Admin can
 * hand-build -- none of them goes through a rendered form.
 */
final class ImpersonationForgedRequestTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /**
     * Ids, not emails: `AccountLifecycleService::delete()` anonymizes
     * `app_user` in place, so a row this test deleted no longer matches the
     * email it was created with, and an email-keyed cleanup would leak it
     * into every later test (`CreateSuperAdminCommandTest` counts Super
     * Admins regardless of status).
     *
     * @var list<string>
     */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_deletion_log WHERE subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE actor_user_id = :id OR subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM impersonation_session WHERE actor_user_id = :id OR subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * The regression test for the header carrier. `SwitchUserListener::supports()`
     * falls back to `$request->headers->get('_switch_user')` when the
     * parameter is absent (or empty) in both the query and the body, so a
     * guard that reads only the query and the body lets a header-carried
     * switch through with no POST requirement and no CSRF token at all.
     * This must stay red if `ImpersonationGuardSubscriber` ever stops
     * reading the header.
     */
    public function testSwitchUserCarriedInARequestHeaderIsRefusedAndCreatesNoSession(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $this->client->request('GET', '/', server: ['HTTP__SWITCH_USER' => $trainer->getEmail()]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainer));
    }

    /**
     * Same carrier, on a POST that does carry a *valid* CSRF token -- but
     * one minted for the legitimate target, while the header names someone
     * else. The token-id binding is derived from the same value the switch
     * acts on, so a proof issued for target A cannot be re-pointed at B.
     */
    public function testAValidCsrfTokenForOneTargetDoesNotAuthoriseASwitchToAnother(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainerA->getId()));
        $form = $crawler->selectButton(\sprintf('View platform as %s', $trainerA->getDisplayName()))->form();
        $token = $form->get('_token')->getValue();
        self::assertIsString($token);

        // Re-point the very same, still-valid proof at a different target.
        $this->client->request('POST', \sprintf('/admin/users/%s/impersonate', $trainerB->getId()), [
            '_switch_user' => $trainerB->getEmail(),
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainerB));
        self::assertSame(0, $this->countSessionsFor($trainerA));
    }

    /**
     * BR-002 against a hand-built request rather than through the UI: the
     * Users directory hides the action on a Super Admin row and the
     * confirmation route 403s before rendering, but neither of those is
     * what protects the switch itself. `ImpersonationVoter` is, and it is
     * consulted by `SwitchUserListener` on every carrier -- here the
     * header, which reaches the listener without a controller ever running.
     */
    public function testAForgedHeaderSwitchNamingAnotherSuperAdminIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $otherAdmin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->signIn($admin);

        $this->client->request('GET', '/', server: ['HTTP__SWITCH_USER' => $otherAdmin->getEmail()]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($otherAdmin));
    }

    /**
     * Nesting, forged: a confirmation form for target B legitimately
     * obtained *before* switching (a second browser tab), submitted while
     * a session on target A is live. The guard's own nesting branch cannot
     * see this: it runs at `kernel.request` priority 32, above the
     * firewall's 8, so token storage is still empty there. Nor can
     * `ImpersonationVoter`'s no-nesting clause, because the native listener
     * exits the live session *before* asking the voter and so shows it the
     * restored original admin token. `ImpersonationSwitchSubscriber` is what
     * refuses it, on the listener's implicit exit and before that exit is
     * recorded -- which is why the live session must still be open
     * afterwards.
     */
    public function testAPreObtainedConfirmationFormSubmittedWhileAlreadyImpersonatingIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        // Tab 2: the confirmation page for B, still open and still valid.
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainerB->getId()));
        $formB = $crawler->selectButton(\sprintf('View platform as %s', $trainerB->getDisplayName()))->form();
        $tokenB = $formB->get('_token')->getValue();
        self::assertIsString($tokenB);

        // Tab 1: switch to A.
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainerA->getId()));
        $this->client->submit($crawler->selectButton(\sprintf('View platform as %s', $trainerA->getDisplayName()))->form());
        $this->client->followRedirect();

        // Tab 2 submitted now: a genuinely valid CSRF token for B, while a
        // session on A is open.
        $this->client->request('POST', \sprintf('/admin/users/%s/impersonate', $trainerB->getId()), [
            '_switch_user' => $trainerB->getEmail(),
            '_token' => $tokenB,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainerB), 'No second session may be opened.');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainerA->getId()],
        );
        self::assertIsArray($row);
        self::assertNull($row['ended_at'], 'The live session must be left open and untouched.');
    }

    /**
     * The service guard, exercised directly -- the second half of this
     * project's "every deny rule exists as both a voter and a service
     * guard" convention. `ImpersonationVoter` is unit-tested as a truth
     * table; these are the same five refusals re-derived inside
     * `ImpersonationService::start()`, which is what protects any future
     * caller that does not route through `SwitchUserListener`.
     */
    public function testServiceStartGuardsRefuseEveryForbiddenPairing(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $otherAdmin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $deactivated = $this->persist(UserFactory::deactivated(UserRole::TRAINER));
        $deactivatedAdmin = $this->persist(UserFactory::deactivated(UserRole::SUPER_ADMIN));

        $service = self::getContainer()->get(ImpersonationService::class);
        \assert($service instanceof ImpersonationService);

        $this->assertRefused($service, $admin, $otherAdmin, 'a Super Admin subject');
        $this->assertRefused($service, $admin, $deactivated, 'a deactivated subject');
        $this->assertRefused($service, $admin, $admin, 'the actor themselves');
        $this->assertRefused($service, $trainer, $deactivated, 'a non-Super-Admin actor');
        $this->assertRefused($service, $deactivatedAdmin, $trainer, 'a deactivated Super Admin actor');

        // The nesting guard: a legitimate session first, then a second
        // start() for the same actor on a different subject.
        $service->start($admin, $trainer);
        $secondTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->assertRefused($service, $admin, $secondTrainer, 'an actor who already has an open session');

        self::assertSame(0, $this->countSessionsFor($secondTrainer));
        self::assertSame(0, $this->countSessionsFor($otherAdmin));
    }

    /**
     * The `RESTRICT`-foreign-key risk the architecture names explicitly:
     * `impersonation_session` pins both parties with `onDelete: RESTRICT`,
     * so a user with impersonation history is undeletable *at the row
     * level*. S2's deletion path anonymizes `app_user` in place instead of
     * deleting the row, which is what keeps them deletable -- but nothing
     * had ever exercised the two together. Both directions are covered:
     * the subject of a past session, and the Super Admin who was its actor.
     */
    public function testDeletingEitherPartyToAPastSessionCompletesAndKeepsTheAuditRow(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $deleter = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));

        $service = self::getContainer()->get(ImpersonationService::class);
        \assert($service instanceof ImpersonationService);
        $session = $service->start($admin, $trainer);
        $service->end($admin, ImpersonationEndReason::EXPLICIT_EXIT);

        $lifecycle = self::getContainer()->get(AccountLifecycleService::class);
        \assert($lifecycle instanceof AccountLifecycleService);

        [$freshTrainer, $freshDeleter] = $this->freshPair($trainer, $deleter);
        $lifecycle->delete($freshTrainer, $freshDeleter, 'subject of a past session');

        [$freshAdmin, $freshDeleter] = $this->freshPair($admin, $deleter);
        $lifecycle->delete($freshAdmin, $freshDeleter, 'actor of a past session');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT actor_user_id, subject_user_id FROM impersonation_session WHERE id = :id',
            ['id' => (string) $session->getId()],
        );
        self::assertIsArray($row, 'The compliance row must survive both deletions.');
        self::assertSame((string) $admin->getId(), $row['actor_user_id']);
        self::assertSame((string) $trainer->getId(), $row['subject_user_id']);
    }

    /**
     * Both users re-read after a single `clear()` -- fetching them one
     * `clear()` apart would leave the first one detached, which Doctrine
     * then reports as "a new entity was found through the relationship".
     *
     * @return array{0: User, 1: User}
     */
    private function freshPair(User $subject, User $actor): array
    {
        $this->em->clear();
        $freshSubject = $this->em->find(User::class, $subject->getId());
        $freshActor = $this->em->find(User::class, $actor->getId());
        self::assertInstanceOf(User::class, $freshSubject);
        self::assertInstanceOf(User::class, $freshActor);

        return [$freshSubject, $freshActor];
    }

    private function assertRefused(ImpersonationService $service, User $actor, User $subject, string $because): void
    {
        try {
            $service->start($actor, $subject);
        } catch (ImpersonationNotPermittedException) {
            return;
        }

        self::fail(\sprintf('ImpersonationService::start() must refuse %s.', $because));
    }

    private function countSessionsFor(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $subject->getId()],
        );
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
