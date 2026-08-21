<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProfileCoach;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Task 34: the real-Postgres schema facts behind this slice's CHECK
 * constraints and its one UNIQUE reuse -- `btrim(reason) <> ''`,
 * `day_of_week`, and `starts_at_minute < ends_at_minute` on
 * `coach_assignment_override`; the equivalent pair on
 * `coach_availability_slot`; `UNIQUE (user_id, type)` on `profile` refusing
 * a second `ProfileCoach` row for one user; and
 * `doctrine:schema:update --dump-sql` reporting nothing on a second run.
 *
 * Same isolation/recovery discipline as
 * `PlayerFamilyAvailabilityConstraintsTest`: each test runs inside a
 * transaction begun in `setUp()` and rolled back in `tearDown()`, writes
 * directly through the `Connection`/`EntityManager` bypassing the service
 * layer, and a closed `EntityManager` is recovered via
 * `ManagerRegistry::resetManager()`.
 */
final class CoachFeaturesConstraintsTest extends KernelTestCase
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

    public function testTheCoachAvailabilitySlotCheckConstraintsRefuseBadValues(): void
    {
        $coach = $this->persistUser('bad-slot-coach', UserRole::COACH);
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(static function () use ($connection, $coach, $now): void {
                $connection->executeStatement(
                    'INSERT INTO coach_availability_slot (id, day_of_week, starts_at_minute, ends_at_minute, created_at, coach_id) VALUES (:id, 0, 60, 120, :now, :coach)',
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId()],
                );
            });
            self::fail('Expected coach_availability_slot_day_ck to refuse day_of_week = 0.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_availability_slot_day_ck', $e->getMessage());
        }

        try {
            $connection->transactional(static function () use ($connection, $coach, $now): void {
                $connection->executeStatement(
                    'INSERT INTO coach_availability_slot (id, day_of_week, starts_at_minute, ends_at_minute, created_at, coach_id) VALUES (:id, 1, 120, 60, :now, :coach)',
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId()],
                );
            });
            self::fail('Expected coach_availability_slot_range_ck to refuse starts_at_minute >= ends_at_minute.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_availability_slot_range_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM coach_availability_slot WHERE coach_id = :coach',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'Neither refused insert may have left a row behind.');
    }

    public function testTheCoachAssignmentOverrideDayAndRangeCheckConstraintsRefuseBadValues(): void
    {
        [$coach, $trainer] = $this->makePair('bad-override-values');
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(static function () use ($connection, $coach, $trainer, $now): void {
                $connection->executeStatement(
                    "INSERT INTO coach_assignment_override (id, day_of_week, starts_at_minute, ends_at_minute, coverage, reason, created_at, coach_id, overridden_by_user_id) VALUES (:id, 8, 60, 120, 'UNAVAILABLE', 'a reason', :now, :coach, :trainer)",
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId(), 'trainer' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected coach_assignment_override_day_ck to refuse day_of_week = 8.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_assignment_override_day_ck', $e->getMessage());
        }

        try {
            $connection->transactional(static function () use ($connection, $coach, $trainer, $now): void {
                $connection->executeStatement(
                    "INSERT INTO coach_assignment_override (id, day_of_week, starts_at_minute, ends_at_minute, coverage, reason, created_at, coach_id, overridden_by_user_id) VALUES (:id, 1, 120, 60, 'UNAVAILABLE', 'a reason', :now, :coach, :trainer)",
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId(), 'trainer' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected coach_assignment_override_range_ck to refuse starts_at_minute >= ends_at_minute.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_assignment_override_range_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM coach_assignment_override WHERE coach_id = :coach',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'Neither refused insert may have left a row behind.');
    }

    /**
     * D3d: `CHECK (btrim(reason) <> '')` refuses an empty and a
     * whitespace-only reason at the database level, the second layer behind
     * `CoachAssignmentOverrideService::record()`'s own service-level guard.
     */
    public function testTheReasonCheckConstraintRefusesEmptyAndWhitespaceOnlyReasonsAc7(): void
    {
        [$coach, $trainer] = $this->makePair('bad-reason');
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(static function () use ($connection, $coach, $trainer, $now): void {
                $connection->executeStatement(
                    "INSERT INTO coach_assignment_override (id, day_of_week, starts_at_minute, ends_at_minute, coverage, reason, created_at, coach_id, overridden_by_user_id) VALUES (:id, 1, 60, 120, 'UNAVAILABLE', '', :now, :coach, :trainer)",
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId(), 'trainer' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected coach_assignment_override_reason_ck to refuse an empty reason.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_assignment_override_reason_ck', $e->getMessage());
        }

        try {
            $connection->transactional(static function () use ($connection, $coach, $trainer, $now): void {
                $connection->executeStatement(
                    "INSERT INTO coach_assignment_override (id, day_of_week, starts_at_minute, ends_at_minute, coverage, reason, created_at, coach_id, overridden_by_user_id) VALUES (:id, 1, 60, 120, 'UNAVAILABLE', '   ', :now, :coach, :trainer)",
                    ['id' => (string) new UuidV7(), 'now' => $now, 'coach' => (string) $coach->getId(), 'trainer' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected coach_assignment_override_reason_ck to refuse a whitespace-only reason.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_assignment_override_reason_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM coach_assignment_override WHERE coach_id = :coach',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'Neither refused insert may have left a row behind.');
    }

    /**
     * `UNIQUE (user_id, type)` on `profile`: a second `ProfileCoach` row for
     * the same user is refused, even written directly through the ORM,
     * bypassing `ProfileService::updateCoachDetails()`'s own lazy-creation
     * logic entirely.
     */
    public function testUniqueProfileUserTypeRefusesASecondProfileCoachRowForOneUser(): void
    {
        $coach = $this->persistUser('dup-profile-coach', UserRole::COACH);

        $first = new ProfileCoach($coach);
        $this->em->wrapInTransaction(function () use ($first): void {
            $this->em->persist($first);
        });

        $second = new ProfileCoach($coach);

        try {
            $this->em->wrapInTransaction(function () use ($second): void {
                $this->em->persist($second);
            });
            self::fail('Expected uniq_profile_user_type to reject a second ProfileCoach row for the same user.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $count = $freshManager->getConnection()->executeQuery(
            "SELECT COUNT(*) FROM profile WHERE user_id = :user AND type = 'COACH'",
            ['user' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'Exactly one ProfileCoach row survives the collision.');
    }

    /**
     * @return array{0: User, 1: User} coach, trainer
     */
    private function makePair(string $prefix): array
    {
        $coach = $this->persistUser($prefix.'-coach', UserRole::COACH);
        $trainer = $this->persistUser($prefix.'-trainer', UserRole::TRAINER);

        return [$coach, $trainer];
    }

    private function persistUser(string $prefix, UserRole $role): User
    {
        $user = new User(UserFactory::email($prefix), UserFactory::passwordHash(), $role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
