<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-16: each role lands on its own dashboard.
 * AC-17: each role is refused the others' dashboards.
 */
final class RoleLandingTest extends WebTestCase
{
    private const DASHBOARDS = [
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

    #[DataProvider('roles')]
    public function testEachRoleLandsOnItsOwnDashboard(UserRole $role): void
    {
        $this->signIn($role);

        $this->client->request('GET', '/');

        self::assertResponseRedirects(self::DASHBOARDS[$role->value]);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    /**
     * The two halves are asserted in one test on purpose. Split apart, "no link
     * is rendered" and "the URL is refused" can each pass while the other
     * silently regresses -- and a UI that merely hides the link is not
     * enforcement at all (AC-17).
     */
    #[DataProvider('foreignDashboards')]
    public function testAForeignDashboardIsRefusedAndUnlinked(UserRole $role, string $foreignPath): void
    {
        $this->signIn($role);

        $ownPage = $this->client->request('GET', self::DASHBOARDS[$role->value]);
        self::assertResponseIsSuccessful();

        $links = $ownPage->filter(\sprintf('a[href="%s"]', $foreignPath))->count();
        self::assertSame(0, $links, \sprintf('%s dashboard links to %s.', $role->value, $foreignPath));

        $this->client->request('GET', $foreignPath);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            \sprintf('%s reached %s by typing the URL, so the missing link was the only thing stopping them.', $role->value, $foreignPath),
        );
    }

    /**
     * The catch-all access_control rule, seen from the anonymous side.
     */
    public function testAnAnonymousVisitorIsSentToTheLoginForm(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function signIn(UserRole $role): User
    {
        $user = UserFactory::activeVerified($role);
        $this->em->persist($user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

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
     * Every role against every dashboard that is not its own -- twelve pairs,
     * so no combination is left to chance.
     *
     * @return iterable<string, array{UserRole, string}>
     */
    public static function foreignDashboards(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach (self::DASHBOARDS as $ownerRole => $path) {
                if ($ownerRole !== $role->value) {
                    yield \sprintf('%s -> %s', $role->value, $path) => [$role, $path];
                }
            }
        }
    }
}
