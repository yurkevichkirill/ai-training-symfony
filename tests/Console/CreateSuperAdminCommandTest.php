<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Command\CreateSuperAdminCommand;
use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test against the real app_test database (AC-25), exercising
 * `app:create-super-admin` end to end through `CommandTester`, exactly the
 * way an operator would run it.
 *
 * Deliberately does **not** wrap each test in a `beginTransaction()`/
 * `rollBack()` pair the way most other integration tests in this suite do
 * (see e.g. `UserAccountServiceConcurrentCreationTest`,
 * `AuthEventsRecordedTest`). `AuthEventRecorder` (Task 34) writes through a
 * genuinely separate physical connection whose FK to `app_user` requires the
 * created row to be *durably committed*, not merely written to a savepoint
 * inside an outer test transaction that is never actually committed. Letting
 * `UserAccountService::create()`'s own `wrapInTransaction()` call be the
 * *outermost* transaction (i.e. not nesting it inside one this test opens)
 * is what makes that commit real, exactly as it is in production. Cleanup is
 * therefore explicit, by tracked email, in `tearDown()`.
 *
 * `CreateSuperAdminCommand` is fetched directly from the container rather
 * than built by hand: `framework.test: true` (config/packages/framework.yaml)
 * makes every service -- public or private -- reachable via
 * `self::getContainer()->get()` in the test environment specifically (same
 * mechanism `QueuedMailDoesNotBlockResponseTest` relies on for the private
 * `messenger.transport.async` service), and unlike `UserAccountService` in
 * Task 24's test, this command IS referenced elsewhere now (its own
 * `#[AsCommand]` tag), so it is not compiled out.
 */
final class CreateSuperAdminCommandTest extends KernelTestCase
{
    private const string VALID_PASSWORD = 'Sup3r-Admin-B00tstrap-Pwd-1';
    private const string OTHER_VALID_PASSWORD = 'Another-Valid-Bootstrap-Pw-2';

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        // AuthEventRecorder writes through its own separate physical
        // connection (Task 34), so nothing here relies on a rollback --
        // every row this test created is durably committed and must be
        // deleted explicitly.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement(
                'DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)',
                ['email' => $email],
            );
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testInteractiveModeWithValidPromptedInputCreatesAVerifiedSuperAdminAndExitsZero(): void
    {
        $email = 'super-admin-interactive@example.test';
        $this->persistedEmails[] = $email;

        $messagesBefore = $this->countAsyncTransportMessages();

        $tester = $this->tester();
        $tester->setInputs([$email, self::VALID_PASSWORD, self::VALID_PASSWORD]);
        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringNotContainsString(
            self::VALID_PASSWORD,
            $tester->getDisplay(),
            'The plaintext password must never be echoed back to the console.',
        );

        $user = $this->findUser($email);

        self::assertSame(UserRole::SUPER_ADMIN, $user->getRole());
        self::assertNotNull($user->getEmailVerifiedAt(), 'The bootstrap account must be verified immediately (AC-25).');
        self::assertTrue($user->isEmailVerified());

        self::assertSame(
            $messagesBefore,
            $this->countAsyncTransportMessages(),
            'Creating a Super Admin must not dispatch any message (e.g. a verification email) onto the async transport.',
        );
    }

    public function testNonInteractiveModeReadsCredentialsFromTheRealEnvironmentAndExitsZero(): void
    {
        $email = 'super-admin-env@example.test';
        $this->persistedEmails[] = $email;

        $_SERVER['SUPER_ADMIN_EMAIL'] = $email;
        $_SERVER['SUPER_ADMIN_PASSWORD'] = self::OTHER_VALID_PASSWORD;

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute([], ['interactive' => false]);
        } finally {
            unset($_SERVER['SUPER_ADMIN_EMAIL'], $_SERVER['SUPER_ADMIN_PASSWORD']);
        }

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringNotContainsString(self::OTHER_VALID_PASSWORD, $tester->getDisplay());

        $user = $this->findUser($email);

        self::assertSame(UserRole::SUPER_ADMIN, $user->getRole());
        self::assertNotNull($user->getEmailVerifiedAt());
    }

    public function testRunningAgainAndRefusingTheConfirmationPromptExitsNonZeroWithoutCreatingASecondAccount(): void
    {
        $firstEmail = 'super-admin-recovery-first@example.test';
        $this->persistedEmails[] = $firstEmail;

        $first = $this->tester();
        $first->setInputs([$firstEmail, self::VALID_PASSWORD, self::VALID_PASSWORD]);
        self::assertSame(0, $first->execute([], ['interactive' => true]), $first->getDisplay());

        // A second interactive run: the confirmation prompt ("... already
        // exists. Create another one anyway?") is declined, so the command
        // must refuse before ever asking for an email or password.
        $second = $this->tester();
        $second->setInputs(['no']);
        $secondExitCode = $second->execute([], ['interactive' => true]);

        self::assertNotSame(0, $secondExitCode, 'Declining the confirmation must not exit 0.');
        self::assertSame(1, $secondExitCode, 'A declined confirmation is a caught business failure, not an unexpected error.');
        self::assertSame(1, $this->countSuperAdmins(), 'Declining the confirmation must not create a second Super Admin.');
    }

    public function testRunningAgainNonInteractivelyWithoutForceExitsNonZeroWhenASuperAdminAlreadyExists(): void
    {
        $firstEmail = 'super-admin-recovery-second@example.test';
        $this->persistedEmails[] = $firstEmail;

        $first = $this->tester();
        $first->setInputs([$firstEmail, self::VALID_PASSWORD, self::VALID_PASSWORD]);
        self::assertSame(0, $first->execute([], ['interactive' => true]), $first->getDisplay());

        $_SERVER['SUPER_ADMIN_EMAIL'] = 'super-admin-recovery-should-not-exist@example.test';
        $_SERVER['SUPER_ADMIN_PASSWORD'] = self::OTHER_VALID_PASSWORD;

        try {
            $second = $this->tester();
            $secondExitCode = $second->execute([], ['interactive' => false]);
        } finally {
            unset($_SERVER['SUPER_ADMIN_EMAIL'], $_SERVER['SUPER_ADMIN_PASSWORD']);
        }

        self::assertSame(1, $secondExitCode, 'Missing --force in non-interactive mode with an existing Super Admin must exit 1, not 0.');
        self::assertSame(1, $this->countSuperAdmins());
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'super-admin-recovery-should-not-exist@example.test']),
            'No account may be created for the refused non-interactive attempt.',
        );
    }

    private function tester(): CommandTester
    {
        $command = self::getContainer()->get(CreateSuperAdminCommand::class);
        \assert($command instanceof CreateSuperAdminCommand);

        return new CommandTester($command);
    }

    private function findUser(string $email): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, \sprintf('Expected a User row for "%s" to exist.', $email));

        return $user;
    }

    private function countSuperAdmins(): int
    {
        $this->em->clear();

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM app_user WHERE role = :role',
            ['role' => UserRole::SUPER_ADMIN->value],
        );
    }

    /**
     * `messenger_messages` backs the real `async` Doctrine transport
     * (`QueuedMailDoesNotBlockResponseTest` exercises the same table). A
     * plain row count before/after is enough here: `UserAccountService::
     * create()` never dispatches anything (Task 24 built it standalone,
     * before `EmailVerificationService` existed) and this command calls
     * nothing else that could -- so the count must be unchanged.
     */
    private function countAsyncTransportMessages(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }
}
