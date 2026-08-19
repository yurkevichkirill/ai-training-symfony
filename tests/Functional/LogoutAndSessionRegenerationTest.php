<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Proves AC-6 (logout invalidates the session server-side, so a replayed
 * pre-logout session identifier is refused) and the sign-in half of AC-8
 * (the session identifier is regenerated on sign-in, so a value observed
 * beforehand cannot be used afterward).
 *
 * Both halves are proof of behaviour already configured elsewhere -- Task
 * 12's `logout: { invalidate_session: true }` and `form_login`'s own session
 * migration -- not new production code. See each test's docblock for what it
 * is actually pinning down.
 */
final class LogoutAndSessionRegenerationTest extends WebTestCase
{
    /**
     * `when@test.framework.session.storage_factory_id` is
     * `session.storage.factory.mock_file`, whose configured cookie name is
     * `MOCKSESSID` (confirmed against the compiled container, not assumed --
     * the default for `NativeSessionStorage` would have been `PHPSESSID`).
     */
    private const SESSION_COOKIE_NAME = 'MOCKSESSID';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Same reasoning as SignInTest/SessionIdleExpiryTest: without this the
        // kernel reboots between requests, so each request gets a fresh
        // Doctrine connection that cannot see the uncommitted fixture row.
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

        // AuthEventRecorder (Task 34) writes LOGIN_SUCCEEDED/LOGGED_OUT rows
        // through its own, genuinely separate physical connection -- see
        // its class docblock -- so they are not covered by the rollback
        // above, the same reason persist() below commits its fixture user
        // instead of leaving it to the rollback.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * AC-6. Destroying the session server-side (`invalidate_session: true`,
     * Task 12) is what makes a replayed identifier resolve to an empty,
     * unauthenticated session rather than merely an expired-looking one that
     * still happens to authenticate. The CSRF token submitted for `/logout`
     * is the real one rendered on the dashboard page (Task 20's template),
     * not a hand-built value, so this also exercises the logout route's own
     * CSRF gate (AC-21) rather than assuming it out of the way.
     */
    public function testLogoutInvalidatesTheSessionSoAReplayedCookieIsRefused(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        $dashboard = $this->signInAndReachDashboard($user);

        $preLogoutCookie = $this->client->getCookieJar()->get(self::SESSION_COOKIE_NAME);
        self::assertNotNull(
            $preLogoutCookie,
            'Precondition failed: sign-in left no session cookie to capture.',
        );

        $this->logout($dashboard);

        // Replay the pre-logout identifier, as an attacker who captured the
        // cookie before the legitimate user signed out would.
        $this->client->getCookieJar()->set($preLogoutCookie);

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
        self::assertNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A replayed pre-logout session identifier must not authenticate a request.',
        );
    }

    /**
     * AC-8, sign-in half. A value observed before authentication must not be
     * usable afterward.
     *
     * No route in this app actually hands an anonymous visitor a session
     * cookie -- `/login`'s CSRF is stateless (csrf.yaml) and the template has
     * no flash messages to read on a first visit, so GET /login alone never
     * starts a PHP session (confirmed empirically: no Set-Cookie header).
     * `startAnonymousSessionAndCaptureId()` manufactures the pre-sign-in
     * session the same way the framework would -- via the request's own
     * `SessionInterface`, backed by the same `mock_file` storage every real
     * request uses -- and injects its cookie into the client's jar the way a
     * real Set-Cookie response header would have. That is the "value
     * observed before authentication" AC-8 is about; what is under test is
     * only what happens to it at sign-in.
     */
    public function testSignInRegeneratesTheSessionIdentifier(): void
    {
        $preSignInSessionId = $this->startAnonymousSessionAndCaptureId();

        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($user);

        $postSignInSessionId = $this->client->getRequest()->getSession()->getId();

        self::assertNotSame(
            $preSignInSessionId,
            $postSignInSessionId,
            'form_login must regenerate the session identifier on sign-in.',
        );
    }

    /**
     * Starts a real session on the current (anonymous) request and saves it,
     * then hands its cookie to the client's jar exactly as a Set-Cookie
     * response header would, so the *next* request presents it as an
     * already-established session -- without any route in this app ever
     * issuing one on its own for an anonymous visitor.
     */
    private function startAnonymousSessionAndCaptureId(): string
    {
        $this->client->request('GET', '/login');

        $session = $this->client->getRequest()->getSession();
        $session->start();
        $session->save();

        $this->client->getCookieJar()->set(
            new Cookie($session->getName(), $session->getId(), null, '/', 'localhost'),
        );

        return $session->getId();
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
     * Signs in and follows both redirects (app_login -> app_home ->
     * the role's dashboard, Task 20) so the returned crawler is the actual
     * dashboard page, carrying the real CSRF-protected logout form.
     */
    private function signInAndReachDashboard(User $user): Crawler
    {
        $this->signIn($user);

        $this->client->followRedirect();

        return $this->client->followRedirect();
    }

    private function logout(Crawler $dashboard): void
    {
        $this->client->submit($dashboard->selectButton('Sign out')->form());

        self::assertResponseRedirects('/login');
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
        // LOGGED_OUT both reference the signed-in user by FK, and an
        // uncommitted row is invisible across connections regardless of
        // how deeply nested the enclosing transaction is (Postgres
        // transaction isolation, not a Doctrine limitation).
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }
}
