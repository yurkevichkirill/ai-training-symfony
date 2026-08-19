<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * End-to-end proof of the password reset flow (AC-9, AC-10, AC-11, AC-12):
 * uniform non-enumerating response, one-hour expiry, single use, the
 * request-time sibling invalidation `PasswordResetService::request()` does,
 * the cross-session edge case the controller's `$session->invalidate()`
 * covers, and -- as its own standalone test, per this task's explicit
 * requirement -- AC-12's "completing a reset invalidates every other live
 * session" guarantee.
 *
 * **Multiple simultaneous sessions, the mechanism.** `WebTestCase::createClient()`
 * refuses a second call ("the kernel should only be booted once"), so "two
 * separate test clients" cannot mean two `self::createClient()` calls.
 * `config/../vendor/symfony/framework-bundle/Resources/config/test.php`
 * registers the `test.client` service itself as `share(false)` (and its
 * `History`/`CookieJar` collaborators the same way), so
 * `self::getContainer()->get('test.client')` hands back a genuinely
 * independent `KernelBrowser` -- its own cookie jar, its own history -- while
 * every client still shares the one kernel/container `setUp()` booted (and
 * therefore the one open DB transaction fixtures need to be visible at all).
 * `KernelBrowser::doRequest()` only reboots on a client's *second* request
 * (`$this->hasPerformedRequest && $this->reboot`, confirmed by reading the
 * source directly, not assumed) -- since every client here makes at least two
 * requests (a GET, then a POST), `disableReboot()` is called on each one
 * immediately after `newIndependentClient()` creates it, exactly as `setUp()`
 * already does for the primary client. Skipping it on any client would reboot
 * the *shared* kernel on that client's second request, tearing down every
 * other client's container mid-test.
 *
 * **Multi-client assertions deliberately avoid `self::assertResponseRedirects()`
 * and friends.** Those helpers read a single `static $client` slot
 * (`BrowserKitAssertionsTrait::getClient()`) that only `self::createClient()`
 * populates -- so after acting on a second or third client, they would keep
 * reporting on the *first* client's last response. Every assertion below
 * therefore inspects `$client->getResponse()` directly (a genuine
 * `Symfony\Component\HttpFoundation\Response`, confirmed by reading
 * `KernelBrowser`/`HttpKernelBrowser::doRequest()`), which is unambiguous
 * per client.
 *
 * **AC-12's session-invalidation mechanism, verified rather than assumed to
 * "just work".** `main`'s firewall is `lazy: true` (Task 12), which raised
 * the question of whether `ContextListener` -- the listener that reloads the
 * session's user and compares it via `EquatableInterface` -- runs on every
 * request or only when something actually touches the token. Read
 * `Symfony\Component\Security\Http\Firewall\ContextListener::supports()`
 * directly: it returns `null` with the comment "always run authenticate()
 * lazily with lazy firewalls" -- `lazy: true` defers *token storage*
 * initialization, not `ContextListener` itself, which reads the session,
 * calls `EntityUserProvider::refreshUser()`, and runs
 * `self::hasUserChanged()` -- `$originalUser->isEqualTo($refreshedUser)` for
 * any `EquatableInterface` user, which `App\Entity\User` is (Task 5) -- on
 * *every* request that has a previous session, unconditionally. No gap was
 * found in `security.yaml`: the mechanism is genuinely automatic, and this
 * test's job is to prove that empirically over real HTTP requests, not to
 * add any production code. See `testCompletingAResetInvalidatesBothOtherLiveSessionsAndAnyOtherOutstandingToken()`
 * for the proof itself.
 *
 * Follows Task 17/19/28's conventions: `UserFactory`, `disableReboot()` (a
 * fresh Doctrine connection per reboot cannot see the uncommitted fixture
 * rows), a transaction begun in `setUp()` and rolled back in `tearDown()`,
 * and a GET before every POST so BrowserKit's Referer/Sec-Fetch-Site make it
 * past the stateless CSRF check (see `SignInTest`'s docblock for why a bare
 * POST would fail for the wrong reason).
 */
