<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Service\EmailVerificationService;
use App\Service\EmailVerificationTokenService;
use App\Service\Exception\InvalidVerificationTokenException;
use App\Service\Exception\VerificationTokenAlreadyConsumedException;
use App\Service\Exception\VerificationTokenExpiredException;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end proof of the verification mechanism's security-critical
 * property (AC-13, AC-14): a token is single-use, expires after 24 hours,
 * revisiting an already-verified link is idempotent rather than an error,
 * and -- the part a naive sequential test cannot prove -- the `FOR UPDATE`
 * row lock genuinely serializes two consumers racing the same row rather
 * than merely happening to run one after another.
 *
 * Tests exercise both layers deliberately, not interchangeably:
 * `EmailVerificationTokenService::consume()` is where single-use is actually
 * enforced (it throws `VerificationTokenAlreadyConsumedException` on *every*
 * replay of a spent token, no exceptions); `EmailVerificationService::consume()`
 * is the outer, controller-facing wrapper that turns a specific kind of
 * replay -- one whose token already verified its own subject -- into a
 * silent success, so a user who double-clicks (or revisits) their own
 * verification link never sees an error page. Conflating the two layers
 * would make it look like "consuming the same token twice" is sometimes
 * refused and sometimes not for no reason; keeping them separate is what
 * makes both true at once.
 */
