<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\UniformAuthenticationFailureHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * End-to-end proof of the sign-in gate (AC-1, AC-2, AC-3).
 *
 * Note every case GETs /login before posting. That is not ceremony: stateless
 * CSRF validates same-origin via Sec-Fetch-Site/Origin/Referer, and BrowserKit
 * only sends a Referer once its history is non-empty
 * (vendor/symfony/browser-kit/AbstractBrowser.php:356). A bare POST would be
 * rejected as a CSRF failure and every assertion here would pass for the wrong
 * reason. See the Task 2 config verification notes.
 */
final class SignInTest extends WebTestCase
{
    private const DASHBOARD_PATHS = [
        'ROLE_SUPER_ADMIN' => '/admin',
        'ROLE_TRAINER' => '/trainer',
        'ROLE_COACH' => '/coach',
        'ROLE_PLAYER' => '/player',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Without this the kernel is rebooted between requests, so each request
        // gets a fresh Doctrine connection that cannot see the uncommitted
        // fixture rows -- every sign-in would fail as "unknown account" and the
        // failure assertions would all pass for entirely the wrong reason.
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
     * AC-3: all four roles authenticate through the same /login route. There is
     * no role-specific sign-in path to get wrong.
     */
    #[DataProvider('roles')]
    public function testEveryRoleSignsInThroughTheSameRoute(UserRole $role): void
    {
        $user = $this->persist(UserFactory::activeVerified($role));

        $this->submitLogin($user->getEmail(), UserFactory::PASSWORD);

        self::assertResponseRedirects();
        self::assertNotSame(
            '/login',
            parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH),
            \sprintf('%s was bounced back to the login form.', $role->value),
        );
        self::assertNotNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            \sprintf('%s should be authenticated after a correct sign-in.', $role->value),
        );

        // Task 20 has landed, so this asserts the whole way through: / forwards
        // to the role's own dashboard. RoleLandingTest owns the mapping itself.
        $this->client->followRedirect();
        self::assertResponseRedirects(self::DASHBOARD_PATHS[$role->value]);
    }

    /**
     * AC-1, AC-2: the four failure causes must be indistinguishable in the
     * response. Asserted against each other, not against a fixed expectation.
     */
    public function testTheFourFailureCausesAreIndistinguishable(): void
    {
        $signatures = [];

        foreach ($this->failureCases() as $label => [$identifier, $password]) {
            $this->client->restart();
            $this->submitLogin($identifier, $password);
            $response = $this->client->getResponse();

            $signatures[$label] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'flash' => $this->renderedFlashes(),
            ];
        }

        self::assertCount(4, $signatures);
        self::assertCount(
            1,
            array_unique(array_map(serialize(...), $signatures)),
            'Sign-in failures are distinguishable: '.print_r($signatures, true),
        );
        self::assertSame(
            [UniformAuthenticationFailureHandler::FAILURE_MESSAGE],
            reset($signatures)['flash'],
        );
    }

    #[DataProvider('failureLabels')]
    public function testNoFailureCauseAuthenticates(string $label): void
    {
        [$identifier, $password] = $this->failureCases()[$label];

        $this->submitLogin($identifier, $password);

        self::assertNull(
            self::getContainer()->get('security.token_storage')->getToken(),
            \sprintf('"%s" must not produce an authenticated session.', $label),
        );
    }

    /**
     * The timing half of AC-2 (the message half is asserted above).
     *
     * An unknown address must not return measurably faster than a known one:
     * the known-account path pays for a password verification, so without
     * LoginTimingPaddingSubscriber the unknown path would be the quick one and
     * the clock would answer a question the response text refuses to.
     *
     * Tolerance is deliberately generous. This runs on shared CI hardware where
     * scheduling noise dwarfs the effect being measured, and a tight bound would
     * produce flakes that get muted rather than triaged. What it must catch is
     * the padding being removed entirely -- an order-of-magnitude gap, not a
     * few percent. Medians rather than means, so one descheduled iteration
     * cannot move the result.
     */
    public function testFailureCausesDoNotDifferMeasurablyInTiming(): void
    {
        $iterations = 30;
        $medians = [];

        foreach ($this->failureCases() as $label => [$identifier, $password]) {
            $samples = [];

            for ($i = 0; $i < $iterations; ++$i) {
                $this->client->restart();
                $started = hrtime(true);
                $this->submitLogin($identifier, $password);
                $samples[] = hrtime(true) - $started;
            }

            sort($samples);
            $medians[$label] = $samples[intdiv($iterations, 2)];
        }

        $fastest = min($medians);
        $slowest = max($medians);

        self::assertGreaterThan(0, $fastest);
        self::assertLessThan(
            3.0,
            $slowest / $fastest,
            \sprintf(
                'Sign-in failure timing differs across causes by more than 3x, which is a usable enumeration signal. Medians (ns): %s',
                json_encode($medians),
            ),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function failureCases(): array
    {
        $wrongPassword = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, 'wrong-password@example.test'));
        $deactivated = $this->persist(UserFactory::deactivated(UserRole::COACH, 'deactivated@example.test'));
        $unverified = $this->persist(UserFactory::activeUnverified(UserRole::TRAINER, 'unverified@example.test'));

        return [
            'wrong password' => [$wrongPassword->getEmail(), 'not-the-right-password'],
            'unknown email' => ['nobody@example.test', UserFactory::PASSWORD],
            'deactivated account' => [$deactivated->getEmail(), UserFactory::PASSWORD],
            'unverified account' => [$unverified->getEmail(), UserFactory::PASSWORD],
        ];
    }

    private function submitLogin(string $identifier, string $password): Crawler
    {
        $crawler = $this->client->request('GET', '/login');

        return $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $identifier,
            '_password' => $password,
        ]));
    }

    /**
     * @return list<string>
     */
    private function renderedFlashes(): array
    {
        $crawler = $this->client->followRedirect();

        return $crawler->filter('[role="alert"]')->each(static fn (Crawler $node): string => trim($node->text()));
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function roles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failureLabels(): iterable
    {
        foreach (['wrong password', 'unknown email', 'deactivated account', 'unverified account'] as $label) {
            yield $label => [$label];
        }
    }
}
