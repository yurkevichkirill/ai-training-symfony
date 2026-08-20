<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Command\SweepUnverifiedAccountsCommand;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\UuidV7;

/**
 * Task 39 coverage gap: `app:sweep-unverified-accounts` (Task 37) was only
 * manually verified via `psql` before this file -- no automated test
 * existed. Integration test against the real app_test database, exercising
 * the command end to end through `CommandTester`, following
 * `CreateSuperAdminCommandTest`'s own conventions for this project's console
 * commands.
 *
 * Deliberately does **not** wrap each test in a `beginTransaction()`/
 * `rollBack()` pair: `SweepUnverifiedAccountsCommand::deleteAccount()` runs
 * its own `Connection::transactional()` per account, and this test needs
 * those commits to be real so a second, independent command run (the
 * "second run is a no-op" test) sees them -- the same reasoning
 * `CreateSuperAdminCommandTest`'s class docblock gives for its own
 * untransacted setup. Cleanup is therefore explicit, by tracked id, in
 * `tearDown()`.
 */
final class SweepUnverifiedAccountsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * `--dry-run` must report the true candidate (a stale, unverified,
     * PLAYER row) without deleting it or its `account_event` row, and must
     * never list a row that fails any one of the candidate query's own
     * filters: a stale but already-*verified* PLAYER, a stale-and-unverified
     * but wrong-*role* TRAINER, and a fresh (not old enough) unverified
     * PLAYER.
     */
    public function testDryRunReportsTheStaleCandidateWithoutDeletingAnything(): void
    {
        $stale = $this->createStaleUnverified(UserRole::PLAYER, 'sweep-dry-run-stale', '-2 hours');
        $this->insertRegistrationEvent($stale);

        $freshUnverified = $this->createStaleUnverified(UserRole::PLAYER, 'sweep-dry-run-fresh', '-1 minute');
        $staleVerified = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('sweep-dry-run-verified')));
        $this->backdateCreatedAt($staleVerified, '-2 hours');
        $staleTrainer = $this->createStaleUnverified(UserRole::TRAINER, 'sweep-dry-run-trainer', '-2 hours');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();

        self::assertStringContainsString($stale->getEmail(), $display);
        self::assertStringContainsString('1 account(s) would be deleted', $display);
        self::assertStringNotContainsString($freshUnverified->getEmail(), $display);
        self::assertStringNotContainsString($staleVerified->getEmail(), $display);
        self::assertStringNotContainsString($staleTrainer->getEmail(), $display);

        // Nothing was actually touched.
        self::assertNotNull($this->findUser($stale->getEmail()), 'Dry run must never delete the candidate row.');
        self::assertSame(1, $this->countAccountEventsFor($stale), 'Dry run must never delete the candidate\'s account_event row.');
    }

    /**
     * The real run: the stale candidate's `app_user` row and its own
     * `account_event` row (the `RESTRICT` FK this command's own docblock
     * documents) are both deleted, in that order, within one transaction --
     * every other fixture row is untouched.
     */
    public function testRealRunDeletesTheStaleAccountAndItsOwnAccountEventRow(): void
    {
        $stale = $this->createStaleUnverified(UserRole::COACH, 'sweep-real-run-stale', '-3 hours');
        $this->insertRegistrationEvent($stale);

        $freshUnverified = $this->createStaleUnverified(UserRole::PLAYER, 'sweep-real-run-fresh', '-1 minute');

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Deleted 1 stale unverified account(s)', $tester->getDisplay());

        self::assertNull($this->findUser($stale->getEmail()), 'The stale account\'s app_user row must be deleted.');
        self::assertSame(0, $this->countAccountEventsFor($stale), 'The stale account\'s own account_event row must be deleted alongside it.');

        self::assertNotNull($this->findUser($freshUnverified->getEmail()), 'A not-yet-stale account must never be touched.');
    }

    /**
     * Running the sweep again immediately afterward -- nothing left to
     * match -- must be a clean, successful no-op, never an error.
     */
    public function testASecondRunAfterTheFirstIsACleanNoOp(): void
    {
        $stale = $this->createStaleUnverified(UserRole::PLAYER, 'sweep-second-run', '-2 hours');

        $first = new CommandTester($this->command());
        self::assertSame(Command::SUCCESS, $first->execute([]));
        self::assertNull($this->findUser($stale->getEmail()));

        $second = new CommandTester($this->command());
        $exitCode = $second->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $second->getDisplay());
        self::assertStringContainsString('No accounts matched', $second->getDisplay());
    }

    /**
     * `--hours=0` and a non-numeric `--hours` are both refused as a clean
     * validation failure (`Command::FAILURE`, exit 1), never an uncaught
     * error or a silent no-op that could be confused with "nothing matched".
     */
    public function testAnInvalidHoursOptionExitsWithACleanValidationFailure(): void
    {
        $zeroHours = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $zeroHours->execute(['--hours' => '0']));
        self::assertStringContainsString('--hours must be a positive integer', $zeroHours->getDisplay());

        $nonNumeric = new CommandTester($this->command());
        self::assertSame(Command::FAILURE, $nonNumeric->execute(['--hours' => 'not-a-number']));
        self::assertStringContainsString('--hours must be a positive integer', $nonNumeric->getDisplay());
    }

    private function command(): SweepUnverifiedAccountsCommand
    {
        $command = self::getContainer()->get(SweepUnverifiedAccountsCommand::class);
        \assert($command instanceof SweepUnverifiedAccountsCommand);

        return $command;
    }

    /**
     * A never-verified account of the given role, its `created_at`
     * backdated directly via SQL (`User` exposes no setter for it) so it
     * lands on either side of the command's cutoff as the caller chooses.
     */
    private function createStaleUnverified(UserRole $role, string $emailPrefix, string $createdAtOffset): User
    {
        $user = $this->persist(UserFactory::activeUnverified($role, UserFactory::email($emailPrefix)));
        $this->backdateCreatedAt($user, $createdAtOffset);

        return $user;
    }

    private function backdateCreatedAt(User $user, string $offset): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE app_user SET created_at = :createdAt WHERE id = :id',
            [
                'createdAt' => (new \DateTimeImmutable($offset))->format('Y-m-d H:i:sP'),
                'id' => (string) $user->getId(),
            ],
        );
        $this->em->clear();
    }

    /**
     * A minimal, directly-inserted `account_event` row standing in for the
     * real `PLAYER_REGISTERED_VIA_SHARE_LINK`/`COACH_INVITATION_ACCEPTED`
     * row this command's own docblock says every real candidate already
     * has -- exercising the exact `RESTRICT` FK deletion order the command
     * must get right, without needing a full registration flow.
     */
    private function insertRegistrationEvent(User $subject): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO account_event (id, occurred_at, type, actor_user_id, subject_user_id, ip, user_agent, context) '
            ."VALUES (:id, :occurredAt, :type, :actor, :subject, NULL, NULL, '{}'::jsonb)",
            [
                'id' => (string) new UuidV7(),
                'occurredAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
                'type' => 'PLAYER_REGISTERED_VIA_SHARE_LINK',
                'actor' => (string) $subject->getId(),
                'subject' => (string) $subject->getId(),
            ],
        );
    }

    private function countAccountEventsFor(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id',
            ['id' => (string) $subject->getId()],
        );
    }

    private function findUser(string $email): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
