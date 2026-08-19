<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuthEvent;
use App\Enum\AuthEventType;
use App\Enum\UserRole;
use App\Service\AuthEventRecord;
use App\Service\AuthEventRecorder;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Targeted investigation of whether `AuthEventRecorder` can hit the same
 * readonly-identifier/lazy-proxy hydration bug Task 28 found in
 * `EmailVerificationTokenService::consume()` and Task 30 re-confirmed in
 * `PasswordResetService::complete()` -- both for the identical `User`
 * association shape `AuthEvent` maps. This is that investigation for
 * `AuthEventRecorder`, done empirically as Task 34's own instructions
 * require, not assumed safe by analogy.
 *
 * The mechanism differs from the other two in a way that turns out to
 * matter (see `AuthEventRecorder`'s class docblock for the full
 * reasoning): the other services' bug requires an already-referenced proxy
 * to be *fully re-initialized* (a getter other than the identifier forces
 * `__load()`, and the hydrator then tries to re-set the readonly `$id`).
 * `AuthEventRecorder::record()` only ever uses `getReference()` to populate
 * `AuthEvent`'s foreign key for an insert -- it never calls any getter on
 * that reference -- and its `EntityManager` is always freshly constructed
 * with an empty identity map, so there is no pre-existing object for that
 * row to collide with in the first place. This test proves the *outcome*
 * (record() must actually succeed against a user this process never
 * independently loaded through the recorder's own EntityManager -- the
 * exact "genuinely fresh" precondition that reproduces the bug for the
 * other two services) rather than merely asserting the reasoning above.
 */
final class AuthEventRecorderIdentityMapTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuthEventRecorder $recorder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->recorder = self::getContainer()->get(AuthEventRecorder::class);
    }

    protected function tearDown(): void
    {
        // AuthEventRecorder writes through its own, genuinely separate
        // physical connection (see its class docblock) and commits
        // immediately -- there is no outer test transaction to roll this
        // back the way most of this suite's fixtures are cleaned up, so
        // this test cleans up explicitly instead.
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM auth_event WHERE identifier_attempted = :email OR user_id IN (SELECT id FROM app_user WHERE email = :email)', [
            'email' => 'auth-event-identity-map@example.test',
        ]);
        $connection->executeStatement('DELETE FROM app_user WHERE email = :email', [
            'email' => 'auth-event-identity-map@example.test',
        ]);

        parent::tearDown();
    }

    /**
     * The security-critical case: `AuthEventSubscriber` and the services
     * that call `AuthEventRecorder` directly all pass a `userId` for a
     * `User` this process may never have loaded through the recorder's own
     * EntityManager -- which is *every* call, since that EntityManager is
     * always freshly constructed (see the class docblock). A real
     * `User` row that exists in the database, but was only ever loaded
     * through a *different* EntityManager (the container's own, here), is
     * the closest a single-process test gets to that condition.
     */
    public function testRecordingAgainstAUserNeverLoadedThroughTheRecordersOwnEntityManagerSucceeds(): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER, 'auth-event-identity-map@example.test');
        $this->em->persist($user);
        $this->em->flush();
        $userId = $user->getId();

        // Would throw `LogicException: Attempting to change readonly
        // property App\Entity\User::$id` if AuthEventRecorder's `User`
        // reference were ever forced to fully re-initialize the way the
        // other two services' bug requires.
        $this->recorder->record(new AuthEventRecord(
            type: AuthEventType::LOGIN_SUCCEEDED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $userId,
            ip: '203.0.113.10',
            userAgent: 'PHPUnit/AuthEventRecorderIdentityMapTest',
        ));

        // AuthEventRecorder wrote through its own Connection/EntityManager,
        // not $this->em -- clear() forces the read below to hit the
        // database rather than return nothing from an identity map that
        // never saw the write.
        $this->em->clear();

        $authEvent = $this->em->getRepository(AuthEvent::class)->findOneBy(['user' => $user]);

        self::assertInstanceOf(AuthEvent::class, $authEvent, 'record() must actually persist a row, not merely avoid throwing.');
        self::assertSame(AuthEventType::LOGIN_SUCCEEDED->value, $authEvent->getType());
        self::assertSame(AuthEventRecord::OUTCOME_SUCCESS, $authEvent->getOutcome());
        self::assertInstanceOf(\App\Entity\User::class, $authEvent->getUser());
        self::assertSame($userId->toRfc4122(), $authEvent->getUser()->getId()->toRfc4122());
    }
}
