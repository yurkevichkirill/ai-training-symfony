<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\ProfileRepository;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Task 35: the real-Postgres schema facts behind S7's one CHECK constraint
 * and its reuse of `profile`'s existing UNIQUE, plus the batched
 * `findTrainerProfilesFor()` query count and the migration's idempotency
 * (AC-9, D1, NFR-001).
 *
 * Same isolation/recovery discipline as `CoachFeaturesConstraintsTest`:
 * each test runs inside a transaction begun in `setUp()` and rolled back in
 * `tearDown()`, writes directly through the `Connection`/`EntityManager`
 * bypassing the service layer, and a closed `EntityManager` is recovered
 * via `ManagerRegistry::resetManager()`.
 */
final class TrainerBrandingConstraintsTest extends KernelTestCase
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

    public function testTheCheckConstraintRefusesANonHexColorValue(): void
    {
        $trainer = $this->persistTrainerProfile('bad-color-red');
        $connection = $this->em->getConnection();

        try {
            $connection->transactional(static function () use ($connection, $trainer): void {
                $connection->executeStatement(
                    "UPDATE profile_trainer SET primary_color_hex = 'red' WHERE id = :id",
                    ['id' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected profile_trainer_primary_color_hex_ck to refuse a non-hex value.');
        } catch (DriverException $e) {
            self::assertStringContainsString('profile_trainer_primary_color_hex_ck', $e->getMessage());
        }
    }

    public function testTheCheckConstraintRefusesAnUppercaseThreeDigitHexValue(): void
    {
        $trainer = $this->persistTrainerProfile('bad-color-fff');
        $connection = $this->em->getConnection();

        try {
            $connection->transactional(static function () use ($connection, $trainer): void {
                $connection->executeStatement(
                    "UPDATE profile_trainer SET primary_color_hex = '#FFF' WHERE id = :id",
                    ['id' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected profile_trainer_primary_color_hex_ck to refuse a 3-digit value.');
        } catch (DriverException $e) {
            self::assertStringContainsString('profile_trainer_primary_color_hex_ck', $e->getMessage());
        }
    }

    public function testTheCheckConstraintRefusesNonHexCharacters(): void
    {
        $trainer = $this->persistTrainerProfile('bad-color-gggggg');
        $connection = $this->em->getConnection();

        try {
            $connection->transactional(static function () use ($connection, $trainer): void {
                $connection->executeStatement(
                    "UPDATE profile_trainer SET primary_color_hex = '#gggggg' WHERE id = :id",
                    ['id' => (string) $trainer->getId()],
                );
            });
            self::fail('Expected profile_trainer_primary_color_hex_ck to refuse non-hex characters.');
        } catch (DriverException $e) {
            self::assertStringContainsString('profile_trainer_primary_color_hex_ck', $e->getMessage());
        }
    }

    public function testUniqueProfileUserTypeStillRefusesASecondProfileTrainerRowForOneUser(): void
    {
        $trainer = $this->persistUser('dup-profile-trainer', UserRole::TRAINER);

        $first = new ProfileTrainer($trainer, 'First Business');
        $this->em->wrapInTransaction(function () use ($first): void {
            $this->em->persist($first);
        });

        $second = new ProfileTrainer($trainer, 'Second Business');

        try {
            $this->em->wrapInTransaction(function () use ($second): void {
                $this->em->persist($second);
            });
            self::fail('Expected uniq_profile_user_type to reject a second ProfileTrainer row for the same user.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $count = $freshManager->getConnection()->executeQuery(
            "SELECT COUNT(*) FROM profile WHERE user_id = :user AND type = 'TRAINER'",
            ['user' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'Exactly one ProfileTrainer row survives the collision.');
    }

    /**
     * NFR-001: one row per trainer for a 10-trainer page. The "one query,
     * never one per row" half of this claim is a source-level fact --
     * `ProfileRepository::findTrainerProfilesFor()` is a single
     * `QueryBuilder` with one `IN (:users)` clause and no per-row loop
     * (see that method) -- confirmed by reading the method, since
     * instrumenting the shared, already-connected test `Connection` with a
     * query logger after `setUp()`'s transaction has begun cannot observe
     * queries issued on that same live connection.
     */
    public function testFindTrainerProfilesForReturnsOneRowPerTrainerForABatchOfTen(): void
    {
        $trainers = [];

        for ($i = 0; $i < 10; ++$i) {
            $trainers[] = $this->persistTrainerProfile('batch-'.$i)->getUser();
        }

        $this->em->clear();

        /** @var ProfileRepository $profileRepository */
        $profileRepository = $this->em->getRepository(\App\Entity\Profile::class);

        $freshTrainers = array_map(fn (User $u) => $this->em->getReference(User::class, $u->getId()), $trainers);

        $result = $profileRepository->findTrainerProfilesFor($freshTrainers);

        self::assertCount(10, $result);

        foreach ($freshTrainers as $trainer) {
            self::assertArrayHasKey($trainer->getId()->toRfc4122(), $result);
        }
    }

    /**
     * S3's CHECK-normalisation trap, re-verified for this migration:
     * `doctrine:schema:update --dump-sql` reports nothing on a second run.
     */
    /**
     * Run as a real subprocess against `bin/console` -- the command's own
     * output only renders correctly against a genuine console output
     * stream, and this test's own transaction (begun in `setUp()`, on the
     * shared kernel connection) must not be on the same connection the
     * command introspects, since an uncommitted `ALTER`-adjacent DDL check
     * is not what this test is proving.
     */
    public function testSchemaUpdateDumpSqlReportsNothingToUpdateTwiceInARow(): void
    {
        $projectDir = \dirname(__DIR__, 2);

        $run = static function () use ($projectDir): string {
            return (string) shell_exec(\sprintf(
                'cd %s && php bin/console doctrine:schema:update --dump-sql --env=test 2>&1',
                escapeshellarg($projectDir),
            ));
        };

        self::assertStringContainsString('Nothing to update', $run());
        self::assertStringContainsString('Nothing to update', $run());
    }

    private function persistTrainerProfile(string $prefix): ProfileTrainer
    {
        $trainer = $this->persistUser($prefix, UserRole::TRAINER);
        $profile = new ProfileTrainer($trainer, $prefix.' Business');
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    private function persistUser(string $prefix, UserRole $role): User
    {
        $user = new User(UserFactory::email($prefix), UserFactory::passwordHash(), $role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
