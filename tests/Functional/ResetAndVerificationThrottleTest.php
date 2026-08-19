<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\IpTruncator;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * AC-20 (reinforcing AC-11 and AC-19's enumeration-resistance property in the
 * reset/verification context specifically): the shared `password_reset_account`
 * (3/hour) / `password_reset_source` (10/hour) limiter pair -- consumed by
 * both `PasswordResetService` (Task 30) and `EmailVerificationService`
 * (Task 26) -- must never let an exhausted *account* limiter surface as a
 * 429, because a 429 keyed to one address would announce that the address
 * exists (specs/auth-foundation-architecture.md's "Rate limiting and CSRF"
 * section, and its Decisions-table row: "Account-limiter rejection renders
 * the same generic page; only source-limiter rejection may 429"). This is
 * the account/source-429 asymmetry the architecture calls out by name, and
 * this file proves it directly for the reset/verification context rather
 * than assuming it from `LoginThrottleTest`'s login-context proof (Task 23),
 * since the two are genuinely separate mechanisms (`LoginRateLimiter` runs
 * inside `login_throttling`, before authentication; the reset/verification
 * pair is consumed explicitly inside each service's own method body).
 *
 * **The same test-only array-cache-reset issue Task 22/23 documented,
 * confirmed here for these two limiters specifically, not assumed to carry
 * over unchanged.** `cache.rate_limiter` is `cache.adapter.array` under
 * `when@test` (Task 22), and `ArrayAdapter::reset()` (tagged `kernel.reset`)
 * wipes it clean at the start of the *second and later* top-level request
 * handled by an already-booted kernel/container -- `KernelBrowser::
 * disableReboot()` has no effect on this kernel-internal mechanism (Task
 * 23's docblock has the full citation trail). Confirmed empirically for this
 * file's own limiters (not the login ones) before relying on it: a bare
 * second `$client->request()` call in one test method reliably saw
 * `limiter.password_reset_account`/`limiter.password_reset_source` reset to
 * full budget. The workaround is the same as Task 23's: every "prior
 * attempt" before the decisive one is simulated with a direct,
 * in-process `RateLimiterFactory::create($key)->consume()` call against the
 * *real* `limiter.password_reset_account`/`limiter.password_reset_source`
 * services (never touching `Kernel::handle()`, so never triggering the
 * reset), and each test makes exactly **one** real HTTP request -- a bare
 * POST, no preceding GET, so it is unconditionally the client's *first*
 * request and therefore never itself reset before it runs.
 *
 * **Keying, confirmed against the real services rather than re-derived.**
 * `password_reset_account` is keyed on the normalized email as-is
 * (`User::normalizeEmail()`, no extra secret-keyed hash -- unlike
 * `login_account`); `password_reset_source` is keyed on
 * `IpTruncator::truncate()` of the client IP. Both
 * `PasswordResetService::request()` and `EmailVerificationService::resend()`
 * key identically (Tasks 26/30's own docblocks confirm this), which is what
 * makes it safe to simulate prior attempts for *either* endpoint against the
 * same two named limiter services.
 *
 * **A genuine asymmetry-of-asymmetries, found by reading the two services'
 * code rather than assuming they mirror each other.** `PasswordResetService::
 * request()` throws `SourceRateLimitExceededException` when
 * `password_reset_source` is exhausted, and `ResetPasswordController::request()`
 * catches exactly that exception to return a real 429 -- confirmed in this
 * file's reset-flow source test below. `EmailVerificationService::resend()`,
 * by contrast, never throws for *either* limiter -- its own docblock is
 * explicit ("An exhausted limiter (either one) is handled by silently
 * returning, never by throwing"), and `EmailVerificationController::resend()`
 * has no exception handling around the call at all (confirmed by reading
 * both files directly). The architecture's rule ("only the source limiter
 * *may* surface a 429") is worded as a permission, not a mandate, and
 * `EmailVerificationService` exercises the stricter, more conservative half
 * of that permission: it never turns *any* rate-limit outcome into an
 * observable status-code difference, for either limiter. This file's
 * verification-resend source-exhaustion test therefore asserts the
 * behaviour the code actually has (200, not 429) rather than assuming the
 * reset flow's choice transfers unchanged -- the resend endpoint's
 * enumeration-resistance is, if anything, strictly stronger than AC-20
 * requires, not weaker.
 */
final class ResetAndVerificationThrottleTest extends WebTestCase
{
    /**
     * The literal value a CSRF field must carry for a stateless-CSRF request
     * with no cookie/session to pass `SameOriginCsrfTokenManager` -- not a
     * secret, see `LoginThrottleTest`'s class docblock for the full
     * citation. `config/packages/csrf.yaml` lists `submit` (the token id
     * every ordinary Symfony Form in this project uses, including both forms
     * here) in `stateless_token_ids`, so this is the same mechanism, not a
     * login-specific special case.
     */
    private const CSRF_TOKEN_VALUE = 'csrf-token';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
     * `password_reset_account`'s 3/hour budget: a 4th request for the same
     * registered account within the hour must still render the generic
     * `check_email` page at 200 -- never a 429, which would otherwise leak
     * that the account exists via the status code alone, not just the body
     * (AC-11's guarantee extended to the status line, not only the HTML).
     */
    public function testFourResetPasswordRequestsForTheSameAccountNeverProduce429(): void
    {
        $ip = '198.51.100.70';
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'reset-account-throttle@example.test'));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $remaining = $this->consumeResetAccount($user->getEmail())->getRemainingTokens();
            self::assertSame(
                3 - $attempt,
                $remaining,
                \sprintf('password_reset_account should have exactly %d of 3 tokens left after simulated attempt %d.', 3 - $attempt, $attempt),
            );
            $this->consumeResetSource($ip);
        }

        // The 4th request: a real HTTP request, account limiter now
        // exhausted. If an exhausted account limiter could ever surface a
        // 429, this would fail here.
        $this->bareFormPost('/reset-password', 'reset_password_request_form', ['email' => $user->getEmail()]);

        $response = $this->client->getResponse();
        self::assertSame(
            200,
            $response->getStatusCode(),
            'An exhausted password_reset_account limiter must never produce a 429 -- that would announce the account exists.',
        );
        self::assertStringContainsString('Check your email', (string) $response->getContent());
    }

    /**
     * `password_reset_source`'s 10/hour budget: 10 simulated attempts spread
     * across ten different, never-reused (and here, all unregistered)
     * emails from one source exhaust `password_reset_source` while none of
     * those emails comes anywhere near its own `password_reset_account`
     * limit -- the two limiters are independent (mirroring
     * `LoginThrottleTest`'s equivalent login_source proof). The 11th
     * request, for a brand-new email from the same source, is refused with a
     * real 429 -- source exhaustion carries no per-account signal, so
     * surfacing it is safe (AC-19-shaped, per the architecture).
     */
    public function testElevenResetPasswordRequestsFromTheSameSourceMayProduce429(): void
    {
        $ip = '198.51.100.71';

        for ($i = 1; $i <= 10; ++$i) {
            $this->consumeResetAccount(\sprintf('reset-source-burst-%d@example.test', $i));
            $this->consumeResetSource($ip);
        }

        self::assertSame(
            0,
            $this->peekResetSource($ip)->getRemainingTokens(),
            'password_reset_source must be exhausted after 10 attempts from one source.',
        );
        // Each of those 10 emails was only ever tried once -- nowhere near
        // password_reset_account's own 3/hour limit.
        self::assertSame(2, $this->peekResetAccount('reset-source-burst-1@example.test')->getRemainingTokens());

        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        // The 11th request: a real HTTP request, a genuinely fresh email
        // this source has never tried before. Its own account limiter has
        // full budget -- only the exhausted source limiter can refuse this.
        $this->bareFormPost('/reset-password', 'reset_password_request_form', ['email' => 'reset-source-eleventh@example.test']);

        $response = $this->client->getResponse();
        self::assertSame(
            429,
            $response->getStatusCode(),
            'An exhausted password_reset_source limiter may (and here, does) produce a 429 -- it carries no per-account signal.',
        );
        self::assertTrue($response->headers->has('Retry-After'));
    }

    /**
     * The same account-exhaustion shape as above, for the verification-resend
     * endpoint, which shares the identical `password_reset_account` limiter
     * (per the architecture's "one pair of limiters, reused" decision, Task
     * 22/26). A different account and a different source IP from the
     * reset-flow tests above, so this test's own attempt count cannot be
     * confused with theirs even though the underlying limiter keys are the
     * same named services (belt-and-suspenders documentation -- each test
     * method gets its own fresh kernel/container per `KernelTestCase::
     * tearDown()`'s unconditional `ensureKernelShutdown()`, so the array
     * cache pool is already empty at the start of every method regardless).
     */
    public function testFourVerificationResendRequestsForTheSameAccountNeverProduce429(): void
    {
        $ip = '198.51.100.72';
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER, 'verify-account-throttle@example.test'));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $remaining = $this->consumeResetAccount($user->getEmail())->getRemainingTokens();
            self::assertSame(3 - $attempt, $remaining);
            $this->consumeResetSource($ip);
        }

        $this->bareFormPost('/verify-email/resend', 'resend_verification_form', ['email' => $user->getEmail()]);

        $response = $this->client->getResponse();
        self::assertSame(
            200,
            $response->getStatusCode(),
            'An exhausted password_reset_account limiter must never produce a 429 for verification-resend either -- same non-enumeration guarantee, same shared limiter.',
        );
        self::assertStringContainsString('Check your email', (string) $response->getContent());
    }

    /**
     * The source-exhaustion shape for verification-resend -- but, per this
     * file's class docblock, `EmailVerificationService::resend()` never
     * throws on rate-limit exhaustion at all (unlike `PasswordResetService::
     * request()`), so the 11th request here stays at 200, not 429. This is
     * confirmed behaviour, not a gap: the architecture permits ("may
     * surface a 429") rather than requires a 429 for source exhaustion, and
     * this endpoint's own code deliberately never takes that option, making
     * its non-enumeration guarantee strictly uniform across every outcome
     * (found, not-found, already-verified, account-rate-limited,
     * source-rate-limited) instead of carving out one observable exception
     * the way the reset flow does.
     */
    public function testElevenVerificationResendRequestsFromTheSameSourceNeverProduce429Either(): void
    {
        $ip = '198.51.100.73';

        for ($i = 1; $i <= 10; ++$i) {
            $this->consumeResetAccount(\sprintf('verify-source-burst-%d@example.test', $i));
            $this->consumeResetSource($ip);
        }

        self::assertSame(
            0,
            $this->peekResetSource($ip)->getRemainingTokens(),
            'password_reset_source must be exhausted after 10 attempts from one source.',
        );

        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        $this->bareFormPost('/verify-email/resend', 'resend_verification_form', ['email' => 'verify-source-eleventh@example.test']);

        $response = $this->client->getResponse();
        self::assertSame(
            200,
            $response->getStatusCode(),
            'EmailVerificationService::resend() never throws on rate-limit exhaustion (either limiter), so this endpoint never produces a 429 -- confirmed behaviour, see the class docblock.',
        );
        self::assertStringContainsString('Check your email', (string) $response->getContent());
    }

    /**
     * A bare POST directly against a plain Symfony Form's action, with no
     * preceding GET -- see the class docblock for why skipping the GET is
     * what keeps this the client's *first* (and only) real HTTP request per
     * test method, and why the literal CSRF field value below is what a
     * request with no cookie/session needs to pass stateless CSRF.
     *
     * @param array<string, string> $fields the form's own fields (e.g. 'email'), unprefixed
     */
    private function bareFormPost(string $uri, string $formName, array $fields): void
    {
        $payload = [$formName => array_merge($fields, ['_token' => self::CSRF_TOKEN_VALUE])];

        $this->client->request(
            'POST',
            $uri,
            $payload,
            [],
            ['HTTP_REFERER' => 'http://localhost/'],
        );
    }

    /**
     * Consumes one `password_reset_account` token for $email via the real,
     * production `RateLimiterFactory` service, in-process -- see the class
     * docblock for why this stands in for a real prior HTTP attempt.
     */
    private function consumeResetAccount(string $email): RateLimit
    {
        return $this->resetAccountLimiter()->create(User::normalizeEmail($email))->consume();
    }

    private function consumeResetSource(string $ip): RateLimit
    {
        return $this->resetSourceLimiter()->create(IpTruncator::truncate($ip))->consume();
    }

    /**
     * Reads a limiter's remaining tokens for a key without consuming one.
     * `LimiterInterface` has no dedicated peek method -- confirmed by
     * reading `Symfony\Component\RateLimiter\LimiterInterface` directly --
     * but `SlidingWindowLimiter::consume(0)` is a genuine no-op read:
     * `reserve(0, 0)` returns the current `RateLimit` without ever calling
     * `$window->add()` or `$this->storage->save()` (confirmed by reading
     * `SlidingWindowLimiter::reserve()`'s `0 === $tokens` branch directly),
     * so it cannot itself perturb the count it reports.
     */
    private function peekResetAccount(string $email): RateLimit
    {
        return $this->resetAccountLimiter()->create(User::normalizeEmail($email))->consume(0);
    }

    private function peekResetSource(string $ip): RateLimit
    {
        return $this->resetSourceLimiter()->create(IpTruncator::truncate($ip))->consume(0);
    }

    private function resetAccountLimiter(): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.password_reset_account');
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter;
    }

    private function resetSourceLimiter(): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.password_reset_source');
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
