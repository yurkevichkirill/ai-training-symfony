<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Enum\ImpersonationEndReason;
use App\Enum\UserRole;
use App\Repository\ImpersonationSessionRepository;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * S6 (AC-9, AC-10, D2, D4b, D4c): the two-way `CHECK` on `ended_at`/
 * `end_reason`, the two timestamp-ordering `CHECK`s, the partial unique
 * index `uniq_impersonation_active_actor`, and `closeIfOpen()`/
 * `findExpiredOpen()`'s own SQL, proven against a real Postgres connection
 * -- the same isolation discipline as `ShareLinkInvitationsConstraintsTest`.
 */
final class ImpersonationSessionConstraintsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ManagerRegistry $managerRegistry;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->managerRegistry = self::getContainer()->get('doctrine');

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheEndPairCheckRefusesEndedAtWithoutEndReason(): void
    {
        [$actor, $subject] = $this->persistActorAndSubject();
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(function () use ($connection, $actor, $subject, $now): void {
                $connection->executeStatement(
                    'INSERT INTO impersonation_session (id, actor_user_id, subject_user_id, started_at, expires_at, ended_at, end_reason) '
                    .'VALUES (:id, :actor, :subject, :startedAt, :expiresAt, :endedAt, NULL)',
                    [
                        'id' => (string) new UuidV7(),
                        'actor' => (string) $actor->getId(),
                        'subject' => (string) $subject->getId(),
                        'startedAt' => $now,
                        'expiresAt' => $now,
                        'endedAt' => $now,
                    ],
                );
            });
            self::fail('Expected impersonation_session_end_pair_ck to refuse ended_at without end_reason.');
        } catch (DriverException $e) {
            self::assertStringContainsString('impersonation_session_end_pair_ck', $e->getMessage());
        }

        self::assertSame(0, $this->countRowsFor($subject));
    }

    public function testTheEndPairCheckRefusesEndReasonWithoutEndedAt(): void
    {
        [$actor, $subject] = $this->persistActorAndSubject();
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(function () use ($connection, $actor, $subject, $now): void {
                $connection->executeStatement(
                    'INSERT INTO impersonation_session (id, actor_user_id, subject_user_id, started_at, expires_at, ended_at, end_reason) '
                    .'VALUES (:id, :actor, :subject, :startedAt, :expiresAt, NULL, :endReason)',
                    [
                        'id' => (string) new UuidV7(),
                        'actor' => (string) $actor->getId(),
                        'subject' => (string) $subject->getId(),
                        'startedAt' => $now,
                        'expiresAt' => $now,
                        'endReason' => ImpersonationEndReason::TIMEOUT->value,
                    ],
                );
            });
            self::fail('Expected impersonation_session_end_pair_ck to refuse end_reason without ended_at.');
        } catch (DriverException $e) {
            self::assertStringContainsString('impersonation_session_end_pair_ck', $e->getMessage());
        }

        self::assertSame(0, $this->countRowsFor($subject));
    }

    public function testTheExpiresAfterStartedCheckRefusesAnExpiresAtNotAfterStartedAt(): void
    {
        [$actor, $subject] = $this->persistActorAndSubject();
        $connection = $this->em->getConnection();
        $startedAt = new \DateTimeImmutable();

        try {
            $connection->transactional(function () use ($connection, $actor, $subject, $startedAt): void {
                $connection->executeStatement(
                    'INSERT INTO impersonation_session (id, actor_user_id, subject_user_id, started_at, expires_at) VALUES (:id, :actor, :subject, :startedAt, :expiresAt)',
                    [
                        'id' => (string) new UuidV7(),
                        'actor' => (string) $actor->getId(),
                        'subject' => (string) $subject->getId(),
                        'startedAt' => $startedAt->format('Y-m-d H:i:sP'),
                        'expiresAt' => $startedAt->format('Y-m-d H:i:sP'),
                    ],
                );
            });
            self::fail('Expected impersonation_session_expires_after_started_ck to refuse expires_at <= started_at.');
        } catch (DriverException $e) {
            self::assertStringContainsString('impersonation_session_expires_after_started_ck', $e->getMessage());
        }

        self::assertSame(0, $this->countRowsFor($subject));
    }

    public function testTheEndedNotBeforeStartedCheckRefusesAnEndedAtBeforeStartedAt(): void
    {
        [$actor, $subject] = $this->persistActorAndSubject();
        $connection = $this->em->getConnection();
        $startedAt = new \DateTimeImmutable();
        $beforeStart = $startedAt->modify('-1 minute');
        $expiresAt = $startedAt->modify('+1 hour');

        try {
            $connection->transactional(function () use ($connection, $actor, $subject, $startedAt, $expiresAt, $beforeStart): void {
                $connection->executeStatement(
                    'INSERT INTO impersonation_session (id, actor_user_id, subject_user_id, started_at, expires_at, ended_at, end_reason) '
                    .'VALUES (:id, :actor, :subject, :startedAt, :expiresAt, :endedAt, :endReason)',
                    [
                        'id' => (string) new UuidV7(),
                        'actor' => (string) $actor->getId(),
                        'subject' => (string) $subject->getId(),
                        'startedAt' => $startedAt->format('Y-m-d H:i:sP'),
                        'expiresAt' => $expiresAt->format('Y-m-d H:i:sP'),
                        'endedAt' => $beforeStart->format('Y-m-d H:i:sP'),
                        'endReason' => ImpersonationEndReason::TIMEOUT->value,
                    ],
                );
            });
            self::fail('Expected impersonation_session_ended_not_before_started_ck to refuse ended_at < started_at.');
        } catch (DriverException $e) {
            self::assertStringContainsString('impersonation_session_ended_not_before_started_ck', $e->getMessage());
        }

        self::assertSame(0, $this->countRowsFor($subject));
    }

    /**
     * The partial unique index refuses a second open row for one actor, but
     * permits any number of *closed* ones for that same actor.
     */
    public function testThePartialUniqueIndexRejectsASecondOpenRowForOneActorButPermitsManyClosedOnes(): void
    {
        [$actor, $subjectA] = $this->persistActorAndSubject();
        $subjectB = new User(UserFactory::email('subject-b'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($subjectB);
        $this->em->flush();

        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval('PT1H'));

        $closedOne = new ImpersonationSession($actor, $subjectA, $now, $expiresAt);
        $this->em->wrapInTransaction(function () use ($closedOne): void {
            $this->em->persist($closedOne);
        });
        $this->em->getConnection()->executeStatement(
            "UPDATE impersonation_session SET ended_at = :endedAt, end_reason = 'EXPLICIT_EXIT' WHERE id = :id",
            ['endedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'), 'id' => (string) $closedOne->getId()],
        );

        $openOne = new ImpersonationSession($actor, $subjectA, $now, $expiresAt);
        $this->em->wrapInTransaction(function () use ($openOne): void {
            $this->em->persist($openOne);
        });

        $colliding = new ImpersonationSession($actor, $subjectB, $now, $expiresAt);

        try {
            $this->em->wrapInTransaction(function () use ($colliding): void {
                $this->em->persist($colliding);
            });
            self::fail('Expected uniq_impersonation_active_actor to reject a second open row for the same actor.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $totalCount = (int) $freshManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE actor_user_id = :actor',
            ['actor' => (string) $actor->getId()],
        );
        self::assertSame(2, $totalCount, 'The closed row and the one surviving open row, no more.');

        $openCount = (int) $freshManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE actor_user_id = :actor AND ended_at IS NULL',
            ['actor' => (string) $actor->getId()],
        );
        self::assertSame(1, $openCount);
    }

    public function testCloseIfOpenReturnsFalseAndWritesNothingForAnAlreadyClosedRow(): void
    {
        [$actor, $subject] = $this->persistActorAndSubject();
        // Zeroed microseconds: the column is `TIMESTAMP(0)`, which *rounds*
        // (not truncates) sub-second precision on write, so comparing an
        // in-memory value with microseconds against a value re-read from
        // the database is a real, if rare, off-by-one-second flake -- not
        // something this test is trying to prove.
        $now = \DateTimeImmutable::createFromFormat('U', (string) time());
        self::assertInstanceOf(\DateTimeImmutable::class, $now);
        $expiresAt = $now->add(new \DateInterval('PT1H'));

        $session = new ImpersonationSession($actor, $subject, $now, $expiresAt);
        $this->em->persist($session);
        $this->em->flush();

        /** @var ImpersonationSessionRepository $repository */
        $repository = $this->em->getRepository(ImpersonationSession::class);

        $firstClose = $repository->closeIfOpen($session, $now->add(new \DateInterval('PT5M')), ImpersonationEndReason::EXPLICIT_EXIT);
        self::assertTrue($firstClose);

        $endedAtAfterFirstClose = $session->getEndedAt();
        self::assertNotNull($endedAtAfterFirstClose);

        $secondClose = $repository->closeIfOpen($session, $now->add(new \DateInterval('PT20M')), ImpersonationEndReason::TIMEOUT);
        self::assertFalse($secondClose, 'closeIfOpen() must return false for an already-closed row.');

        $this->em->clear();
        $reloaded = $this->em->find(ImpersonationSession::class, $session->getId());
        self::assertInstanceOf(ImpersonationSession::class, $reloaded);
        self::assertSame(
            $endedAtAfterFirstClose->getTimestamp(),
            $reloaded->getEndedAt()?->getTimestamp(),
            'The second, no-op close must not have overwritten ended_at.',
        );
        self::assertSame(ImpersonationEndReason::EXPLICIT_EXIT, $reloaded->getEndReason(), 'The second, no-op close must not have overwritten end_reason.');
    }

    public function testFindExpiredOpenIgnoresClosedAndUnexpiredRows(): void
    {
        // Each row uses its own actor: `uniq_impersonation_active_actor`
        // permits at most one *open* row per actor, and two of these three
        // rows are open at once.
        [$actorForExpiredOpen, $subjectExpired] = $this->persistActorAndSubject();
        $actorForClosed = new User(UserFactory::email('actor-closed'), UserFactory::passwordHash(), UserRole::SUPER_ADMIN);
        $actorForUnexpired = new User(UserFactory::email('actor-unexpired'), UserFactory::passwordHash(), UserRole::SUPER_ADMIN);
        $subjectClosed = new User(UserFactory::email('subject-closed'), UserFactory::passwordHash(), UserRole::TRAINER);
        $subjectUnexpired = new User(UserFactory::email('subject-unexpired'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($actorForClosed);
        $this->em->persist($actorForUnexpired);
        $this->em->persist($subjectClosed);
        $this->em->persist($subjectUnexpired);
        $this->em->flush();

        $now = new \DateTimeImmutable();

        $expiredOpen = new ImpersonationSession($actorForExpiredOpen, $subjectExpired, $now->modify('-2 hours'), $now->modify('-1 hour'));
        $this->em->persist($expiredOpen);

        $expiredButClosed = new ImpersonationSession($actorForClosed, $subjectClosed, $now->modify('-2 hours'), $now->modify('-1 hour'));
        $expiredButClosed->markEnded($now->modify('-90 minutes'), ImpersonationEndReason::EXPLICIT_EXIT);
        $this->em->persist($expiredButClosed);

        $unexpired = new ImpersonationSession($actorForUnexpired, $subjectUnexpired, $now, $now->add(new \DateInterval('PT1H')));
        $this->em->persist($unexpired);

        $this->em->flush();

        /** @var ImpersonationSessionRepository $repository */
        $repository = $this->em->getRepository(ImpersonationSession::class);

        $results = $repository->findExpiredOpen($now, 100);
        $resultIds = array_map(static fn (ImpersonationSession $s): string => (string) $s->getId(), $results);

        self::assertContains((string) $expiredOpen->getId(), $resultIds);
        self::assertNotContains((string) $expiredButClosed->getId(), $resultIds, 'A closed row must never be returned, even if expired.');
        self::assertNotContains((string) $unexpired->getId(), $resultIds, 'An unexpired row must never be returned.');
    }

    /**
     * @return array{User, User}
     */
    private function persistActorAndSubject(): array
    {
        $actor = new User(UserFactory::email('actor'), UserFactory::passwordHash(), UserRole::SUPER_ADMIN);
        $subject = new User(UserFactory::email('subject'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($actor);
        $this->em->persist($subject);
        $this->em->flush();

        return [$actor, $subject];
    }

    private function countRowsFor(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $subject->getId()],
        );
    }
}