final class EmailVerificationFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EmailVerificationTokenService $tokenService;
    private EmailVerificationService $verificationService;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tokenService = self::getContainer()->get(EmailVerificationTokenService::class);
        $this->verificationService = self::getContainer()->get(EmailVerificationService::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // AuthEventRecorder (Task 34) writes EMAIL_VERIFIED rows through its
        // own, genuinely separate physical connection -- see its class
        // docblock -- so they are not covered by the rollback above, the
        // same reason persist() below commits its fixture user instead of
        // leaving it to the rollback.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * The live producer (AC-13's S1 boundary: resend is the only trigger
     * this slice has). Confirms resend() actually issues a persisted,
     * consumable token -- not just that some message got dispatched -- and
     * that consuming it marks the account verified.
     */
    public function testResendIssuesATokenAndConsumingItVerifiesTheUser(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'resend-flow@example.test'));

        $this->verificationService->resend($user->getEmail());

        $dispatched = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $dispatched, 'resend() should dispatch a verification email.');
        self::assertSame(SendEmailMessage::TEMPLATE_VERIFY_EMAIL, $dispatched->template);
        self::assertSame($user->getEmail(), $dispatched->to);

        $token = $dispatched->context['token'] ?? null;
        self::assertIsString($token, 'The dispatched message must carry the raw selector.verifier token.');

        self::assertFalse($user->isEmailVerified());

        $this->verificationService->consume($token);

        self::assertTrue($user->isEmailVerified());
        self::assertNotNull($user->getEmailVerifiedAt());
    }

    /**
     * Single-use, proved at the layer that actually enforces it. If this
     * only tested EmailVerificationService::consume(), the idempotent
     * already-verified branch would swallow the replay as a success and this
     * test would not prove single-use at all -- see the class docblock.
     */
    public function testConsumingTheSameTokenASecondTimeIsRefusedServerSide(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'single-use@example.test'));
        $token = $this->tokenService->issue($user);

        $verifiedUser = $this->tokenService->consume($token);
        self::assertTrue($verifiedUser->isEmailVerified());

        try {
            $this->tokenService->consume($token);
            self::fail('Expected VerificationTokenAlreadyConsumedException on the second consumption of the same token.');
        } catch (VerificationTokenAlreadyConsumedException $e) {
            self::assertSame($user->getId()->toRfc4122(), $e->getUser()->getId()->toRfc4122());
        }
    }

    /**
     * AC-14: more than 24h after issue, the token is refused even though it
     * was never consumed. `expiresAt` is a readonly column populated only at
     * construction, so it cannot be reassigned through the entity (confirmed
     * empirically: PHP refuses even a Reflection-based write to an
     * already-initialized readonly property with "Cannot modify readonly
     * property"). The only way to "manipulate expiresAt directly" per this
     * task's instructions is therefore a raw UPDATE against the column,
     * followed by EntityManager::clear() so the next query rehydrates a
     * fresh (uninitialized, and therefore writable-by-Doctrine) entity
     * instead of returning the stale object already sitting in the identity
     * map.
     */
    public function testATokenConsumedAfter24HoursAndOneMinuteIsRefused(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'expiry@example.test'));
        $token = $this->tokenService->issue($user);
        $userId = $user->getId();

        $tokenEntity = $this->em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(EmailVerificationToken::class, $tokenEntity);
        $selector = $tokenEntity->getSelector();

        $expiredAt = new \DateTimeImmutable('-24 hours -1 minute');
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE email_verification_token SET expires_at = :expiresAt WHERE selector = :selector',
            ['expiresAt' => $expiredAt, 'selector' => $selector],
            ['expiresAt' => Types::DATETIMETZ_IMMUTABLE, 'selector' => Types::STRING],
        );
        self::assertSame(1, $affected, 'The raw UPDATE must hit exactly the one fixture row.');

        // Force the next query to rehydrate fresh entities from the row just
        // updated, rather than returning the stale (not-yet-expired) objects
        // already tracked in the identity map.
        $this->em->clear();

        try {
            $this->tokenService->consume($token);
            self::fail('Expected VerificationTokenExpiredException for a token more than 24h past issue.');
        } catch (VerificationTokenExpiredException $e) {
            self::assertSame($userId->toRfc4122(), $e->getUser()->getId()->toRfc4122());
        }

        $freshUser = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $freshUser);
        self::assertFalse($freshUser->isEmailVerified(), 'An expired token must never mark the account verified.');
    }

    /**
     * Idempotent re-verification, case 1: the *same* token, visited twice.
     * EmailVerificationTokenService::consume() throws
     * VerificationTokenAlreadyConsumedException on the second call (proved
     * above); EmailVerificationService::consume() -- what a revisited link
     * actually calls -- must swallow that specific case as success, and must
     * not touch emailVerifiedAt a second time. Asserted by timestamp
     * equality across both calls, not merely "no exception was thrown".
     */
    public function testRevisitingTheSameVerificationLinkAfterSuccessReportsSuccessAndDoesNotMoveTheTimestamp(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'idempotent-same-token@example.test'));
        $token = $this->tokenService->issue($user);

        $this->verificationService->consume($token);
        self::assertTrue($user->isEmailVerified());
        $firstVerifiedAt = $user->getEmailVerifiedAt();
        self::assertNotNull($firstVerifiedAt);

        // No exception expected here -- this is the assertion.
        $this->verificationService->consume($token);

        self::assertSame(
            $firstVerifiedAt->format('Y-m-d H:i:s.u'),
            $user->getEmailVerifiedAt()?->format('Y-m-d H:i:s.u'),
            'Revisiting the same link a second time must not move emailVerifiedAt.',
        );
    }

    /**
     * Idempotent re-verification, case 2: a *fresh*, unconsumed, unexpired
     * token for a user who is already verified by some other token. This
     * cannot happen through the public resend() API (it refuses to issue a
     * new token once the account is verified), so it is constructed directly
     * against EmailVerificationTokenService::issue() -- exercising it the
     * way an internal caller or an admin action could.
     *
     * Investigated per this task's explicit instruction rather than assumed:
     * EmailVerificationTokenService::consume() does NOT check whether the
     * user is already verified before running its happy path -- it only
     * checks the token's own isConsumed()/isExpired() state -- so this fresh
     * token is consumed for real (its own consumedAt is set) and
     * User::markEmailVerified() is called again. That method is
     * `$this->emailVerifiedAt ??= $at` (null-coalescing assignment), which
     * is what actually keeps this idempotent: no bug found here, but it is
     * the null-coalescing guard doing the work, not any check in
     * consume() itself, so it is worth proving directly rather than by
     * inference.
     */
    public function testConsumingAFreshTokenForAnAlreadyVerifiedUserDoesNotMoveTheTimestamp(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'idempotent-fresh-token@example.test'));

        $firstToken = $this->tokenService->issue($user);
        $this->verificationService->consume($firstToken);
        $firstVerifiedAt = $user->getEmailVerifiedAt();
        self::assertNotNull($firstVerifiedAt);

        // issue() invalidates the (already-spent) first token and mints a
        // genuinely fresh, unconsumed, unexpired one for the same user, who
        // is already verified.
        $secondToken = $this->tokenService->issue($user);

        // No exception expected -- the token itself is valid, so this goes
        // through the ordinary success path, not the already-consumed branch.
        $this->verificationService->consume($secondToken);

        self::assertTrue($user->isEmailVerified());
        self::assertSame(
            $firstVerifiedAt->format('Y-m-d H:i:s.u'),
            $user->getEmailVerifiedAt()?->format('Y-m-d H:i:s.u'),
            'A second, genuinely valid token for an already-verified user must not move emailVerifiedAt.',
        );
    }

    public function testAnInvalidTokenIsRefusedWithoutDisclosingWhyToTheCaller(): void
    {
        $this->expectException(InvalidVerificationTokenException::class);

        $this->tokenService->consume('not-a-real-selector-not-a-real-verifier');
    }

    /**
     * The security-critical case (AC-13, AC-14): two consumers racing the
     * same token row must yield exactly one success -- not because they
     * happen to run one after another (a naive sequential
     * `consume(); consume();` in one process would show "one success, one
     * refusal" even with the `FOR UPDATE` lock deleted, since the second
     * call would simply see the first's already-committed write), but
     * because the row lock genuinely blocks the second reader until the
     * first's transaction resolves.
     *
     * Mechanism, spelled out because it is the whole point of this test:
     *
     * 1. This process opens a second, genuinely independent DBAL connection
     *    (its own Postgres backend) and takes `SELECT ... FOR UPDATE` on the
     *    token row directly, deliberately not committing -- the same lock
     *    `EmailVerificationTokenRepository::findOneBySelectorForUpdate()`
     *    takes, held open on purpose.
     * 2. A second, genuinely separate OS process (fixtures/consume-email-
     *    verification-token-subprocess.php) is spawned. It boots its own
     *    kernel, gets its own Postgres connection, and calls the real
     *    `EmailVerificationTokenService::consume()` -- not a mock, not the
     *    same PHP process. It signals "READY" the instant it is about to
     *    call consume(), so this process's deliberate hold window starts
     *    only once the race is genuinely on, independent of how long the
     *    subprocess took to boot.
     * 3. This process holds the lock for a fixed window after that signal,
     *    then releases it without mutating anything.
     * 4. The subprocess's own consume() call is timed from the inside
     *    (excluding boot time). If the row lock did nothing, that call would
     *    return almost instantly and read consumedAt = null, since nothing
     *    would have stopped it from doing so while this process held its
     *    "lock" -- and it would report SUCCESS as if it were the only
     *    consumer, which is precisely the double-consumption bug the lock
     *    exists to prevent. Instead it must report SUCCESS *and* an elapsed
     *    time at least as long as the hold window, proving it was genuinely
     *    blocked in Postgres, not merely lucky about ordering.
     * 5. This process then makes its own real, second consume() attempt
     *    against the now-actually-consumed row (after EntityManager::clear(),
     *    since this process's identity map is otherwise stale -- it never
     *    saw the subprocess's write). It must be refused as already
     *    consumed, proving the *other* of the two racers lost.
     */
    public function testTwoConcurrentConsumeAttemptsOnTheSameTokenYieldExactlyOneSuccess(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'concurrent-consume@example.test'));
        $token = $this->tokenService->issue($user);
        $userId = $user->getId();

        $tokenEntity = $this->em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(EmailVerificationToken::class, $tokenEntity);
        $selector = $tokenEntity->getSelector();

        // Make the fixture durable: a second real OS process needs its own
        // Postgres session to see this row at all. An uncommitted
        // transaction on this connection is invisible to any other session,
        // by design -- that is what would make the race a no-op rather than
        // a real one.
        $this->em->getConnection()->commit();

        $rawConnection = DriverManager::getConnection($this->em->getConnection()->getParams());
        $process = null;

        $readyFile = tempnam(sys_get_temp_dir(), 'evft-ready-');
        $resultFile = tempnam(sys_get_temp_dir(), 'evft-result-');
        unlink($readyFile); // the subprocess re-creates it -- its *existence* is the signal
        unlink($resultFile);

        try {
            $rawConnection->beginTransaction();
            $rawConnection->executeQuery(
                'SELECT 1 FROM email_verification_token WHERE selector = :selector FOR UPDATE',
                ['selector' => $selector],
            );

            $script = __DIR__.'/fixtures/consume-email-verification-token-subprocess.php';
            $process = proc_open(
                ['php', $script, $token, $readyFile, $resultFile],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                \dirname(__DIR__, 2),
            );
            self::assertIsResource($process, 'Failed to spawn the concurrent-consume subprocess.');
            fclose($pipes[0]);

            // Deliberately not a one-line self::assertTrue(cond, sprintf(...
            // stream_get_contents($pipes[2]))): PHP evaluates every argument
            // before the call happens, so that stream_get_contents() would
            // run unconditionally -- including on the success path, where it
            // blocks (deadlocks, in fact) reading the child's stderr pipe to
            // EOF, since the child cannot exit until this process's held row
            // lock below is released. Read it only if the wait actually
            // failed.
            if (!$this->waitForFile($readyFile, 15.0)) {
                self::fail(\sprintf(
                    "Subprocess did not signal readiness within 15s. stderr:\n%s",
                    stream_get_contents($pipes[2]),
                ));
            }

            $holdMicroseconds = 300_000; // 300ms
            usleep($holdMicroseconds);

            $rawConnection->rollBack();
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
            'SUCCESS',
            $result,
            'The subprocess should win the race once this process releases the row lock it held.',
        );
        self::assertGreaterThanOrEqual(
            200_000_000,
            $elapsedNs,
            \sprintf(
                'The subprocess consume() call completed in %dns, too fast to have been genuinely blocked '.
                'by the %dus row lock this process held -- the FOR UPDATE lock may not be taking effect.',
                $elapsedNs,
                $holdMicroseconds,
            ),
        );

        // This process's identity map never saw the subprocess's write --
        // clear it so this second, real consume() attempt reads the row's
        // actual current state instead of the stale in-memory copy.
        $this->em->clear();

        try {
            $this->tokenService->consume($token);
            self::fail('Expected the second concurrent consume attempt to be refused as already consumed.');
        } catch (VerificationTokenAlreadyConsumedException $e) {
            self::assertTrue($e->getUser()->isEmailVerified());
        }

        // The closed-EntityManager pitfall documented on
        // EmailVerificationTokenService: wrapInTransaction() closes the
        // manager on any exception escaping it, including the expected
        // VerificationTokenAlreadyConsumedException just caught above. This
        // process's own $this->em is now that closed instance -- recover a
        // fresh one the same way the service itself does, rather than
        // reusing it.
        $doctrine = self::getContainer()->get('doctrine');
        $manager = $doctrine->getManagerForClass(User::class);
        if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
            $manager = $doctrine->resetManager();
        }
        \assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;

        $freshUser = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $freshUser);
        self::assertTrue($freshUser->isEmailVerified());

        // Manual cleanup: the fixture rows were committed above, so they are
        // not covered by tearDown()'s rollback.
        $this->em->createQuery('DELETE FROM App\Entity\EmailVerificationToken t WHERE t.user = :user')
            ->setParameter('user', $freshUser)
            ->execute();
        $this->em->remove($freshUser);
        $this->em->flush();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup
        // of everything else. AuthEventRecorder's own physical connection
        // (Task 34) cannot see this row otherwise -- EMAIL_VERIFIED
        // references the verified user by FK, and an uncommitted row is
        // invisible across connections regardless of how deeply nested the
        // enclosing transaction is (Postgres transaction isolation, not a
        // Doctrine limitation). testTwoConcurrentConsumeAttemptsOnTheSameTokenYieldExactlyOneSuccess()
        // still works with this: it needs the fixture durably committed
        // anyway (see its own docblock) -- this reopened transaction is
        // simply the one that test's own later, explicit commit() call
        // finalizes, alongside the token issue() call made in between.
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }

    /**
     * Polls for a file's existence rather than reading a proc_open() pipe
     * with `stream_select()` -- see the concurrent-consume test's comment
     * for why. `clearstatcache()` is required: PHP caches `file_exists()`
     * results per path for the life of the process, so without it this
     * would only ever see the (non-existent) pre-creation state.
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
}
