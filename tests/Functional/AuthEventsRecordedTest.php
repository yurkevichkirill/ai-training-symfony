<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuthEvent;
use App\Entity\User;
use App\Enum\AuthEventType;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * End-to-end proof of AC-24: a real sign-in failure, a real sign-in success,
 * and a real logout must each leave an `auth_event` row of the right type,
 * and none of the secret material that necessarily passed through those
 * requests (the real password, the attempted wrong password, the session
 * identifier issued at sign-in) may appear anywhere in the persisted rows --
 * proven by direct inspection of the raw database row, not by re-reading
 * `AuthEventRecord`'s source and trusting it stays narrow. Task 35.
 *
 * Follows SignInTest/LogoutAndSessionRegenerationTest's conventions: GET
 * /login before every POST (stateless CSRF needs same-origin headers that
 * BrowserKit only sends once its history is non-empty), `disableReboot()`
 * so the fixture user stays visible to the app's own connection, and the
 * fixture committed immediately (then a fresh transaction reopened for
 * rollback-based cleanup of everything else) because AuthEventRecorder
 * writes through a genuinely separate physical connection whose FK to
 * `app_user` needs a durably committed row.
 */
final class AuthEventsRecordedTest extends WebTestCase
{
    /**
     * Same as LogoutAndSessionRegenerationTest -- confirmed against the
     * compiled container's `when@test` mock_file session storage, not the
     * `NativeSessionStorage` default.
     */
    private const SESSION_COOKIE_NAME = 'MOCKSESSID';

    /**
     * Deliberately distinctive (not "password123" or similar) so a match
     * anywhere in a persisted row is unambiguous evidence of a leak, not a
     * coincidental substring of something unrelated.
     */
    private const WRONG_PASSWORD = 'Wr0ng-P@ssphrase-For-AuditTest-7f2b9e1d4a';

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

        // AuthEventRecorder (Task 34) writes every row in this test through
        // its own, genuinely separate physical connection -- not covered by
        // the rollback above.
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement(
                'DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email) OR identifier_attempted = :email',
                ['email' => $email],
            );
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testSignInFailureSuccessAndLogoutAreRecordedWithoutSecretMaterial(): void
    {
        $user = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));

        // 1. Wrong password -- LOGIN_FAILED.
        $this->submitLogin($user->getEmail(), self::WRONG_PASSWORD);
        self::assertResponseRedirects();
        self::assertNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'Precondition failed: the wrong-password attempt must not authenticate.',
        );

        // 2. Correct password -- LOGIN_SUCCEEDED.
        $this->submitLogin($user->getEmail(), UserFactory::PASSWORD);
        self::assertResponseRedirects();
        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            'Precondition failed: the correct-password attempt must authenticate.',
        );

        // form_login regenerates the session id at sign-in (AC-8), so the
        // secret worth checking for is the one issued *after* signing in,
        // which is the one that would actually be usable to hijack the
        // session if it leaked.
        $sessionCookie = $this->client->getCookieJar()->get(self::SESSION_COOKIE_NAME);
        self::assertNotNull($sessionCookie, 'Precondition failed: sign-in left no session cookie to capture.');
        $sessionId = (string) $sessionCookie->getValue();
        self::assertNotSame('', $sessionId, 'Precondition failed: captured session id is empty.');

        // app_login -> app_home -> the role's own dashboard (Task 20).
        $this->client->followRedirect();
        $dashboard = $this->client->followRedirect();

        // 3. Logout -- LOGGED_OUT. Uses the dashboard's own rendered
        // CSRF-protected logout form, not a hand-built request.
        $this->client->submit($dashboard->selectButton('Sign out')->form());
        self::assertResponseRedirects('/login');

        // Now inspect what actually got persisted.
        $this->em->clear();

        $rows = $this->em->getRepository(AuthEvent::class)->findBy(
            ['user' => $user],
            ['occurredAt' => 'ASC'],
        );

        self::assertCount(3, $rows, 'Expected exactly one auth_event row each for the failed attempt, the success, and the logout.');
        self::assertSame(
            [
                AuthEventType::LOGIN_FAILED->value,
                AuthEventType::LOGIN_SUCCEEDED->value,
                AuthEventType::LOGGED_OUT->value,
            ],
            array_map(static fn (AuthEvent $event): string => $event->getType(), $rows),
        );

        $secrets = [
            'test password' => UserFactory::PASSWORD,
            'wrong password' => self::WRONG_PASSWORD,
            'session id' => $sessionId,
        ];

        $connection = $this->em->getConnection();

        foreach ($rows as $row) {
            // Read the raw row directly via DBAL -- every column, not just
            // the ones AuthEvent happens to expose a getter for today -- so
            // a secret leaking into a column this test's authors did not
            // think to check explicitly (e.g. identifierAttempted) is still
            // caught.
            $rawRow = $connection->fetchAssociative(
                'SELECT * FROM auth_event WHERE id = :id',
                ['id' => $row->getId()->toRfc4122()],
            );

            self::assertIsArray($rawRow, 'Precondition failed: could not re-read the persisted row via raw SQL.');

            $serialized = json_encode($rawRow, \JSON_THROW_ON_ERROR);

            foreach ($secrets as $label => $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $serialized,
                    \sprintf(
                        'auth_event row %s (%s) contains the %s -- AC-24 requires no password or token material in the audit trail. Row: %s',
                        $row->getId()->toRfc4122(),
                        $row->getType(),
                        $label,
                        $serialized,
                    ),
                );
            }
        }
    }

    private function submitLogin(string $identifier, string $password): Crawler
    {
        $crawler = $this->client->request('GET', '/login');

        return $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $identifier,
            '_password' => $password,
        ]));
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        // Committed immediately, then a fresh transaction reopened for the
        // rest of this test to keep relying on for rollback-based cleanup
        // of everything else -- same reasoning as SignInTest/
        // LogoutAndSessionRegenerationTest: AuthEventRecorder's own physical
        // connection cannot see an uncommitted fixture row.
        $connection = $this->em->getConnection();
        $connection->commit();
        $connection->beginTransaction();

        return $user;
    }
}
