<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountDeletionLog;
use App\Entity\AccountInvitation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AccountInvitationRepository;
use App\Service\AccountInvitationService;
use App\Service\AccountLifecycleService;
use App\Service\Exception\InvalidAccountInvitationException;
use App\Service\Exception\InvalidAccountStateTransitionException;
use App\Service\SelectorVerifierTokenFactory;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

/**
 * US-01.12/US-01.13: deactivation (soft delete, AC-14…AC-17) and GDPR
 * deletion (AC-18…AC-23), including the state-machine guards.
 */
final class AccountLifecycleFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AccountLifecycleService $lifecycleService;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->lifecycleService = self::getContainer()->get(AccountLifecycleService::class);
    }

    /**
     * Deliberately no wrapping transaction -- same reason as
     * TrainerOnboardingFlowTest: `AccountLifecycleService` records an
     * `AccountEvent` through its own independent physical connection, which
     * must be able to see already-committed fixture rows.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_deletion_log WHERE subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testDeactivatedUserCannotSignInAndReactivationRestoresAccess(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $this->lifecycleService->deactivate($player, $admin);
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertSame(UserStatus::DEACTIVATED, $reloaded?->getStatus());

        $this->assertSignInFails($player);

        $reloadedAgain = $this->em->getRepository(User::class)->find($player->getId());
        \assert($reloadedAgain instanceof User);
        $this->lifecycleService->reactivate($reloadedAgain, $admin);
        $this->em->clear();

        $afterReactivate = $this->em->getRepository(User::class)->find($player->getId());
        self::assertSame(UserStatus::ACTIVE, $afterReactivate?->getStatus());

        $this->assertSignInSucceeds($player);
    }

    /**
     * A session opened before deactivation stops working at its next
     * request -- S1's `EquatableInterface` mechanism, reused with no new
     * code (AC-15's second half).
     *
     * Fetches fresh `User` references via a freshly-resolved
     * `EntityManagerInterface` after the sign-in requests, rather than
     * reusing `$player`/`$admin`: Symfony's test-environment
     * `services_resetter` swaps in a new EntityManager between requests
     * (confirmed empirically -- reusing the pre-request entities here
     * silently no-ops the deactivation, since flush() cannot act on an
     * entity attached to a since-discarded EntityManager). Every other
     * test in this class calls the lifecycle service *before* any HTTP
     * request, so it does not need this.
     */
    public function testASessionOpenBeforeDeactivationStopsWorkingAtItsNextRequest(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $playerId = $player->getId();
        $adminId = $admin->getId();

        $this->assertSignInSucceeds($player);

        $freshEm = self::getContainer()->get(EntityManagerInterface::class);
        $freshPlayer = $freshEm->find(User::class, $playerId);
        $freshAdmin = $freshEm->find(User::class, $adminId);
        \assert($freshPlayer instanceof User && $freshAdmin instanceof User);

        self::getContainer()->get(AccountLifecycleService::class)->deactivate($freshPlayer, $freshAdmin);

        $this->client->request('GET', '/player');
        self::assertResponseRedirects('/login');
    }

    public function testReactivatingAnAlreadyActiveAccountIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $this->expectException(InvalidAccountStateTransitionException::class);
        $this->lifecycleService->reactivate($player, $admin);
    }

    public function testDeletingAUserAnonymizesPiiAndRecordsTheComplianceLog(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $player->setName('Real', 'Name');
        $player->setPhone('+15550001111');
        $originalEmail = $player->getEmail();
        $this->em->flush();

        $this->lifecycleService->delete($player, $admin, 'GDPR request');
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::DELETED, $reloaded->getStatus());
        self::assertNull($reloaded->getFirstName());
        self::assertNull($reloaded->getLastName());
        self::assertNull($reloaded->getPhone());
        self::assertSame(\sprintf('deleted_%s@example.com', $reloaded->getId()), $reloaded->getEmail());
        self::assertNotSame($originalEmail, $reloaded->getEmail());
        self::assertSame('Deleted User', $reloaded->getDisplayName());

        $log = $this->em->getRepository(AccountDeletionLog::class)->findOneBy(['subjectUser' => $reloaded]);
        self::assertInstanceOf(AccountDeletionLog::class, $log);
        self::assertSame(\sprintf('deleted_%s@example.com', $reloaded->getId()), $log->getAnonymizedEmail());
        self::assertNotSame($originalEmail, $log->getAnonymizedEmail());
        self::assertSame('GDPR request', $log->getReference());

        $this->assertSignInFails($reloaded);
    }

    public function testDeletingAnAlreadyDeletedUserIsRefusedAsANoOp(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $this->lifecycleService->delete($player, $admin, null);

        $this->expectException(InvalidAccountStateTransitionException::class);
        $this->lifecycleService->delete($player, $admin, null);
    }

    /**
     * M-code-quality H gap #2: the "already deleted" guard's authoritative
     * layer is documented as the database's unique `subject_user_id` index
     * on `account_deletion_log`, not merely the in-memory
     * `existsForUser()`/status check above -- but a naive sequential
     * `delete(); delete();` in one process only ever exercises the in-memory
     * check (once the first call commits, the second call's own
     * `existsForUser()` SELECT sees that same committed row and never
     * reaches the INSERT at all), the same reason
     * `testTwoConcurrentConsumeAttemptsOnTheSameTokenYieldExactlyOneSuccess()`
     * needs a second, genuinely separate OS process rather than a second
     * in-process call.
     *
     * Mechanism:
     *
     * 1. This process opens a second, genuinely independent DBAL connection
     *    and INSERTs the `account_deletion_log` row for the target account
     *    directly, deliberately not committing -- standing in for "another
     *    delete() call that has already gotten past its own
     *    existsForUser() check and is mid-transaction."
     * 2. A second, genuinely separate OS process
     *    (fixtures/account-lifecycle-delete-subprocess.php) is spawned. It
     *    calls the real `AccountLifecycleService::delete()` for the same
     *    account. Its own `existsForUser()` SELECT runs against a row this
     *    process has not committed yet, so -- exactly like a real race --
     *    it does NOT see it, passes the guard, and proceeds into
     *    `anonymize()` + the `AccountDeletionLog` insert, which collides on
     *    `uniq_account_deletion_log_subject` and blocks, waiting for this
     *    process's uncommitted row to resolve.
     * 3. This process holds its transaction open for a fixed window after
     *    the subprocess signals readiness, then commits -- releasing the
     *    lock with the conflicting key now durably in place, so the
     *    subprocess's blocked INSERT wakes up straight into a unique
     *    violation.
     * 4. The subprocess must report the typed
     *    `InvalidAccountStateTransitionException` (this fix), never
     *    `UniqueConstraintViolationException` or an uncaught 500, and its
     *    delete() call must have been genuinely blocked for at least the
     *    hold window -- not merely lucky about ordering.
     * 5. Back in this process: the account must still be reachable and NOT
     *    DELETED (the subprocess's failed transaction rolled its own
     *    anonymize() back), and exactly one `account_deletion_log` row must
     *    exist for the account -- the one this process inserted.
     */
    public function testTwoConcurrentDeletesForTheSameAccountYieldExactlyOneSuccess(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $playerId = (string) $player->getId();
        $adminId = (string) $admin->getId();

        $rawConnection = DriverManager::getConnection($this->em->getConnection()->getParams());
        $process = null;

        $readyFile = tempnam(sys_get_temp_dir(), 'alft-ready-');
        $resultFile = tempnam(sys_get_temp_dir(), 'alft-result-');
        unlink($readyFile); // the subprocess re-creates it -- its *existence* is the signal
        unlink($resultFile);

        try {
            $rawConnection->beginTransaction();
            $rawConnection->executeStatement(
                'INSERT INTO account_deletion_log (id, subject_user_id, actor_user_id, anonymized_email, reference, deleted_at) VALUES (:id, :subject, :actor, :email, :reference, :deletedAt)',
                [
                    'id' => (string) new UuidV7(),
                    'subject' => $playerId,
                    'actor' => $adminId,
                    'email' => 'race-lock-placeholder@example.test',
                    'reference' => 'Concurrent-delete race lock',
                    'deletedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
                ],
            );

            $script = __DIR__.'/fixtures/account-lifecycle-delete-subprocess.php';
            $process = proc_open(
                ['php', $script, $playerId, $adminId, $readyFile, $resultFile],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                \dirname(__DIR__, 2),
            );
            self::assertIsResource($process, 'Failed to spawn the concurrent-delete subprocess.');
            fclose($pipes[0]);

            if (!$this->waitForFile($readyFile, 15.0)) {
                self::fail(\sprintf(
                    "Subprocess did not signal readiness within 15s. stderr:\n%s",
                    stream_get_contents($pipes[2]),
                ));
            }

            $holdMicroseconds = 300_000; // 300ms
            usleep($holdMicroseconds);

            $rawConnection->commit();
        } finally {
            $rawConnection->close();
        }

        if (!$this->waitForFile($resultFile, 15.0)) {
            self::fail(\sprintf(
                "Subprocess did not report a result within 15s. stderr:\n%s",
                stream_get_contents($pipes[2]),
            ));
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = trim((string) file_get_contents($resultFile));
        @unlink($readyFile);
        @unlink($resultFile);

        self::assertSame(0, $exitCode, \sprintf("Subprocess exited abnormally. stderr:\n%s", $stderr));
        self::assertMatchesRegularExpression(
            '/^RESULT=(\w+);ELAPSED_NS=(\d+)$/',
            $output,
            'Unexpected subprocess output: '.$output,
        );
        preg_match('/^RESULT=(\w+);ELAPSED_NS=(\d+)$/', $output, $matches);
        [, $result, $elapsedNs] = $matches;
        $elapsedNs = (int) $elapsedNs;

        self::assertSame(
            'InvalidAccountStateTransitionException',
            $result,
            'The losing concurrent delete() must be refused with the typed exception, not an uncaught UniqueConstraintViolationException.',
        );
        self::assertGreaterThanOrEqual(
            200_000_000,
            $elapsedNs,
            \sprintf(
                'The subprocess delete() call completed in %dns, too fast to have been genuinely blocked '.
                'by the %dus lock this process held on the account_deletion_log unique key.',
                $elapsedNs,
                $holdMicroseconds,
            ),
        );

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotSame(
            UserStatus::DELETED,
            $reloaded->getStatus(),
            "The losing delete()'s anonymize() must have been rolled back with its failed transaction.",
        );

        $logCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM account_deletion_log WHERE subject_user_id = :id',
            ['id' => $playerId],
        )->fetchOne();
        self::assertSame(1, (int) $logCount, 'Exactly one account_deletion_log row must exist -- the winning insert, not a duplicate.');
    }

    public function testDeactivatingADeletedAccountIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $this->lifecycleService->delete($player, $admin, null);

        $this->expectException(InvalidAccountStateTransitionException::class);
        $this->lifecycleService->deactivate($player, $admin);
    }

    /**
     * H-1 regression: a trainer invitation issued while the account was
     * ACTIVE must not survive deactivation. Consuming it afterwards must be
     * refused and must not touch the stored password hash.
     */
    public function testConsumingAnInvitationForADeactivatedAccountIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $token = $this->issueInvitation($trainer, $admin);
        $originalPasswordHash = $trainer->getPasswordHash();

        $this->lifecycleService->deactivate($trainer, $admin);
        $this->em->clear();

        /** @var AccountInvitationService $invitationService */
        $invitationService = self::getContainer()->get(AccountInvitationService::class);

        try {
            $invitationService->consume($token, 'a-different-password-123');
            self::fail('Consuming an invitation for a deactivated account must be refused.');
        } catch (InvalidAccountInvitationException) {
            // Expected.
        }

        $reloaded = $this->em->getRepository(User::class)->find($trainer->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($originalPasswordHash, $reloaded->getPasswordHash());
    }

    /**
     * H-1 wiring regression: `deactivate()` must delete any pending
     * invitation for the account, not just be blocked by the status guard
     * above -- otherwise a reactivated account's stale invitation link would
     * still work.
     */
    public function testDeactivatingAUserDeletesAnyPendingInvitation(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->issueInvitation($trainer, $admin);

        $this->lifecycleService->deactivate($trainer, $admin);
        $this->em->clear();

        $reloadedTrainer = $this->em->getRepository(User::class)->find($trainer->getId());
        \assert($reloadedTrainer instanceof User);

        $invitationRepository = self::getContainer()->get(AccountInvitationRepository::class);
        self::assertNull($invitationRepository->findOneBy(['user' => $reloadedTrainer]));
    }

    /**
     * H-1 wiring regression, delete() side: GDPR erasure must also purge any
     * pending invitation for the account.
     */
    public function testDeletingAUserDeletesAnyPendingInvitation(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->issueInvitation($trainer, $admin);

        $this->lifecycleService->delete($trainer, $admin, null);
        $this->em->clear();

        $reloadedTrainer = $this->em->getRepository(User::class)->find($trainer->getId());
        \assert($reloadedTrainer instanceof User);

        $invitationRepository = self::getContainer()->get(AccountInvitationRepository::class);
        self::assertNull($invitationRepository->findOneBy(['user' => $reloadedTrainer]));
    }

    /**
     * M-2 regression: GDPR erasure must not orphan the profile photo file on
     * disk -- once `photoKey` is nulled by `anonymize()`, nothing else could
     * ever find it to purge it later.
     */
    public function testDeletingAUserRemovesTheirPhotoFileFromDisk(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $uploadsDir = self::getContainer()->getParameter('app.uploads_dir');
        \assert(\is_string($uploadsDir));
        $photoKey = 'photos/'.bin2hex(random_bytes(16)).'.png';
        $photoPath = $uploadsDir.'/'.$photoKey;
        @mkdir(\dirname($photoPath), 0775, true);
        file_put_contents($photoPath, 'fake-photo-bytes');
        self::assertFileExists($photoPath);

        $player->setPhotoKey($photoKey);
        $this->em->flush();

        $this->lifecycleService->delete($player, $admin, null);

        self::assertFileDoesNotExist($photoPath);
    }

    /**
     * M-3 regression: a Super Admin must not be able to re-personalize a
     * GDPR-anonymized account through the profile-edit route.
     */
    public function testAdminEditRouteReturnsNotFoundForADeletedUser(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->lifecycleService->delete($player, $admin, null);
        $this->signIn($admin);

        $this->client->request('GET', \sprintf('/admin/users/%s/edit', $player->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * M-3 regression, UI side: the Users tool must not even offer the edit
     * path for a DELETED row.
     */
    public function testUsersIndexHasNoEditLinkForADeletedUser(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->lifecycleService->delete($player, $admin, null);
        $this->signIn($admin);

        $crawler = $this->client->request('GET', '/admin/users');

        self::assertCount(0, $crawler->filter(\sprintf('a[href$="/admin/users/%s/edit"]', $player->getId())));
    }

    public function testSuperAdminDeactivatesAndReactivatesThroughTheUsersToolUi(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter(\sprintf('form[action$="/admin/users/%s/deactivate"]', $player->getId()))->form());
        self::assertResponseRedirects('/admin/users');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertSame(UserStatus::DEACTIVATED, $reloaded?->getStatus());
    }

    public function testSuperAdminDeletesAUserThroughTheUsersToolUi(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/delete', $player->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-message', 'cannot be undone');

        $this->client->submit($crawler->selectButton('Delete permanently')->form([
            'delete_user_form[reason]' => 'Support request',
        ]));

        self::assertResponseRedirects('/admin/users');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertSame(UserStatus::DELETED, $reloaded?->getStatus());
    }

    /**
     * Polls for a file's existence rather than reading a proc_open() pipe
     * with `stream_select()` -- same reason and pattern as
     * EmailVerificationFlowTest's identical helper. `clearstatcache()` is
     * required: PHP caches `file_exists()` results per path for the life of
     * the process, so without it this would only ever see the
     * (non-existent) pre-creation state.
     */
    private function waitForFile(string $path, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            clearstatcache(true, $path);

            if (file_exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function assertSignInFails(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects('/login');
    }

    private function assertSignInSucceeds(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
        self::assertNotSame('/login', $this->client->getResponse()->headers->get('Location'));
    }

    private function signIn(User $user): void
    {
        $this->assertSignInSucceeds($user);
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }

    private function issueInvitation(User $trainer, User $actor): string
    {
        $factory = self::getContainer()->get(SelectorVerifierTokenFactory::class);
        $pair = $factory->generate();

        $invitation = new AccountInvitation(
            $trainer,
            $actor,
            $pair->selector,
            $pair->hashedVerifier,
            new \DateTimeImmutable('+7 days'),
        );

        $this->em->persist($invitation);
        $this->em->flush();

        return $pair->token;
    }
}
