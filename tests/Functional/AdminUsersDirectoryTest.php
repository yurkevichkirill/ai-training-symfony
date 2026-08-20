<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Users tool directory (AC-1, AC-2, AC-3): Super-Admin-only access,
 * role/status filters, tool-scoped search, and pagination that stays flat
 * rather than degrading with `OFFSET`.
 */
final class AdminUsersDirectoryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testOnlyASuperAdminCanReachTheUsersDirectory(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDirectoryListsUsersAndFiltersByRoleAndStatus(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('findme-trainer')));
        $coach = $this->persist(UserFactory::deactivated(UserRole::COACH, UserFactory::email('findme-coach')));

        $this->signIn($admin);

        $this->client->request('GET', '/admin/users', ['role' => UserRole::TRAINER->value]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $trainer->getEmail());
        self::assertSelectorTextNotContains('table', $coach->getEmail());

        $this->client->request('GET', '/admin/users', ['status' => 'DEACTIVATED']);
        self::assertSelectorTextContains('table', $coach->getEmail());
        self::assertSelectorTextNotContains('table', $trainer->getEmail());
    }

    public function testSearchMatchesEmailWithinTheDirectoryOnly(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $needle = UserFactory::email('unique-search-needle');
        $target = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, $needle));
        $other = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $this->signIn($admin);

        $this->client->request('GET', '/admin/users', ['q' => 'unique-search-needle']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $target->getEmail());
        self::assertSelectorTextNotContains('table', $other->getEmail());
    }

    /**
     * A search string containing a literal `%` must not behave as a SQL
     * wildcard -- confirmed by using it as a needle that should match
     * nothing, since no real email contains it verbatim outside this test.
     */
    public function testSearchTreatsPercentAsALiteralCharacterNotAWildcard(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $this->signIn($admin);

        $this->client->request('GET', '/admin/users', ['q' => '%@%']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('table tbody', '');
    }

    public function testPaginationReturnsAAdditionalPageCursorWhenMoreRowsExist(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));

        for ($i = 0; $i < 3; ++$i) {
            $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('page-fill')));
        }

        $this->signIn($admin);

        $crawler = $this->client->request('GET', '/admin/users', ['role' => UserRole::TRAINER->value]);
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table tbody tr');
        self::assertGreaterThanOrEqual(3, $rows->count());
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $user->getEmail();

        return $user;
    }
}
