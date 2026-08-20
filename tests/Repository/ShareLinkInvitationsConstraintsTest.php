<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PlayerShareLink;
use App\Entity\TrainerCoachAssociation;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\PlayerShareLinkRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Repository-level, real-Postgres proofs of Epic-01 S3's schema-level facts
 * (Task 28): the three `UNIQUE`/partial-unique-index collisions that make
 * AC-1, AC-4, AC-13, and AC-16 database facts rather than application-level
 * checks, the `usageCount` accumulation AC-6 depends on, and the two
 * hand-written `CHECK` constraints AC-7 names.
 *
 * Same isolation discipline as `UserAccountServiceConcurrentCreationTest`/
 * `UserRepositoryTest`: each test runs inside a transaction begun in
 * `setUp()` and rolled back in `tearDown()`, so cases stay independent
 * without a fixture-reload step.
 *
 * **Reproducing the three UNIQUE-constraint races without two live
 * connections.** `UserAccountServiceConcurrentCreationTest`'s technique --
 * issue both conflicting writes sequentially against the same open
 * connection/transaction -- works unchanged there because
 * `UserAccountService::create()` never pre-reads for an existing row before
 * inserting. `PlayerShareLinkService::associate()`/`getOrCreateFor()` are
 * different: each opens with a pre-check read (`findOneFor()`/
 * `findOneByTrainer()`) that is deliberately *not* the authority (see those
 * methods' own docblocks) -- calling the service method itself twice in a
 * row would let the second call's pre-check see the first call's
 * already-committed row and return early through the fast path, never
 * reaching the `UNIQUE` constraint at all. To reproduce the actual
 * database-level race -- two writers who both read "no row exists" before
 * either commits, exactly the scenario the pre-check's own docblock warns
 * about -- these tests persist the two conflicting entities directly via
 * the `EntityManager`, bypassing the service layer's pre-check entirely,
 * each write wrapped in its own `wrapInTransaction()` call exactly as the
 * services do. DBAL 4 always nests transactions with savepoints, so the
 * second (colliding) `wrapInTransaction()` call rolling back only unwinds
 * to its own savepoint, never this test's outer transaction.
 *
 * **The closed-EntityManager recovery.** Every collision below closes
 * `$this->em` (the same documented pitfall `UserAccountService`/
 * `PlayerShareLinkService` describe at length): the recovery is always
 * `$this->managerRegistry->resetManager()` followed by re-reading directly
 * against the fresh manager -- never by touching `$this->em` again, and
 * never through a repository fetched from the container (which permanently
 * caches whichever `EntityRepository` it first resolved, bound to the now-
 * closed manager).
 */
final class ShareLinkInvitationsConstraintsTest extends KernelTestCase
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

    /**
     * AC-13: `UNIQUE (trainer_id, player_id)` is the database fact that
     * makes "no duplicate association is created" true under a genuine
     * race, not just the pre-check. The second, colliding insert must raise
     * `UniqueConstraintViolationException` -- never corrupt data, never an
     * uncaught 500 -- and the row a "loser" would re-read afterward (exactly
     * `PlayerShareLinkService::associate()`'s own recovery path) must be the
     * one and only surviving row.
     */
    public function testConcurrentInsertsOnTheSameTrainerPlayerPairResolveToOneRowAc13(): void
    {
        $trainer = new User(UserFactory::email('trainer'), UserFactory::passwordHash(), UserRole::TRAINER);
        $player = new User(UserFactory::email('player'), UserFactory::passwordHash(), UserRole::PLAYER);
        $this->em->persist($trainer);
        $this->em->persist($player);
        $this->em->flush();

        $winner = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->wrapInTransaction(function () use ($winner): void {
            $this->em->persist($winner);
        });

        $loser = new TrainerPlayerAssociation($trainer, $player, null);

        try {
            $this->em->wrapInTransaction(function () use ($loser): void {
                $this->em->persist($loser);
            });
            self::fail('Expected UNIQUE (trainer_id, player_id) to reject the second, colliding insert.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $reloadedTrainer = $freshManager->find(User::class, $trainer->getId());
        $reloadedPlayer = $freshManager->find(User::class, $player->getId());
        self::assertInstanceOf(User::class, $reloadedTrainer);
        self::assertInstanceOf(User::class, $reloadedPlayer);

        /** @var TrainerPlayerAssociationRepository $repository */
        $repository = $freshManager->getRepository(TrainerPlayerAssociation::class);
        $survivor = $repository->findOneFor($reloadedTrainer, $reloadedPlayer);

        self::assertInstanceOf(TrainerPlayerAssociation::class, $survivor);
        self::assertSame(
            $winner->getId()->toRfc4122(),
            $survivor->getId()->toRfc4122(),
            'The loser\'s post-collision re-read must land on the first, successfully-committed row -- its idempotent "success".',
        );

        $count = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-13: exactly one row survives the collision.');
    }

    /**
     * AC-16: the partial unique index `uniq_trainer_coach_active_coach
     * (coach_id) WHERE ended_at IS NULL` rejects a second *active*
     * association for a coach already actively associated elsewhere -- but,
     * because an ended row is invisible to it, permits a new one the moment
     * the first is ended. No S3 code path writes `endedAt` yet (the
     * entity's own class docblock), so this test sets it by hand, at the SQL
     * level, purely to exercise the index's predicate.
     */
    public function testThePartialUniqueIndexRejectsASecondActiveAssociationButPermitsOneOnceTheFirstIsEndedAc16(): void
    {
        $trainerA = new User(UserFactory::email('trainer-a'), UserFactory::passwordHash(), UserRole::TRAINER);
        $trainerB = new User(UserFactory::email('trainer-b'), UserFactory::passwordHash(), UserRole::TRAINER);
        $coach = new User(UserFactory::email('coach'), UserFactory::passwordHash(), UserRole::COACH);
        $this->em->persist($trainerA);
        $this->em->persist($trainerB);
        $this->em->persist($coach);
        $this->em->flush();

        $activeWithA = new TrainerCoachAssociation($trainerA, $coach, null);
        $this->em->wrapInTransaction(function () use ($activeWithA): void {
            $this->em->persist($activeWithA);
        });

        $activeWithB = new TrainerCoachAssociation($trainerB, $coach, null);

        try {
            $this->em->wrapInTransaction(function () use ($activeWithB): void {
                $this->em->persist($activeWithB);
            });
            self::fail('Expected the partial unique index to reject a second active association for the same coach.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $reloadedCoach = $freshManager->find(User::class, $coach->getId());
        self::assertInstanceOf(User::class, $reloadedCoach);

        /** @var TrainerCoachAssociationRepository $repository */
        $repository = $freshManager->getRepository(TrainerCoachAssociation::class);
        $survivor = $repository->findActiveForCoach($reloadedCoach);

        self::assertInstanceOf(TrainerCoachAssociation::class, $survivor);
        self::assertSame($activeWithA->getId()->toRfc4122(), $survivor->getId()->toRfc4122());

        $activeCount = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_coach_association WHERE coach_id = :coach AND ended_at IS NULL',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $activeCount, 'Only one active association can exist for a coach at a time.');

        // End the first association directly at the SQL level -- there is
        // no entity setter for endedAt, deliberately (see the class
        // docblock).
        $freshManager->getConnection()->executeStatement(
            'UPDATE trainer_coach_association SET ended_at = :endedAt WHERE id = :id',
            ['endedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'), 'id' => $activeWithA->getId()->toRfc4122()],
        );

        $reloadedTrainerB = $freshManager->find(User::class, $trainerB->getId());
        self::assertInstanceOf(User::class, $reloadedTrainerB);

        $newActiveWithB = new TrainerCoachAssociation($reloadedTrainerB, $reloadedCoach, null);
        $freshManager->wrapInTransaction(function () use ($freshManager, $newActiveWithB): void {
            $freshManager->persist($newActiveWithB);
        });

        $survivorAfterEnding = $repository->findActiveForCoach($reloadedCoach);
        self::assertInstanceOf(TrainerCoachAssociation::class, $survivorAfterEnding);
        self::assertSame(
            $newActiveWithB->getId()->toRfc4122(),
            $survivorAfterEnding->getId()->toRfc4122(),
            'AC-16: once the first association is ended, a new active one for a different trainer succeeds -- the ended row is invisible to the partial index.',
        );

        $totalCount = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_coach_association WHERE coach_id = :coach',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $totalCount, 'Two rows total: the ended one and the new active one -- neither removed nor duplicated.');
    }

    /**
     * AC-1, AC-4: `UNIQUE (trainer_id)` on `player_share_link` is what makes
     * "one link per trainer" true regardless of how many times generation
     * is (concurrently) attempted -- the same collision
     * `PlayerShareLinkService::getOrCreateFor()` recovers from by resetting
     * the manager and re-reading the winner, reproduced here directly
     * against the entity/repository layer.
     */
    public function testConcurrentDoubleGenerateForOneTrainerResolvesToOnePlayerShareLinkRowAc1Ac4(): void
    {
        $trainer = new User(UserFactory::email('trainer'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($trainer);
        $this->em->flush();

        $generator = new ShareLinkCodeGenerator();

        $winner = new PlayerShareLink($trainer, $generator->generate());
        $this->em->wrapInTransaction(function () use ($winner): void {
            $this->em->persist($winner);
        });

        $loser = new PlayerShareLink($trainer, $generator->generate());

        try {
            $this->em->wrapInTransaction(function () use ($loser): void {
                $this->em->persist($loser);
            });
            self::fail('Expected UNIQUE (trainer_id) to reject the second generated link for the same trainer.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $reloadedTrainer = $freshManager->find(User::class, $trainer->getId());
        self::assertInstanceOf(User::class, $reloadedTrainer);

        /** @var PlayerShareLinkRepository $repository */
        $repository = $freshManager->getRepository(PlayerShareLink::class);
        $survivor = $repository->findOneByTrainer($reloadedTrainer);

        self::assertInstanceOf(PlayerShareLink::class, $survivor);
        self::assertSame($winner->getId()->toRfc4122(), $survivor->getId()->toRfc4122());

        $count = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM player_share_link WHERE trainer_id = :trainer',
            ['trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-1, AC-4: exactly one link survives per trainer, regardless of how many generation attempts race.');
    }

    /**
     * AC-6: "the number of times a given player ShareLink has been used" --
     * two independent registrations against the same link must both be
     * tallied, never one overwriting the other. `PlayerShareLink::
     * incrementUsage()` mutates a plain in-memory counter that Doctrine
     * persists as a fully-computed `UPDATE ... SET usage_count = :value`
     * (never a relational `usage_count = usage_count + 1`), so the only way
     * this could genuinely lose a count is if a second registration's write
     * were based on a stale in-memory read that predates the first
     * registration's commit. This test proves exactly that does not happen:
     * two independently-loaded reads (`em->clear()` between them, standing
     * in for two separate requests, each getting its own fresh
     * `PlayerShareLink` instance) each see the other's already-committed
     * increment and accumulate correctly.
     *
     * Deliberately scoped to what the established one-connection technique
     * can prove: a true two-live-connection lost-update race (two snapshots
     * that each read `usage_count = 0` before either commits) is out of
     * scope here, same as every other test in this file avoids needing a
     * second connection.
     */
    public function testIncrementUsageAcrossTwoIndependentlyLoadedReadsAccumulatesWithoutLosingACountAc6(): void
    {
        $trainer = new User(UserFactory::email('trainer'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($trainer);
        $this->em->flush();

        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();
        $linkId = $link->getId();

        $this->em->clear();
        $firstRead = $this->em->getRepository(PlayerShareLink::class)->find($linkId);
        self::assertInstanceOf(PlayerShareLink::class, $firstRead);
        self::assertSame(0, $firstRead->getUsageCount());
        $firstRead->incrementUsage();
        $this->em->flush();

        $this->em->clear();
        $secondRead = $this->em->getRepository(PlayerShareLink::class)->find($linkId);
        self::assertInstanceOf(PlayerShareLink::class, $secondRead);
        self::assertSame(1, $secondRead->getUsageCount(), 'The second, independent read must see the first registration\'s already-committed increment.');
        $secondRead->incrementUsage();
        $this->em->flush();

        $this->em->clear();
        $final = $this->em->getRepository(PlayerShareLink::class)->find($linkId);
        self::assertInstanceOf(PlayerShareLink::class, $final);
        self::assertSame(2, $final->getUsageCount(), 'AC-6: two independent registrations against the same link must both be tallied -- neither increment lost.');
    }

    /**
     * AC-3, AC-7: the migration's hand-written
     * `coach_invitation_email_lower_ck CHECK (invited_email =
     * lower(invited_email))` must genuinely refuse an unnormalized value at
     * the database level, not merely rely on `User::normalizeEmail()`'s
     * normalization point never being bypassed.
     *
     * DBAL 4's PostgreSQL `ExceptionConverter` (read directly, not assumed)
     * has no dedicated mapping for SQLSTATE 23514 (check_violation) -- unlike
     * 23505 (unique_violation), which becomes `UniqueConstraintViolationException`
     * -- so a CHECK violation falls through to the generic
     * `Doctrine\DBAL\Exception\DriverException`. That is this project's
     * "or equivalent" for a typed check-constraint exception; there is no
     * `CheckConstraintViolationException` class in this DBAL version.
     *
     * The raw insert runs through `Connection::transactional()`, which (DBAL
     * 4 always nests transactions with savepoints) rolls back only to its
     * own savepoint on failure, leaving this test's outer transaction, and
     * its own connection, fully usable afterward -- unlike the ORM-level
     * collisions above, no EntityManager is ever touched by this raw insert,
     * so there is nothing to reset.
     */
    public function testTheInvitedEmailCheckConstraintRefusesAnUnnormalizedValueAc3Ac7(): void
    {
        $trainer = new User(UserFactory::email('trainer'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($trainer);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $trainerId = (string) $trainer->getId();

        try {
            $connection->transactional(static function () use ($connection, $now, $trainerId): void {
                $connection->executeStatement(
                    'INSERT INTO coach_invitation '
                    .'(id, invited_email, invited_name, message, selector, hashed_verifier, expires_at, accepted_at, created_at, trainer_id) '
                    .'VALUES (:id, :email, NULL, NULL, :selector, :hashedVerifier, :expiresAt, NULL, :createdAt, :trainer)',
                    [
                        'id' => (string) new UuidV7(),
                        'email' => 'NOT-LOWERCASE@Example.Test',
                        'selector' => bin2hex(random_bytes(12)),
                        'hashedVerifier' => str_repeat('a', 64),
                        'expiresAt' => $now,
                        'createdAt' => $now,
                        'trainer' => $trainerId,
                    ],
                );
            });
            self::fail('Expected the database CHECK constraint to refuse an unnormalized invited_email.');
        } catch (DriverException $e) {
            self::assertStringContainsString('coach_invitation_email_lower_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM coach_invitation WHERE trainer_id = :trainer',
            ['trainer' => $trainerId],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'The refused insert must not have left a row behind.');
    }

    /**
     * AC-7: the migration's hand-written `profile_player_gender_ck CHECK
     * (gender IN (...))` mirrors `app_user.role`'s closed-domain enforcement
     * at the storage level -- an out-of-domain value must be unstorable even
     * if some future caller bypasses `PlayerGender` entirely. Same
     * DriverException / savepoint reasoning as the email CHECK test above.
     */
    public function testTheGenderCheckConstraintRefusesAnOutOfDomainValueAc7(): void
    {
        $user = new User(UserFactory::email('player'), UserFactory::passwordHash(), UserRole::PLAYER);
        $this->em->persist($user);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $profileId = (string) new UuidV7();

        $connection->executeStatement(
            'INSERT INTO profile (id, created_at, updated_at, user_id, type) VALUES (:id, :createdAt, :updatedAt, :user, :type)',
            ['id' => $profileId, 'createdAt' => $now, 'updatedAt' => $now, 'user' => (string) $user->getId(), 'type' => 'PLAYER'],
        );

        try {
            $connection->transactional(static function () use ($connection, $profileId): void {
                $connection->executeStatement(
                    "INSERT INTO profile_player (id, player_name, declared_age, gender) VALUES (:id, 'Test Player', 12, :gender)",
                    ['id' => $profileId, 'gender' => 'NON_BINARY'],
                );
            });
            self::fail('Expected the database CHECK constraint to refuse an out-of-domain gender value.');
        } catch (DriverException $e) {
            self::assertStringContainsString('profile_player_gender_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM profile_player WHERE id = :id',
            ['id' => $profileId],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'The refused insert must not have left a row behind.');
    }
}
