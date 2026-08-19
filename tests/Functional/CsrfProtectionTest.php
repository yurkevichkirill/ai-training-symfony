<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\UniformAuthenticationFailureHandler;
use App\Service\EmailVerificationTokenService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * The AC-21 sweep: every state-changing route named in the spec, CSRF
 * token stripped and CSRF token altered, ten requests total, none of them
 * refused for the wrong reason and none of them performing the action they
 * were supposed to be gated on.
 *
 * **The "altered" value must be deliberately short -- this is not
 * arbitrary, and using a long forged value would make that half of the
 * sweep pass for the wrong reason (or not fail at all).** Every one of
 * these five routes' CSRF ids (`authenticate`, `logout`, `submit`) is
 * decorated by the same `Symfony\Component\Security\Csrf\
 * SameOriginCsrfTokenManager` (`config/packages/csrf.yaml`'s
 * `stateless_token_ids`, confirmed in Task 2's notes). Read directly rather
 * than assumed: `isTokenValid()`'s *first* check is
 * `\strlen($token->getValue()) < self::TOKEN_MIN_LENGTH` (24) `&& $token->getValue()
 * !== $this->cookieName` -- true, it rejects immediately, before either the
 * Origin/Referer check or the double-submit-cookie check ever runs. A
 * *long* forged value (>= 24 chars, matching no real cookie) skips that
 * gate and then genuinely passes `isValidOrigin()` on this project's own
 * same-origin GET-then-POST test flow (BrowserKit sets `Referer` from
 * history automatically -- Task 2's notes, again), which is accepted by
 * design: `SameOriginCsrfTokenManager` does not otherwise care what the
 * token's value *is*, only whether the request is verifiably same-origin.
 * That is correct behaviour for the stateless scheme (the token's specific
 * content is not the security boundary here, same-origin-ness is), but it
 * means a forged-but-long value is not "invalid" from this manager's point
 * of view -- it would be accepted. `ALTERED_CSRF_TOKEN` below is therefore a
 * short literal (well under 24 characters), which trips the length gate
 * unconditionally, regardless of Origin/Referer -- the only shape of
 * "altered to an invalid value" this manager actually refuses. Confirmed by
 * reading `vendor/symfony/security-csrf/SameOriginCsrfTokenManager.php`
 * directly before relying on it here, not assumed from the login-only
 * behaviour Tasks 17/23 already exercised.
 *
 * **Per-route rejection shape, investigated per route rather than assumed
 * uniform (this task's own instruction):**
 *
 * - `/login`: CSRF lives on the `form_login` authenticator's own
 *   `CsrfTokenBadge`, checked by `Symfony\Component\Security\Http\
 *   EventListener\CsrfProtectionListener::checkPassport()`, which throws
 *   `InvalidCsrfTokenException` -- an `AuthenticationException`. That is
 *   caught by the same `AuthenticatorManager::handleAuthenticationFailure()`
 *   path a wrong password takes, which dispatches `LoginFailureEvent` to
 *   `UniformAuthenticationFailureHandler` (Task 16): a 303 redirect to
 *   `/login` with the one uniform flash message, indistinguishable from any
 *   other sign-in failure. No authenticated token is ever created.
 *   (Side note, not asserted here: `LoginThrottlingListener`
 *   (`CheckPassportEvent` priority 2080) runs *before*
 *   `CsrfProtectionListener` (priority 512) confirmed directly in both
 *   classes, so a CSRF-rejected login attempt still consumes one
 *   `login_account`/`login_source` token, exactly like any other failed
 *   attempt -- Task 23 already covers that mechanism; it does not change
 *   whether the request authenticates, which is all this file asserts.)
 * - `/logout`: `LogoutListener::authenticate()` checks the CSRF token
 *   itself (not via `CsrfTokenBadge`) and throws `LogoutException` --
 *   *not* an `AuthenticationException` -- on a stripped or invalid token.
 *   `Symfony\Component\Security\Http\Firewall\ExceptionListener::
 *   handleLogoutException()` wraps that in an `AccessDeniedHttpException`,
 *   i.e. a plain 403. Confirmed by reading both classes directly. The
 *   existing authenticated session is never touched: `LogoutListener`
 *   throws before it ever dispatches `LogoutEvent` or calls
 *   `$tokenStorage->setToken(null)`.
 * - `/reset-password`, `/reset-password/reset/{token}`,
 *   `/verify-email/resend`: ordinary Symfony Forms (`config/packages/
 *   csrf.yaml`'s `token_id: submit`). `Symfony\Component\Form\Extension\
 *   Csrf\EventListener\CsrfValidationListener::preSubmit()` adds a
 *   *form-level* `FormError` (rendered by this project's
 *   `templates/form/_error_summary.html.twig` as a plain, unanchored list
 *   item -- Task 38's accessibility pass already found this exact shape)
 *   without ever calling the controller's service. Every one of these three
 *   controllers only calls its service inside `if ($form->isSubmitted() &&
 *   $form->isValid())`, so a CSRF-rejected submission never reaches
 *   `PasswordResetService`/`EmailVerificationService` at all -- confirmed by
 *   reading all three controllers, not inferred. `Symfony\Bundle\
 *   FrameworkBundle\Controller\AbstractController::doRender()` then sets the
 *   response to 422 automatically, because a `FormInterface` parameter is
 *   submitted-and-invalid (the exact mechanism Task 27 already documented
 *   for a blank-email submission).
 *
 * Follows Task 17/19/23/28/32's conventions: `UserFactory`,
 * `disableReboot()`, a transaction begun in `setUp()` and rolled back in
 * `tearDown()`, and `persist()` commits+reopens because
 * `AuthEventSubscriber`/`AuthEventRecorder` write through their own,
 * genuinely separate physical connection (Task 34) whenever a real sign-in
 * or sign-out succeeds -- which happens here for both the login fixture
 * (either the CSRF-rejected attempt itself fires `LOGIN_FAILED`, or the
 * logout tests' precondition sign-in fires `LOGIN_SUCCEEDED`) -- so that row
 * needs `app_user` to already be visible on *that* connection, and both
 * need manual cleanup since they are not covered by this test's own
 * rollback.
 */
final class CsrfProtectionTest extends WebTestCase
{
    /**
     * Deliberately short -- see the class docblock's first note for exactly
     * why a long forged value would not actually be rejected here.
     */
    private const ALTERED_CSRF_TOKEN = 'not-a-real-csrf-token';

    /**
     * `SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper::SELECTOR_LENGTH`
     * (private in the bundle) -- mirrors `PasswordResetService`'s own copy of
     * this same constant, confirmed against the installed bundle source.
     */
    private const RESET_SELECTOR_LENGTH = 20;

    /**
     * `EmailVerificationTokenService::SELECTOR_LENGTH` (private there) --
     * `random_bytes(9)` base64url-encoded is always exactly 12 characters.
     */
    private const VERIFICATION_SELECTOR_LENGTH = 12;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Without this, the kernel reboots between requests and each gets a
        // fresh Doctrine connection that cannot see the uncommitted fixture
        // row -- see Task 17's docblock for the original discovery.
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // AuthEventRecorder writes through its own, genuinely separate
        // physical connection (Task 34's class docblock) -- not covered by
        // the rollback above. ON DELETE CASCADE on both token tables' user_id
        // FK takes care of any reset_password_request/email_verification_token
        // row a *successful* proof-of-validity request in these tests left
        // committed.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    // --- /login ---------------------------------------------------------

    public function testLoginWithCsrfTokenStrippedIsRefusedAndEstablishesNoSession(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]);
        $form->remove('_csrf_token');

        $this->client->submit($form);

        $this->assertLoginWasRefused();
    }

    public function testLoginWithCsrfTokenAlteredIsRefusedAndEstablishesNoSession(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
            '_csrf_token' => self::ALTERED_CSRF_TOKEN,
        ]);

        $this->client->submit($form);

        $this->assertLoginWasRefused();
    }

    /**
     * Same uniform shape a wrong password gets (Task 16): 303 to `/login`,
     * the one fixed flash message, and -- the side-effect proof -- no
     * authenticated token now or on the very next request to a protected
     * route.
     */
    private function assertLoginWasRefused(): void
    {
        self::assertResponseRedirects('/login');
        self::assertNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A CSRF-rejected login must not authenticate the request.',
        );

        $loginPage = $this->client->followRedirect();
        $flashes = $loginPage->filter('[role="alert"]')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertSame(
            [UniformAuthenticationFailureHandler::FAILURE_MESSAGE],
            $flashes,
            'A CSRF-rejected login must render the identical uniform failure message, not a CSRF-specific one.',
        );

        // No side effect: a subsequent request to a protected route is still anonymous.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    // --- /logout ---------------------------------------------------------

    public function testLogoutWithCsrfTokenStrippedIsRefusedAndTheSessionStaysAuthenticated(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $dashboard = $this->signInAndReachDashboard($user);

        $form = $dashboard->selectButton('Sign out')->form();
        $form->remove('_csrf_token');

        $this->client->submit($form);

        $this->assertLogoutWasRefused($user);
    }

    public function testLogoutWithCsrfTokenAlteredIsRefusedAndTheSessionStaysAuthenticated(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $dashboard = $this->signInAndReachDashboard($user);

        $form = $dashboard->selectButton('Sign out')->form();
        $form['_csrf_token'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertLogoutWasRefused($user);
    }

    /**
     * `LogoutException` (not an `AuthenticationException`) is wrapped as a
     * plain `AccessDeniedHttpException` -- 403, per the class docblock's
     * second note. The side-effect proof: the pre-existing authenticated
     * session must still work on its very next request, mirroring Task 19's
     * `LogoutAndSessionRegenerationTest` in reverse.
     */
    private function assertLogoutWasRefused(User $user): void
    {
        self::assertSame(
            403,
            $this->client->getResponse()->getStatusCode(),
            'A CSRF-rejected /logout must be refused with 403 (LogoutException wrapped as AccessDeniedHttpException).',
        );

        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A CSRF-rejected logout must not have cleared the token storage.',
        );

        // No side effect: the pre-existing session is still authenticated on its next request.
        $this->client->request('GET', '/player');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $user->getEmail(),
            (string) $this->client->getResponse()->getContent(),
            'The dashboard must still render as this signed-in user -- the rejected logout must not have ended the session.',
        );
    }

    // --- /reset-password ---------------------------------------------------

    public function testResetPasswordRequestWithCsrfTokenStrippedIsRefusedAndSendsNoEmail(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => $user->getEmail(),
        ]);
        $form->remove('reset_password_request_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        self::assertNull($this->mailHandler->last(), 'A CSRF-rejected reset request must never dispatch an email.');
        self::assertSame(
            0,
            $this->countResetPasswordRequestsFor($user),
            'A CSRF-rejected reset request must never create a reset token -- the controller only calls PasswordResetService::request() inside if ($form->isValid()).',
        );
    }

    /**
     * The "altered" half additionally starts from an *already outstanding*
     * token, so this proves the stronger half of the task's "or" clause: not
     * only is no new token created, the existing one is provably untouched
     * -- it still completes a real reset afterwards.
     */
    public function testResetPasswordRequestWithCsrfTokenAlteredIsRefusedAndLeavesTheExistingTokenUntouched(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $resetPasswordHelper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        \assert($resetPasswordHelper instanceof ResetPasswordHelperInterface);
        $existingToken = $resetPasswordHelper->generateResetToken($user)->getToken();
        $existingSelector = substr($existingToken, 0, self::RESET_SELECTOR_LENGTH);

        $crawler = $this->client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Send reset link')->form([
            'reset_password_request_form[email]' => $user->getEmail(),
        ]);
        $form['reset_password_request_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        self::assertNull($this->mailHandler->last(), 'A CSRF-rejected reset request must never dispatch an email.');
        self::assertSame(
            $existingSelector,
            $this->onlyResetPasswordSelectorFor($user),
            'A CSRF-rejected reset request must not replace (or remove) an already-outstanding token.',
        );

        // Proof the token is genuinely unconsumed, not merely "still in the table":
        $this->completeReset($existingToken, 'still-valid-after-csrf-01');
        self::assertTrue(
            $this->client->getResponse()->isRedirect('/login'),
            'The untouched token must still complete a real reset.',
        );
    }

    // --- /reset-password/reset/{token} --------------------------------------

    public function testCompletePasswordResetWithCsrfTokenStrippedIsRefusedAndLeavesThePasswordAndTokenUnchanged(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $originalHash = $user->getPasswordHash();

        $resetPasswordHelper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        \assert($resetPasswordHelper instanceof ResetPasswordHelperInterface);
        $token = $resetPasswordHelper->generateResetToken($user)->getToken();

        $crawler = $this->client->request('GET', '/reset-password/reset/'.$token);
        $form = $crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => 'rejected-attempt-pw-01',
            'change_password_form[plainPassword][second]' => 'rejected-attempt-pw-01',
        ]);
        $form->remove('change_password_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertPasswordHashUnchanged($user, $originalHash);

        // Proof the token is genuinely unconsumed: it still completes for real.
        $this->completeReset($token, 'still-valid-after-csrf-02');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    public function testCompletePasswordResetWithCsrfTokenAlteredIsRefusedAndLeavesThePasswordAndTokenUnchanged(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $originalHash = $user->getPasswordHash();

        $resetPasswordHelper = self::getContainer()->get(ResetPasswordHelperInterface::class);
        \assert($resetPasswordHelper instanceof ResetPasswordHelperInterface);
        $token = $resetPasswordHelper->generateResetToken($user)->getToken();

        $crawler = $this->client->request('GET', '/reset-password/reset/'.$token);
        $form = $crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => 'rejected-attempt-pw-02',
            'change_password_form[plainPassword][second]' => 'rejected-attempt-pw-02',
        ]);
        $form['change_password_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertPasswordHashUnchanged($user, $originalHash);

        $this->completeReset($token, 'still-valid-after-csrf-03');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'));
    }

    // --- /verify-email/resend -----------------------------------------------

    public function testVerifyEmailResendWithCsrfTokenStrippedIsRefusedAndIssuesNoToken(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER));

        $crawler = $this->client->request('GET', '/verify-email/resend');
        $form = $crawler->selectButton('Send verification link')->form([
            'resend_verification_form[email]' => $user->getEmail(),
        ]);
        $form->remove('resend_verification_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        self::assertNull($this->mailHandler->last(), 'A CSRF-rejected resend must never dispatch an email.');
        self::assertSame(
            0,
            $this->countVerificationTokensFor($user),
            'A CSRF-rejected resend must never issue a verification token -- the controller only calls EmailVerificationService::resend() inside if ($form->isValid()).',
        );
    }

    /**
     * The "altered" half starts from an already-outstanding token, proving
     * the stronger half of the task's "or" clause -- untouched, not merely
     * absent-of-a-new-one -- by actually verifying with it afterwards.
     */
    public function testVerifyEmailResendWithCsrfTokenAlteredIsRefusedAndLeavesTheExistingTokenUntouched(): void
    {
        $user = $this->persist(UserFactory::activeUnverified(UserRole::PLAYER));

        $tokenService = self::getContainer()->get(EmailVerificationTokenService::class);
        \assert($tokenService instanceof EmailVerificationTokenService);
        $existingToken = $tokenService->issue($user);
        $existingSelector = substr($existingToken, 0, self::VERIFICATION_SELECTOR_LENGTH);

        $crawler = $this->client->request('GET', '/verify-email/resend');
        $form = $crawler->selectButton('Send verification link')->form([
            'resend_verification_form[email]' => $user->getEmail(),
        ]);
        $form['resend_verification_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        self::assertNull($this->mailHandler->last(), 'A CSRF-rejected resend must never dispatch an email.');
        self::assertSame(
            $existingSelector,
            $this->onlyVerificationSelectorFor($user),
            'A CSRF-rejected resend must not replace (or remove) the already-outstanding token.',
        );

        // Proof the token is genuinely unconsumed: it still verifies for real.
        $this->client->request('GET', '/verify-email/'.$existingToken);
        self::assertStringContainsString(
            'Email verified',
            (string) $this->client->getResponse()->getContent(),
            'The untouched token must still verify the account for real.',
        );
    }

    // --- shared helpers ------------------------------------------------------

    /**
     * Common to the three Symfony-Form-based routes: 422 (per
     * `AbstractController::doRender()`'s submitted-and-invalid rule), and
     * the same root-level, unanchored CSRF error every one of them shares
     * via `CsrfValidationListener`/`templates/form/_error_summary.html.twig`.
     */
    private function assertFormRejectedForInvalidCsrf(): void
    {
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'The CSRF token is invalid',
            (string) $response->getContent(),
            'A CSRF-rejected form submission must re-render with the CSRF validation error.',
        );
    }

    private function assertPasswordHashUnchanged(User $user, string $originalHash): void
    {
        $this->em->clear();
        $freshUser = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $freshUser);
        self::assertSame(
            $originalHash,
            $freshUser->getPasswordHash(),
            'A CSRF-rejected password change must never touch the stored hash.',
        );
    }

    private function completeReset(string $token, string $newPassword): void
    {
        $crawler = $this->client->request('GET', '/reset-password/reset/'.$token);
        $this->client->submit($crawler->selectButton('Reset password')->form([
            'change_password_form[plainPassword][first]' => $newPassword,
            'change_password_form[plainPassword][second]' => $newPassword,
        ]));
    }

    private function countResetPasswordRequestsFor(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM reset_password_request r JOIN app_user u ON u.id = r.user_id WHERE u.email = :email',
            ['email' => $user->getEmail()],
        );
    }

    private function onlyResetPasswordSelectorFor(User $user): string
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.selector FROM reset_password_request r JOIN app_user u ON u.id = r.user_id WHERE u.email = :email',
            ['email' => $user->getEmail()],
        );
        self::assertCount(1, $rows, 'Expected exactly one outstanding reset-password request for this account.');

        return $rows[0]['selector'];
    }

    private function countVerificationTokensFor(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM email_verification_token t JOIN app_user u ON u.id = t.user_id WHERE u.email = :email',
            ['email' => $user->getEmail()],
        );
    }

    private function onlyVerificationSelectorFor(User $user): string
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT t.selector FROM email_verification_token t JOIN app_user u ON u.id = t.user_id WHERE u.email = :email',
            ['email' => $user->getEmail()],
        );
        self::assertCount(1, $rows, 'Expected exactly one outstanding verification token for this account.');

        return $rows[0]['selector'];
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'Precondition failed: sign-in itself did not authenticate.',
        );
    }

    /**
     * Signs in and follows both redirects (app_login -> app_home -> the
     * role's dashboard, Task 20) so the returned crawler is the actual
     * dashboard page, carrying the real CSRF-protected logout form -- same
     * technique as Task 19's `LogoutAndSessionRegenerationTest`.
     */
    private function signInAndReachDashboard(User $user): Crawler
    {
        $this->signIn($user);

        $this->client->followRedirect();

        return $this->client->followRedirect();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup of
        // everything else -- see the class docblock for why (AuthEventRecorder's
        // separate physical connection needs this row visible).
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }
}
