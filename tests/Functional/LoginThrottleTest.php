<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\LoginRateLimiter;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * AC-19: repeated failed sign-ins are throttled per account (`login_account`,
 * 5 / 15 minutes) and per source (`login_source`, 20 / hour), and the
 * account throttle applies identically to an unknown email -- so the
 * throttle itself never becomes an enumeration oracle. See
 * `LoginRateLimiter`'s class docblock and specs/auth-foundation-
 * architecture.md's "Rate limiting and CSRF" section.
 *
 * **Why most of the "5 failed attempts" build-up below is done through
 * direct calls to the real `LoginRateLimiter` service instead of real HTTP
 * POSTs, and why the one HTTP request that decides each test skips its
 * usual preceding GET:**
 *
 * `cache.rate_limiter` is `cache.adapter.array` under `when@test` (Task 22),
 * chosen so a fresh test process always starts with an empty limiter.
 * `ArrayAdapter::reset()` clears the pool entirely (`Symfony\Component\
 * Cache\Adapter\ArrayAdapter::reset()` calls `clear()`), and that pool is
 * tagged `kernel.reset` (every `cache.pool`-tagged service is, via
 * `Symfony\Component\Cache\DependencyInjection\CachePoolPass`). Symfony's
 * `Kernel::boot()` runs every `kernel.reset`-tagged service's reset method
 * at the start of the *second and every later* top-level request handled by
 * an already-booted kernel (gated on `!$requestStackSize && $resetServices`,
 * set true at the end of the previous request) -- confirmed empirically
 * (not assumed): a test that fires two separate `$client->request()` calls
 * always sees `cache.rate_limiter` wiped clean immediately before the
 * *second* one is processed, however that second call is made -- GET or
 * POST, and regardless of `KernelBrowser::disableReboot()`, which only
 * skips the browser's own explicit reboot-between-requests and has no
 * effect on this kernel-internal mechanism. That reset is invisible in
 * ordinary use (`FilesystemAdapter::reset()`, used in dev/prod, only
 * flushes deferred writes -- it does not clear anything, so this is purely
 * an artifact of the test-only array pool) but it means: within one test
 * method, at most one `$client->request()` call can ever see rate-limiter
 * state accumulated by anything before it. Every earlier "attempt" has to
 * be simulated by calling the real, production `LoginRateLimiter::consume()`
 * directly (still the real key-hashing and sliding-window logic, just
 * in-process rather than over HTTP -- direct calls never touch
 * `Kernel::handle()`, so they never trigger this reset), and the one HTTP
 * request each test cares about must be the *first* `$client->request()`
 * call that test method makes. `testASingleFailedHttpLoginConsumesExactly
 * OneAccountToken` below closes the loop, proving with a real GET+POST pair
 * that a genuine failed login through the firewall consumes login_account
 * by exactly the same one token a direct `consume()` call does -- so the
 * two are equivalent for everything after it.
 *
 * Skipping the preceding GET is what keeps each decisive request first:
 * stateless CSRF (`SameOriginCsrfTokenManager::isValidOrigin()`) accepts a
 * same-origin `Referer` header on its own, no session or prior page visit
 * required, and the token value it expects is the literal cookie name
 * ('csrf-token' -- see the `_csrf_token` field in security/login.html.twig
 * and `SameOriginCsrfTokenManager::isValidDoubleSubmit()`, which treats a
 * submitted value equal to the cookie name as "double-submit not
 * applicable" rather than invalid). Verified empirically before relying on
 * it here.
 */
final class LoginThrottleTest extends WebTestCase
{
    /**
     * The literal value `_csrf_token` must carry for a request with no
     * cookie/session to pass SameOriginCsrfTokenManager -- not a secret, see
     * the class docblock.
     */
    private const CSRF_TOKEN_VALUE = 'csrf-token';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedEmails = [];

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

        // AuthEventRecorder (Task 34) writes LOGIN_SUCCEEDED/LOGIN_FAILED
        // rows through its own, genuinely separate physical connection --
        // see its class docblock -- so they are not covered by the
        // rollback above, the same reason persist() below commits its
        // fixture user instead of leaving it to the rollback.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * Foundation for every other test here: a genuine failed login, through
     * the real firewall (GET the form, then POST it), consumes exactly one
     * login_account token -- the same production code path, and the same
     * amount, that a direct `LoginRateLimiter::consume()` call does. This is
     * the one test in the file allowed two real requests, because nothing
     * needs to survive past the first.
     */
    public function testASingleFailedHttpLoginAttemptConsumesExactlyOneAccountToken(): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.10');

        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => 'wrong-password',
        ]));

        self::assertNull($this->currentToken());
        self::assertSame(4, $this->peekLoginAccount($user->getEmail())->getRemainingTokens());
    }

    /**
     * Proves the limiter runs before authentication (per the architecture):
     * once 5 failed attempts exhaust login_account for this address, a
     * request with the genuinely correct password is still refused.
     */
    public function testFiveFailedAttemptsLockTheSixthEvenWithTheCorrectPassword(): void
    {
        $ip = '198.51.100.11';
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $remaining = $this->consumeLoginAccount($user->getEmail(), $ip)->getRemainingTokens();
            self::assertSame(
                5 - $attempt,
                $remaining,
                \sprintf('login_account should have exactly %d of 5 tokens left after simulated attempt %d.', 5 - $attempt, $attempt),
            );
        }

        // The 6th attempt: a real HTTP request, the real password. If
        // login_throttling ran after authentication instead of before it,
        // this would succeed.
        $this->bareLoginPost($user->getEmail(), UserFactory::PASSWORD);

        self::assertNull(
            $this->currentToken(),
            'The correct password must still be refused once login_account is exhausted.',
        );
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-19's enumeration-resistance half: an address with no matching
     * account exhausts login_account at exactly the same threshold (5) as a
     * real one, because LoginRateLimiter keys on the submitted identifier,
     * never a resolved User. An attacker cannot use "was I throttled yet?"
     * to learn whether the account exists.
     */
    public function testTheSameThrottleAppliesToAnUnknownEmailAtTheSameRate(): void
    {
        $ip = '198.51.100.12';
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        $unknownEmail = 'nobody-throttle-test@example.test';

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $remaining = $this->consumeLoginAccount($unknownEmail, $ip)->getRemainingTokens();
            self::assertSame(
                5 - $attempt,
                $remaining,
                \sprintf('An unknown email should also have exactly %d of 5 tokens left after simulated attempt %d -- the same rate as a real account.', 5 - $attempt, $attempt),
            );
        }

        // A real HTTP request against the now-exhausted, never-real address.
        $this->bareLoginPost($unknownEmail, 'whatever-password');

        self::assertNull($this->currentToken());
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-19's per-source half: a burst of failed attempts spread across many
     * *different*, never-reused emails from one source trips login_source
     * (20 / hour) while no single email's own login_account counter (5 / 15
     * min) comes anywhere near its limit -- the two limiters are
     * independent, and either alone can refuse a request
     * (AbstractRequestRateLimiter::getMinimalRateLimit() returns whichever
     * of the two is more restrictive).
     */
    public function testABurstAcrossManyEmailsFromOneSourceTripsTheSourceLimiterIndependently(): void
    {
        $burstIp = '198.51.100.40';
        $cleanIp = '198.51.200.40';

        $target = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        // 20 simulated failed attempts, each against a distinct, never-reused
        // email, all from the same source: login_source's entire budget,
        // one token per attempt, same as any other failed login.
        for ($i = 1; $i <= 20; ++$i) {
            $this->consumeLoginAccount(\sprintf('burst-email-%d@example.test', $i), $burstIp);
        }

        self::assertSame(
            0,
            $this->peekLoginSource($burstIp)->getRemainingTokens(),
            'login_source must be exhausted after 20 attempts from one source.',
        );
        // Each of those 20 emails was only ever tried once, nowhere near
        // login_account's own 5/15min limit.
        self::assertSame(4, $this->peekLoginAccount('burst-email-1@example.test')->getRemainingTokens());

        // The target's genuinely correct password, still from the burst IP.
        // login_account has never seen this email before -- if the two
        // limiters were not independent, this would succeed.
        $this->client->setServerParameter('REMOTE_ADDR', $burstIp);
        $this->bareLoginPost($target->getEmail(), UserFactory::PASSWORD);

        self::assertNull(
            $this->currentToken(),
            'A correct password must still be refused while login_source is exhausted, even for an account whose own login_account counter is untouched.',
        );

        // Control: the same correct password for the same account, but this
        // is this test's *second* real HTTP request, so the array pool has
        // already been reset clean regardless of which IP is used now (see
        // the class docblock) -- a genuinely unburdened source, which is
        // exactly what the control needs. A distinct IP is used anyway so
        // the intent reads as "a different, unrelated request" rather than
        // relying on the reset.
        $this->client->setServerParameter('REMOTE_ADDR', $cleanIp);
        $this->bareLoginPost($target->getEmail(), UserFactory::PASSWORD);

        self::assertNotNull(
            $this->currentToken(),
            'The same correct password from an unburdened source must succeed.',
        );
    }

    /**
     * The one real HTTP request a decisive test makes, deliberately without
     * a preceding GET (see the class docblock for why) -- a bare POST with a
     * same-origin Referer header and the stateless CSRF token's literal
     * value.
     */
    private function bareLoginPost(string $identifier, string $password): void
    {
        $this->client->request(
            'POST',
            '/login',
            [
                '_username' => $identifier,
                '_password' => $password,
                '_csrf_token' => self::CSRF_TOKEN_VALUE,
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost/'],
        );
    }

    private function currentToken(): ?object
    {
        return self::getContainer()->get('security.token_storage')->getToken();
    }

    /**
     * Consumes one login_account token for $email via the real production
     * LoginRateLimiter, in-process -- see the class docblock for why this
     * stands in for a real failed HTTP attempt in every test but the first.
     */
    private function consumeLoginAccount(string $email, string $ip): RateLimit
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return self::getContainer()->get(LoginRateLimiter::class)->consume($request);
    }

    /**
     * Reads login_account's remaining tokens for $email, isolated from
     * login_source by pairing it with an IP this file's other methods never
     * submit through -- AbstractRequestRateLimiter::getMinimalRateLimit()
     * returns whichever of the two limiters is more restrictive, so a
     * source leg that is always headroom-full leaves this result reflecting
     * only the email's own login_account count (max 5, always less than the
     * probe IP's untouched login_source count).
     */
    private function peekLoginAccount(string $email): RateLimit
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return self::getContainer()->get(LoginRateLimiter::class)->peek($request);
    }

    /**
     * Reads login_source's remaining tokens for $ip, isolated from
     * login_account by pairing it with an email this file never otherwise
     * submits -- same reasoning as peekLoginAccount(), mirrored.
     */
    private function peekLoginSource(string $ip): RateLimit
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'never-submitted-peek-probe@example.test');

        return self::getContainer()->get(LoginRateLimiter::class)->peek($request);
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup
        // of everything else. AuthEventRecorder's own physical connection
        // (Task 34) cannot see this row otherwise -- LOGIN_SUCCEEDED and
        // LOGIN_FAILED both reference the attempted-against user by FK, and
        // an uncommitted row is invisible across connections regardless of
        // how deeply nested the enclosing transaction is (Postgres
        // transaction isolation, not a Doctrine limitation).
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }
}
