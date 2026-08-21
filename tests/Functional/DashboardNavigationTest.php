<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Each role's dashboard renders its expected nav links, and the Player
 * dashboard hides "My family" for a child account while showing it for an
 * adult player -- `PlayerDashboardController` passes `isChild` from
 * `ChildAccountResolver` for exactly that purpose.
 */
final class DashboardNavigationTest extends WebTestCase
{
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

    public function testAdminDashboardShowsItsNavLinks(): void
    {
        $this->loginAs(UserRole::SUPER_ADMIN);

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $this->assertRoutesPresent($crawler, [
            'admin_users_index',
            'admin_users_create',
            'admin_impersonation_history',
        ]);
    }

    public function testTrainerDashboardShowsItsNavLinks(): void
    {
        $this->loginAs(UserRole::TRAINER);

        $crawler = $this->client->request('GET', '/trainer');

        self::assertResponseIsSuccessful();
        $this->assertRoutesPresent($crawler, [
            'app_trainer_players',
            'app_trainer_coaches',
            'app_trainer_share_link',
            'app_profile_edit',
            'app_trainer_branding',
        ]);
    }

    public function testCoachDashboardShowsItsNavLinks(): void
    {
        $this->loginAs(UserRole::COACH);

        $crawler = $this->client->request('GET', '/coach');

        self::assertResponseIsSuccessful();
        $this->assertRoutesPresent($crawler, [
            'app_coach_availability',
            'app_profile_edit',
        ]);
    }

    public function testAdultPlayerDashboardShowsTheFamilyLink(): void
    {
        $this->loginAs(UserRole::PLAYER);

        $crawler = $this->client->request('GET', '/player');

        self::assertResponseIsSuccessful();
        $this->assertRoutesPresent($crawler, [
            'app_player_trainers',
            'app_player_availability',
            'app_profile_edit',
            'app_family_index',
        ]);
    }

    public function testChildPlayerDashboardHidesTheFamilyLink(): void
    {
        $parent = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent'));
        $childUser = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child'));
        $this->em->persist($parent);
        $this->em->persist($childUser);
        $this->em->persist(new ChildAccount($childUser, $parent));
        $this->em->flush();

        $this->client->loginUser($childUser);
        $crawler = $this->client->request('GET', '/player');

        self::assertResponseIsSuccessful();
        $this->assertRoutesPresent($crawler, [
            'app_player_trainers',
            'app_player_availability',
            'app_profile_edit',
        ]);

        $familyPath = $this->client->getContainer()->get('router')->generate('app_family_index');
        self::assertSame(
            0,
            $crawler->filter(\sprintf('a[href="%s"]', $familyPath))->count(),
            'A child account must not see the "My family" link.',
        );
    }

    private function loginAs(UserRole $role): User
    {
        $user = UserFactory::activeVerified($role);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    /**
     * @param list<string> $routeNames
     */
    private function assertRoutesPresent(\Symfony\Component\DomCrawler\Crawler $crawler, array $routeNames): void
    {
        $router = $this->client->getContainer()->get('router');

        foreach ($routeNames as $routeName) {
            $path = $router->generate($routeName);
            self::assertGreaterThan(
                0,
                $crawler->filter(\sprintf('a[href="%s"]', $path))->count(),
                \sprintf('Expected a link to route "%s" (%s).', $routeName, $path),
            );
        }
    }
}
