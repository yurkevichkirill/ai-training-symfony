<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImpersonationCloseExpiredCommand;
use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * S6 (AC-8, AC-9, D4): `app:impersonation:close-expired` closes exactly the
 * abandoned, expired-but-open row, leaves an active one untouched, writes
 * exactly one `IMPERSONATION_ENDED` event, and is a no-op run a second time.
 *
 * Same isolation discipline as `CreateSuperAdminCommandTest`: not wrapped in
 * a test transaction, because `AccountEventRecorder` writes through its own,
 * genuinely separate physical connection whose FK to `app_user`/
 * `impersonation_session` needs durably committed rows. Cleanup is explicit,
 * by tracked email, in `tearDown()`.
 */
final class ImpersonationCloseExpiredCommandTest extends KernelTestCase
{
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

        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM account_event WHERE actor_user_id IN (SELECT id FROM app_user WHERE email = :email) OR subject_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM impersonation_session WHERE actor_user_id IN (SELECT id FROM app_user WHERE email = :email) OR subject_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testClosesExactlyTheExpiredOpenRowLeavesTheActiveOneUntouchedAndIsSafeToRunTwice(): void
    {
        $actor = $this->persist(new User(UserFactory::email('actor'), UserFactory::passwordHash(), UserRole::SUPER_ADMIN));
        $expiredSubject = $this->persist(new User(UserFactory::email('expired-subject'), UserFactory::passwordHash(), UserRole::TRAINER));
        $activeSubject = $this->persist(new User(UserFactory::email('active-subject'), UserFactory::passwordHash(), UserRole::PLAYER));

        $now = new \DateTimeImmutable();

        $expiredSession = new ImpersonationSession($actor, $expiredSubject, $now->modify('-2 hours'), $now->modify('-1 hour'));

        // A second, still-active open session for a *different* actor --
        // two open rows for the same actor would collide with the partial
        // unique index, so a second actor is used to keep both open
        // independently until the command runs.
        $secondActor = $this->persist(new User(UserFactory::email('actor-2'), UserFactory::passwordHash(), UserRole::SUPER_ADMIN));
        $activeSession = new ImpersonationSession($secondActor, $activeSubject, $now, $now->add(new \DateInterval('PT1H')));

        $this->em->persist($expiredSession);
        $this->em->persist($activeSession);
        $this->em->flush();

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('1', $tester->getDisplay());

        $this->em->clear();

        $reloadedExpired = $this->em->find(ImpersonationSession::class, $expiredSession->getId());
        self::assertInstanceOf(ImpersonationSession::class, $reloadedExpired);
        self::assertNotNull($reloadedExpired->getEndedAt());
        self::assertSame('TIMEOUT', $reloadedExpired->getEndReason()?->value);

        $reloadedActive = $this->em->find(ImpersonationSession::class, $activeSession->getId());
        self::assertInstanceOf(ImpersonationSession::class, $reloadedActive);
        self::assertNull($reloadedActive->getEndedAt(), 'The still-active session must be left untouched.');

        self::assertSame(1, $this->countEndedEvents($expiredSubject));

        // Running again immediately must be a safe no-op: no further
        // change, no error.
        $secondTester = $this->tester();
        $secondExitCode = $secondTester->execute([]);

        self::assertSame(0, $secondExitCode, $secondTester->getDisplay());
        self::assertSame(1, $this->countEndedEvents($expiredSubject), 'A second run must not write a second IMPERSONATION_ENDED event.');
    }

    private function tester(): CommandTester
    {
        $command = self::getContainer()->get(ImpersonationCloseExpiredCommand::class);
        \assert($command instanceof ImpersonationCloseExpiredCommand);

        return new CommandTester($command);
    }

    private function countEndedEvents(User $subject): int
    {
        $this->em->clear();

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id AND type = :type',
            ['id' => (string) $subject->getId(), 'type' => AccountEventType::IMPERSONATION_ENDED->value],
        );
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        return $user;
    }
}
