<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ChildAccount;
use App\Entity\ChildTrainerRequest;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\ChildTrainerRequestResolution;
use App\Enum\UserRole;
use App\Repository\ChildTrainerRequestRepository;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Task 44 (S4): the real-Postgres schema facts behind this slice's own
 * uniqueness and validity rules -- the partial unique index that makes a
 * blocked ShareLink click idempotent-until-resolved (AC-15, AC-16), the
 * `UNIQUE (child_user_id)` that makes "one parent per child" a database
 * fact (AC-2, AC-6), the four hand-written `CHECK` constraints, and the
 * affected-row-count proofs behind `connect()`'s and `disconnect()`'s
 * concurrency guarantees (AC-8, AC-9, AC-10).
 *
 * Same isolation and closed-EntityManager-recovery discipline as
 * `ShareLinkInvitationsConstraintsTest` -- see that file's class docblock
 * for the full rationale this file does not repeat: each test runs inside
 * a transaction begun in `setUp()` and rolled back in `tearDown()`; every
 * `UNIQUE`/`CHECK` collision below is reproduced by writing directly
 * through the `EntityManager`/`Connection`, bypassing the service layer's
 * own pre-checks, each write wrapped in `wrapInTransaction()` so DBAL 4's
 * savepoint nesting keeps this test's outer transaction alive across a
 * caught collision; a closed `EntityManager` is recovered via
 * `ManagerRegistry::resetManager()`, never touched directly again.
 *
 * `doctrine:schema:update --dump-sql` reporting nothing on a second run
 * (this same task's final bullet) is confirmed directly against the
 * database, outside PHPUnit, as part of Task 47's full verification pass --
 * not a PHPUnit assertion, the same "operational check documented rather
 * than asserted" precedent Task 6's own docblock sets for this identical
 * partial-index stability question.
 */
final class PlayerFamilyAvailabilityConstraintsTest extends KernelTestCase
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
     * AC-15, AC-16: the partial unique index
     * `uniq_child_trainer_request_pending (child_user_id, trainer_id) WHERE
     * resolved_at IS NULL` admits a second row for the same pairing only
     * once the first is resolved -- a second concurrently-inserted pending
     * row for the same pairing is rejected outright.
     */
    public function testThePartialUniqueIndexAdmitsASecondPendingRequestOnlyAfterTheFirstResolvesAc15Ac16(): void
    {
        [$parent, $child, $trainer] = $this->makeFamily('pending-idx');

        $first = new ChildTrainerRequest($child, $trainer, $parent, null);
        $this->em->wrapInTransaction(function () use ($first): void {
            $this->em->persist($first);
        });

        $secondWhileFirstStillPending = new ChildTrainerRequest($child, $trainer, $parent, null);

        try {
            $this->em->wrapInTransaction(function () use ($secondWhileFirstStillPending): void {
                $this->em->persist($secondWhileFirstStillPending);
            });
            self::fail('Expected the partial unique index to reject a second pending request for the same pairing.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $pendingCount = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :child AND trainer_id = :trainer AND resolved_at IS NULL',
            ['child' => (string) $child->getId(), 'trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $pendingCount, 'Only one pending row may exist for the same pairing.');

        // Resolve the first, then a new pending row for the exact same
        // pairing is now permitted -- the ended row is invisible to the
        // partial index.
        $freshManager->getConnection()->executeStatement(
            'UPDATE child_trainer_request SET resolved_at = :now, resolution = :resolution WHERE id = :id',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
                'resolution' => ChildTrainerRequestResolution::DISMISSED->value,
                'id' => (string) $first->getId(),
            ],
        );

        $reloadedChild = $freshManager->find(User::class, $child->getId());
        $reloadedTrainer = $freshManager->find(User::class, $trainer->getId());
        $reloadedParent = $freshManager->find(User::class, $parent->getId());
        self::assertInstanceOf(User::class, $reloadedChild);
        self::assertInstanceOf(User::class, $reloadedTrainer);
        self::assertInstanceOf(User::class, $reloadedParent);

        $newPending = new ChildTrainerRequest($reloadedChild, $reloadedTrainer, $reloadedParent, null);
        $freshManager->wrapInTransaction(function () use ($freshManager, $newPending): void {
            $freshManager->persist($newPending);
        });

        /** @var ChildTrainerRequestRepository $repository */
        $repository = $freshManager->getRepository(ChildTrainerRequest::class);
        $survivor = $repository->findPendingFor($reloadedChild, $reloadedTrainer);
        self::assertInstanceOf(ChildTrainerRequest::class, $survivor);
        self::assertSame($newPending->getId()->toRfc4122(), $survivor->getId()->toRfc4122());

        $totalCount = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :child AND trainer_id = :trainer',
            ['child' => (string) $child->getId(), 'trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $totalCount, 'The resolved row and the new pending row both exist -- neither removed nor duplicated.');
    }

    /**
     * AC-2, AC-6: `UNIQUE (child_user_id)` refuses a second parent claiming
     * the same child.
     */
    public function testTheUniqueChildUserIndexRefusesASecondParentForTheSameChildAc2Ac6(): void
    {
        $childUser = $this->persistUser('child', UserRole::PLAYER);
        $firstParent = $this->persistUser('first-parent', UserRole::PLAYER);
        $secondParent = $this->persistUser('second-parent', UserRole::PLAYER);

        $firstLink = new ChildAccount($childUser, $firstParent);
        $this->em->wrapInTransaction(function () use ($firstLink): void {
            $this->em->persist($firstLink);
        });

        $secondLink = new ChildAccount($childUser, $secondParent);

        try {
            $this->em->wrapInTransaction(function () use ($secondLink): void {
                $this->em->persist($secondLink);
            });
            self::fail('Expected UNIQUE (child_user_id) to reject a second parent for the same child.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $count = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_account WHERE child_user_id = :child',
            ['child' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-2/AC-6: exactly one parent per child, regardless of how many claims race.');

        $survivorParentId = $freshManager->getConnection()->executeQuery(
            'SELECT parent_user_id FROM child_account WHERE child_user_id = :child',
            ['child' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame((string) $firstParent->getId(), (string) $survivorParentId);
    }

    /**
     * `CHECK (child_user_id <> parent_user_id)`: an account cannot parent
     * itself.
     */
    public function testTheChildAccountSelfParentCheckConstraintRefusesAnAccountParentingItselfAc2(): void
    {
        $user = $this->persistUser('self-parent', UserRole::PLAYER);
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(static function () use ($connection, $user, $now): void {
                $connection->executeStatement(
                    'INSERT INTO child_account (id, sign_in_enabled_at, created_at, child_user_id, parent_user_id) VALUES (:id, NULL, :createdAt, :user, :user)',
                    ['id' => (string) new UuidV7(), 'createdAt' => $now, 'user' => (string) $user->getId()],
                );
            });
            self::fail('Expected child_account_not_self_ck to refuse child_user_id = parent_user_id.');
        } catch (DriverException $e) {
            self::assertStringContainsString('child_account_not_self_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM child_account WHERE child_user_id = :user',
            ['user' => (string) $user->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count);
    }

    /**
     * `CHECK ((resolved_at IS NULL) = (resolution IS NULL))`: a half-resolved
     * row (one set, the other not) is refused in either direction.
     */
    public function testTheChildTrainerRequestResolutionCheckConstraintRefusesAHalfResolvedRow(): void
    {
        [$parent, $child, $trainer] = $this->makeFamily('half-resolved');
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        // resolution set without resolved_at.
        try {
            $connection->transactional(static function () use ($connection, $child, $trainer, $parent, $now): void {
                $connection->executeStatement(
                    'INSERT INTO child_trainer_request (id, created_at, last_notified_at, resolved_at, resolution, child_user_id, trainer_id, parent_user_id, share_link_id, resolved_by_user_id) '
                    .'VALUES (:id, :now, :now, NULL, :resolution, :child, :trainer, :parent, NULL, NULL)',
                    [
                        'id' => (string) new UuidV7(),
                        'now' => $now,
                        'resolution' => ChildTrainerRequestResolution::APPROVED->value,
                        'child' => (string) $child->getId(),
                        'trainer' => (string) $trainer->getId(),
                        'parent' => (string) $parent->getId(),
                    ],
                );
            });
            self::fail('Expected child_trainer_request_resolution_ck to refuse resolution set with resolved_at NULL.');
        } catch (DriverException $e) {
            self::assertStringContainsString('child_trainer_request_resolution_ck', $e->getMessage());
        }

        // resolved_at set without resolution.
        try {
            $connection->transactional(static function () use ($connection, $child, $trainer, $parent, $now): void {
                $connection->executeStatement(
                    'INSERT INTO child_trainer_request (id, created_at, last_notified_at, resolved_at, resolution, child_user_id, trainer_id, parent_user_id, share_link_id, resolved_by_user_id) '
                    .'VALUES (:id, :now, :now, :now, NULL, :child, :trainer, :parent, NULL, NULL)',
                    [
                        'id' => (string) new UuidV7(),
                        'now' => $now,
                        'child' => (string) $child->getId(),
                        'trainer' => (string) $trainer->getId(),
                        'parent' => (string) $parent->getId(),
                    ],
                );
            });
            self::fail('Expected child_trainer_request_resolution_ck to refuse resolved_at set with resolution NULL.');
        } catch (DriverException $e) {
            self::assertStringContainsString('child_trainer_request_resolution_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :child',
            ['child' => (string) $child->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'Neither refused insert may have left a row behind.');
    }

    /**
     * `CHECK (day_of_week BETWEEN 1 AND 7)` and `CHECK (starts_at_minute >=
     * 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)`
     * refuse an out-of-range day and an inverted/out-of-bounds range.
     */
    public function testTheAvailabilitySlotCheckConstraintsRefuseBadValues(): void
    {
        $player = $this->persistUser('bad-slot-player', UserRole::PLAYER);
        $connection = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');

        try {
            $connection->transactional(static function () use ($connection, $player, $now): void {
                $connection->executeStatement(
                    'INSERT INTO player_availability_slot (id, day_of_week, starts_at_minute, ends_at_minute, created_at, player_id) VALUES (:id, 8, 60, 120, :now, :player)',
                    ['id' => (string) new UuidV7(), 'now' => $now, 'player' => (string) $player->getId()],
                );
            });
            self::fail('Expected player_availability_slot_day_ck to refuse day_of_week = 8.');
        } catch (DriverException $e) {
            self::assertStringContainsString('player_availability_slot_day_ck', $e->getMessage());
        }

        try {
            $connection->transactional(static function () use ($connection, $player, $now): void {
                $connection->executeStatement(
                    'INSERT INTO player_availability_slot (id, day_of_week, starts_at_minute, ends_at_minute, created_at, player_id) VALUES (:id, 1, 120, 60, :now, :player)',
                    ['id' => (string) new UuidV7(), 'now' => $now, 'player' => (string) $player->getId()],
                );
            });
            self::fail('Expected player_availability_slot_range_ck to refuse starts_at_minute >= ends_at_minute.');
        } catch (DriverException $e) {
            self::assertStringContainsString('player_availability_slot_range_ck', $e->getMessage());
        }

        try {
            $connection->transactional(static function () use ($connection, $player, $now): void {
                $connection->executeStatement(
                    'INSERT INTO player_availability_slot (id, day_of_week, starts_at_minute, ends_at_minute, created_at, player_id) VALUES (:id, 1, 0, 1441, :now, :player)',
                    ['id' => (string) new UuidV7(), 'now' => $now, 'player' => (string) $player->getId()],
                );
            });
            self::fail('Expected player_availability_slot_range_ck to refuse ends_at_minute > 1440.');
        } catch (DriverException $e) {
            self::assertStringContainsString('player_availability_slot_range_ck', $e->getMessage());
        }

        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM player_availability_slot WHERE player_id = :player',
            ['player' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'None of the three refused inserts may have left a row behind.');
    }

    /**
     * AC-9, edge case: two concurrent `disconnect()` calls for the same
     * pairing must yield affected-row counts of exactly 1 and 0 -- the
     * conditional `UPDATE ... WHERE ended_at IS NULL` is what makes the
     * second call a genuine no-op rather than a second, redundant end.
     */
    public function testTwoConcurrentDisconnectCallsYieldAffectedRowCountsOfOneThenZeroAc9(): void
    {
        $trainer = $this->persistUser('disconnect-trainer', UserRole::TRAINER);
        $player = $this->persistUser('disconnect-player', UserRole::PLAYER);

        // Fixture set up directly against the entity, not through
        // `associateWithTrainer()`: that service call also dispatches
        // through `AccountEventRecorder`, which records via its own
        // independent physical connection and cannot see this test's
        // still-uncommitted `User` rows (same reason
        // `ShareLinkInvitationsConstraintsTest` never calls a
        // service method here either).
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        /** @var PlayerShareLinkService $shareLinkService */
        $shareLinkService = self::getContainer()->get(PlayerShareLinkService::class);

        $first = $shareLinkService->endAssociation($trainer, $player);
        $second = $shareLinkService->endAssociation($trainer, $player);

        self::assertTrue($first, 'The first disconnect call must affect exactly one row.');
        self::assertFalse($second, 'The second, redundant disconnect call must affect zero rows.');
    }

    /**
     * AC-8, edge case: two concurrent `connect()`-equivalent inserts for the
     * same trainer/player pairing must resolve to exactly one row -- the
     * same `UNIQUE (trainer_id, player_id) WHERE ended_at IS NULL` shape
     * `ShareLinkInvitationsConstraintsTest` already proves for an adult
     * pairing, reconfirmed here for a child pairing specifically.
     */
    public function testTwoConcurrentConnectInsertsForTheSameChildTrainerPairingYieldOneRowAc8(): void
    {
        [, $child, $trainer] = $this->makeFamily('concurrent-connect');

        $winner = new TrainerPlayerAssociation($trainer, $child, null);
        $this->em->wrapInTransaction(function () use ($winner): void {
            $this->em->persist($winner);
        });

        $loser = new TrainerPlayerAssociation($trainer, $child, null);

        try {
            $this->em->wrapInTransaction(function () use ($loser): void {
                $this->em->persist($loser);
            });
            self::fail('Expected UNIQUE (trainer_id, player_id) WHERE ended_at IS NULL to reject the second, colliding insert.');
        } catch (UniqueConstraintViolationException) {
            // $this->em is now closed -- do not touch it again below.
        }

        $freshManager = $this->managerRegistry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $freshManager);

        $count = $freshManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $child->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-8: exactly one row survives the collision.');
    }

    /**
     * @return array{0: User, 1: User, 2: User} parent, child, trainer
     */
    private function makeFamily(string $prefix): array
    {
        $parent = $this->persistUser($prefix.'-parent', UserRole::PLAYER);
        $child = $this->persistUser($prefix.'-child', UserRole::PLAYER);
        $trainer = $this->persistUser($prefix.'-trainer', UserRole::TRAINER);

        $childAccount = new ChildAccount($child, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return [$parent, $child, $trainer];
    }

    private function persistUser(string $prefix, UserRole $role): User
    {
        $user = new User(UserFactory::email($prefix), UserFactory::passwordHash(), $role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
