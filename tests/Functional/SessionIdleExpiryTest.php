<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Proves the deterministic half of AC-7: SessionIdleSubscriber ends a session
 * once more than `%app.session_idle_seconds%` has elapsed since the last
 * request, without waiting on `gc_maxlifetime`'s probabilistic sweep.
 *
 * The age is forced by writing directly into the session's `_last_activity`
 * value between requests, not by sleeping for 8 hours -- see the class
 * docblock on SessionIdleSubscriber for why the session must be read from the
 * bag directly rather than from a fresh request.
 */
final class SessionIdleExpiryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $idleSeconds;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Same reasoning as SignInTest: without this the kernel reboots
        // between requests and each one gets a fresh Doctrine connection that
        // cannot see the uncommitted fixture row.
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->idleSeconds = self::getContainer()->getParameter('app.session_idle_seconds');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // AuthEventRecorder (Task 34) writes LOGIN_SUCCEEDED rows through
        // its own, genuinely separate physical connection -- see its class
        // docblock -- so they are not covered by the rollback above, the
        // same reason signIn() below commits its fixture user instead of
        // leaving it to the rollback.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * The core AC-7 assertion: a session idle for longer than the configured
     * threshold cannot be used to reach a protected route, even on the very
     * request that would otherwise have been served by it.
     */
    public function testSessionPastTheIdleThresholdIsUnauthenticated(): void
    {
        $this->signIn(UserFactory::activeVerified(UserRole::PLAYER));

        $this->ageLastActivityBy($this->idleSeconds + 1);

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
        self::assertNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A session idle past the threshold must not authenticate the request that discovers it.',
        );
    }

    /**
     * Negative control: without this, testSessionPastTheIdleThresholdIsUnauthenticated
     * could pass merely because the subscriber invalidates every session it
     * touches, threshold or not.
     */
    public function testSessionWithinTheIdleThresholdStaysAuthenticated(): void
    {
        $this->signIn(UserFactory::activeVerified(UserRole::PLAYER));

        // Comfortably inside the window, not merely "not yet over" it.
        $this->ageLastActivityBy(intdiv($this->idleSeconds, 2));

        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertNotSame(
            '/login',
            parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH),
            'A session inside the idle threshold was bounced back to the login form.',
        );
        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A session inside the idle threshold must stay authenticated.',
        );
    }

    /**
     * The rolling half of the mechanism: activity inside the window must push
     * the deadline forward rather than only ever being checked against the
     * timestamp stamped at sign-in.
     */
    public function testActivityInsideTheWindowResetsTheClock(): void
    {
        $this->signIn(UserFactory::activeVerified(UserRole::PLAYER));

        // Two requests, each just under the threshold apart. Naively summed
        // this exceeds the idle window; it must not, because each request
        // resets "now" as the new last-activity.
        $this->ageLastActivityBy($this->idleSeconds - 5);
        $this->client->request('GET', '/');
        self::assertNotNull(self::getContainer()->get('security.token_storage')->getToken());

        $this->ageLastActivityBy($this->idleSeconds - 5);
        $this->client->request('GET', '/');

        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'A request inside the window must reset _last_activity, not just check the original stamp.',
        );
    }

    private function signIn(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup
        // of everything else. AuthEventRecorder's own physical connection
        // (Task 34) cannot see this row otherwise -- LOGIN_SUCCEEDED
        // references the signed-in user by FK, and an uncommitted row is
        // invisible across connections regardless of how deeply nested the
        // enclosing transaction is (Postgres transaction isolation, not a
        // Doctrine limitation).
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

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
     * Manipulates the already-authenticated session's `_last_activity`
     * directly, standing in for "this many seconds of real inactivity
     * elapsed" without sleeping the test.
     *
     * `getRequest()->getSession()` returns the exact Session/storage instance
     * the last request used, already keyed to the cookie the client is
     * holding; MockFileSessionStorage (the `when@test` storage_factory_id) is
     * file-backed, so this write is visible to the next request exactly as a
     * real elapsed-time change to `_last_activity` would have been.
     */
    private function ageLastActivityBy(int $seconds): void
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(SessionInterface::class, $session);

        $session->set('_last_activity', time() - $seconds);
        $session->save();
    }
}