final class PasswordResetFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        // EntityManager::close() (triggered by PasswordResetService::complete()'s
        // wrapInTransaction() closing on every expected rejection -- invalid,
        // expired, or already-used token) never touches the underlying DBAL
        // Connection (confirmed by reading EntityManager::getConnection()/close()
        // directly: close() only clears the UnitOfWork and flips a flag). So
        // $this->em's Connection is safe to roll back here even on a test whose
        // last HTTP call left $this->em's ORM layer closed.
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // AuthEventRecorder (Task 34) writes PASSWORD_RESET_REQUESTED/
        // PASSWORD_RESET_COMPLETED rows through its own, genuinely separate
        // physical connection -- see its class docblock -- so they are not
        // covered by the rollback above, the same reason persist() below
        // commits its fixture user instead of leaving it to the rollback.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * AC-11: the response must not disclose whether the address is
     * registered. Both the rendered page and the "was an email even sent"
     * signal are asserted -- a response that merely looked the same while
     * silently emailing only the registered address would still leak
     * existence through the send-or-not decision.
     */
    public function testRequestingAResetForARegisteredAndAnUnregisteredAddressRendersByteIdenticalOutput(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'registered-a@example.test'));

        $this->requestReset($this->client, $user->getEmail());
        $registeredResponse = $this->client->getResponse();
        $registeredStatus = $registeredResponse->getStatusCode();
        $registeredBody = (string) $registeredResponse->getContent();

        self::assertCount(1, $this->mailHandler->all(), 'A registered address must dispatch exactly one reset email.');

        $this->requestReset($this->client, 'nobody-registered@example.test');
        $unregisteredResponse = $this->client->getResponse();

        self::assertCount(
            1,
            $this->mailHandler->all(),
            'An unregistered address must never dispatch an email -- the send-or-not decision itself would leak existence even if the rendered page matched.',
        );
        self::assertSame($registeredStatus, $unregisteredResponse->getStatusCode());
        self::assertSame(
            $registeredBody,
            (string) $unregisteredResponse->getContent(),
            'The check-email page must be byte-identical whether or not the address is registered (AC-11).',
        );
    }

    /**
     * AC-10, first half: `expiresAt` has no setter (the bundle's own trait
     * only assigns it once, in `initialize()`), so the only way to
     * "manipulate expiresAt directly", per this task's own instruction, is a
     * raw UPDATE against the column -- mirroring
     * EmailVerificationFlowTest::testATokenConsumedAfter24HoursAndOneMinuteIsRefused()'s
     * exact technique for the sibling entity. `em->clear()` afterwards is
     * required for the same reason it was there: this test's client shares
     * one EntityManager/identity map across every HTTP request it makes
     * (`disableReboot()`), so without clearing it, the *controller's* own
     * lookup of this row would return the already-tracked (not-yet-expired)
     * object from `request()`'s own `generateResetToken()` call rather than
     * rehydrating the row this UPDATE just changed.
     */
    public function testATokenOlderThanOneHourIsRefused(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'expiry@example.test'));

        $this->requestReset($this->client, $user->getEmail());
        $token = $this->lastDispatchedResetToken();
        $selector = substr($token, 0, 20);

        $expiredAt = new \DateTimeImmutable('-1 hour -1 minute');
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE reset_password_request SET expires_at = :expiresAt WHERE selector = :selector',
            ['expiresAt' => $expiredAt, 'selector' => $selector],
            ['expiresAt' => Types::DATETIME_IMMUTABLE, 'selector' => Types::STRING],
        );
        self::assertSame(1, $affected, 'The raw UPDATE must hit exactly the one fixture row.');

        $this->em->clear();

        $this->completeReset($this->client, $token, 'a-brand-new-password-01');

        $this->assertResetWasRefused($this->client);
    }

    /**
     * AC-10, second half: "refused on second use even within the hour" --
     * the token is deleted at first use (`removeResetRequest()`), not merely
     * marked, so a replay within the hour is refused for the same reason an
     * unknown token is. The first completion's effect is confirmed for real
     * (a fresh client signs in with the new password), not just "no
     * exception was thrown on the happy path".
     */
    public function testUsingAValidTokenOnceSucceedsAndUsingItAgainIsRefused(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'single-use@example.test'));

        $this->requestReset($this->client, $user->getEmail());
        $token = $this->lastDispatchedResetToken();

        $this->completeReset($this->client, $token, 'first-new-password-01');
        $this->assertResetSucceeded($this->client);

        $signInClient = $this->newIndependentClient();
        $this->signIn($signInClient, $user, 'first-new-password-01');
        $this->assertAuthenticated($signInClient);

        $this->completeReset($this->client, $token, 'second-attempt-password-02');
        $this->assertResetWasRefused($this->client);
    }

    /**
     * Sibling-invalidation edge case: `PasswordResetService::request()` calls
     * `removeRequests($user)` *before* issuing a new token (Task 30), so
     * requesting twice leaves only the second token valid -- proved here
     * end-to-end over HTTP, not just at the service layer
     * (`PasswordResetServiceIdentityMapTest` already covers `complete()`'s
     * own mechanics in isolation).
     */
    public function testRequestingResetTwiceInvalidatesTheEarlierTokenAndOnlyTheLatestSucceeds(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'sibling@example.test'));

        $this->requestReset($this->client, $user->getEmail());
        $earlierToken = $this->lastDispatchedResetToken();

        $this->requestReset($this->client, $user->getEmail());
        $latestToken = $this->lastDispatchedResetToken();

        self::assertNotSame($earlierToken, $latestToken);

        $this->completeReset($this->client, $earlierToken, 'should-never-apply-pw-01');
        $this->assertResetWasRefused($this->client);

        $this->completeReset($this->client, $latestToken, 'the-actual-new-password-02');
        $this->assertResetSucceeded($this->client);
    }

    /**
     * The cross-session edge case, its own explicit assertion (not inferred
     * from the others): a *different* user's browser -- authenticated as the
     * bystander, never as the token's subject -- opens the subject's reset
     * link and submits it. Mechanically this is one call,
     * `$request->getSession()->invalidate()` in
     * `ResetPasswordController::reset()`, which discards whatever session is
     * attached to the *current* request (the bystander's) regardless of
     * whose token is being completed, while `PasswordResetService::complete()`
     * applies the new password to the token's own subject (looked up via the
     * token, never via the session). Both halves are asserted independently:
     * the bystander's prior session is gone, and the password change landed
     * on the subject, not the bystander.
     */
    public function testOpeningAResetLinkWhileAuthenticatedAsADifferentUserDiscardsThatSessionAndAppliesTheChangeToTheTokensSubject(): void
    {
        $subject = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'cross-session-subject@example.test'));
        $bystander = $this->persist(UserFactory::activeVerified(UserRole::COACH, 'cross-session-bystander@example.test'));

        $this->requestReset($this->client, $subject->getEmail());
        $token = $this->lastDispatchedResetToken();

        $bystanderClient = $this->newIndependentClient();
        $this->signIn($bystanderClient, $bystander);
        $this->assertAuthenticated($bystanderClient);

        // The bystander's own authenticated browser opens the subject's link.
        $this->completeReset($bystanderClient, $token, 'subjects-new-password-03');
        $this->assertResetSucceeded($bystanderClient);

        // Half 1: the bystander's pre-existing session is gone.
        $this->assertUnauthenticated($bystanderClient);

        // Half 2: the change landed on the token's subject, never on the
        // session's owner -- proved by a real sign-in with each account's
        // password, not by peeking at a hash column.
        $subjectClient = $this->newIndependentClient();
        $this->signIn($subjectClient, $subject, 'subjects-new-password-03');
        $this->assertAuthenticated($subjectClient);

        $bystanderStillClient = $this->newIndependentClient();
        $this->signIn($bystanderStillClient, $bystander, UserFactory::PASSWORD);
        $this->assertAuthenticated($bystanderStillClient);
    }

    /**
     * The AC-12 test, standalone and explicit, covering both halves of the
     * architecture's "completion invalidates siblings and, via
     * EquatableInterface + passwordChangedAt, every other live session" note:
     *
     * 1. Two genuinely independent sessions for the *same* account
     *    (`newIndependentClient()` -- see the class docblock) are
     *    established by two real sign-ins.
     * 2. A second, uncompleted reset token is manufactured to still be
     *    outstanding at the moment of completion. This deliberately calls
     *    the bundle's own `ResetPasswordHelperInterface::generateResetToken()`
     *    directly rather than `PasswordResetService::request()`:
     *    `request()` always deletes any prior token for the account *before*
     *    issuing a new one (Task 30, proved above in the sibling-invalidation
     *    test), so two calls to `request()` could never leave two tokens
     *    simultaneously alive -- it would only prove request()'s own
     *    pre-generate invalidation a second time, not complete()'s. Bypassing
     *    it is the only way to construct the precondition this scenario
     *    actually needs.
     * 3. A third, unauthenticated context completes one of the two tokens.
     * 4. Both original sessions are asserted unauthenticated on their next
     *    request -- the empirical proof that `ContextListener`'s
     *    `EquatableInterface` comparison (see the class docblock) really does
     *    reject the stale password hash on a live HTTP request, not merely
     *    that the database row changed.
     * 5. The other, still-outstanding token is asserted refused. Reading the
     *    bundle's own `removeResetPasswordRequest()` directly
     *    (`Persistence/Repository/ResetPasswordRequestRepositoryTrait.php`)
     *    shows it deletes *every* request row for the token's user, not only
     *    the one matching the token passed in -- so in this codebase this
     *    guarantee is actually enforced twice over: once by `complete()`'s
     *    first call, `removeResetRequest($token)` (via that same
     *    all-of-this-user delete), and redundantly again by `complete()`'s
     *    own explicit trailing `removeRequests($user)`. Harmless, but worth
     *    recording rather than assuming the explicit call is what does the
     *    work.
     */
    public function testCompletingAResetInvalidatesBothOtherLiveSessionsAndAnyOtherOutstandingToken(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'ac12@example.test'));

        $sessionOne = $this->client;
        $this->signIn($sessionOne, $user);
        $this->assertAuthenticated($sessionOne);

        $sessionTwo = $this->newIndependentClient();
        $this->signIn($sessionTwo, $user);
        $this->assertAuthenticated($sessionTwo);

        // Every real HTTP request dispatched by this test fires kernel.terminate,
        // which runs Doctrine\Bundle\DoctrineBundle\Registry::reset() (confirmed
        // by reading it directly) -- and that clears the *whole* identity map
        // on every registered EntityManager after *every* request, disableReboot()
        // notwithstanding (reboot only skips re-booting the kernel/container;
        // it does not skip kernel.terminate). $user, managed at persist() above,
        // is therefore a detached PHP object by now (two sign-ins' worth of HTTP
        // round trips later) -- confirmed empirically: contains($user) is false
        // here even though $this->em is still the same, still-open manager.
        // generateResetToken() below needs a managed User to persist the new
        // ResetPasswordRequest's cascade-checked association against, so it is
        // re-fetched by id first.
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $resetPasswordHelper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        \assert($resetPasswordHelper instanceof ResetPasswordHelperInterface);

        $tokenToKeepOutstanding = $resetPasswordHelper->generateResetToken($user)->getToken();
        $tokenToComplete = $resetPasswordHelper->generateResetToken($user)->getToken();
        self::assertNotSame($tokenToKeepOutstanding, $tokenToComplete);

        $completingClient = $this->newIndependentClient();
        $this->completeReset($completingClient, $tokenToComplete, 'ac12-new-password-04');
        $this->assertResetSucceeded($completingClient);

        // The empirical AC-12 proof: both pre-existing sessions, established
        // before the reset, are unauthenticated on their very next request.
        $this->assertUnauthenticated($sessionOne);
        $this->assertUnauthenticated($sessionTwo);

        // The other outstanding token is refused too.
        $checkOutstandingClient = $this->newIndependentClient();
        $this->completeReset($checkOutstandingClient, $tokenToKeepOutstanding, 'ac12-should-never-apply-05');
        $this->assertResetWasRefused($checkOutstandingClient);
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup
        // of everything else. AuthEventRecorder's own physical connection
        // (Task 34) cannot see this row otherwise -- both PASSWORD_RESET_*
        // event types reference the requesting/completing user by FK, and
        // an uncommitted row is invisible across connections regardless of
        // how deeply nested the enclosing transaction is (Postgres
        // transaction isolation, not a Doctrine limitation).
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }

    /**
     * A genuinely independent `KernelBrowser` (its own cookie jar and
     * history) sharing the one kernel/container `setUp()` booted -- see the
     * class docblock for why this, not `self::createClient()`, is how this
     * test gets "two separate clients", and why `disableReboot()` must be
     * called immediately.
     */
    private function newIndependentClient(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        \assert($client instanceof KernelBrowser);
        $client->disableReboot();

        return $client;
    }

    private function signIn(KernelBrowser $client, User $user, string $password = UserFactory::PASSWORD): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => $password,
        ]));

        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect(),
            \sprintf('Sign-in as %s did not redirect as expected. Body: %s', $user->getEmail(), $response->getContent()),
        );
        self::assertNotSame(
            '/login',
            parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH),
            \sprintf('Sign-in as %s was bounced back to the login form.', $user->getEmail()),
        );
    }

    private function requestReset(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/reset-password');
        $client->submit($crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => $email,
        ]));

        self::assertTrue(
            $client->getResponse()->isSuccessful(),
            'Requesting a reset must always render the check-email page (or 429 only on source-limiter exhaustion, not hit in these tests).',
        );
    }

    /**
     * Recovers the raw `selector.verifier` token from the most recently
     * dispatched reset email -- the only place it exists once `request()`
     * returns `void` by design (AC-11-shaped non-enumeration), mirroring
     * `EmailVerificationFlowTest`'s identical technique for the sibling flow.
     */
    private function lastDispatchedResetToken(): string
    {
        $message = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $message, 'Expected a reset-password email to have been dispatched.');
        self::assertSame(SendEmailMessage::TEMPLATE_RESET_PASSWORD, $message->template);

        $token = $message->context['token'] ?? null;
        self::assertIsString($token, 'The dispatched message must carry the raw selector.verifier token.');

        return $token;
    }

    private function completeReset(KernelBrowser $client, string $token, string $newPassword): void
    {
        $crawler = $client->request('GET', '/reset-password/reset/'.$token);
        $client->submit($crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => $newPassword,
            'change_password_form[plainPassword][second]' => $newPassword,
        ]));
    }

    private function assertResetSucceeded(KernelBrowser $client): void
    {
        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect('/login'),
            'A successful reset must redirect to /login. Body: '.$response->getContent(),
        );
    }

    private function assertResetWasRefused(KernelBrowser $client): void
    {
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'Reset link invalid',
            (string) $response->getContent(),
            'A refused reset must render the refused state, not succeed or 500.',
        );
    }

    /**
     * @return Crawler unused by callers today, kept so a future assertion on
     *                  the destination page does not have to change this
     *                  helper's signature
     */
    private function assertAuthenticated(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/');
        $location = parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_PATH);

        self::assertNotSame(
            '/login',
            $location,
            'Expected an authenticated session (redirected somewhere other than /login).',
        );

        return $crawler;
    }

    private function assertUnauthenticated(KernelBrowser $client): void
    {
        $client->request('GET', '/');

        self::assertSame(
            '/login',
            parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_PATH),
            'Expected the session to be unauthenticated (redirected to /login).',
        );
    }
}
