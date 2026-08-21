<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 25: `/trainer/branding`'s settings page and access rules (AC-1,
 * AC-2, BR-001).
 */
final class TrainerBrandingSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM profile WHERE type = 'TRAINER'");

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testASignedInTrainerReachesTheBrandingPageAc1(): void
    {
        $trainer = $this->persistTrainer();
        $this->signIn($trainer);

        $crawler = $this->client->request('GET', '/trainer/branding');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[type="color"]')->count(), 'The color picker input must render.');
        self::assertGreaterThan(0, $crawler->filter('input[type="file"]')->count(), 'The logo upload control must render.');
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function nonTrainerRoles(): iterable
    {
        yield 'player' => [UserRole::PLAYER];
        yield 'coach' => [UserRole::COACH];
    }

    #[DataProvider('nonTrainerRoles')]
    public function testAPlayerCoachOrParentIsRefused403OnTheSettingsPageAc2(UserRole $role): void
    {
        $user = $this->persist(UserFactory::activeVerified($role, UserFactory::email('branding-refused')));
        $this->signIn($user);

        $this->client->request('GET', '/trainer/branding');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAParentIsRefused403OnTheSettingsPageAc2(): void
    {
        // A "parent" in this codebase is simply a PLAYER account that has
        // created a ChildAccount -- covered by the PLAYER case above; this
        // test asserts the same refusal explicitly for the scenario named
        // in the plan.
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('branding-parent')));
        $this->signIn($parent);

        $this->client->request('GET', '/trainer/branding');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[DataProvider('nonTrainerRoles')]
    public function testAForgedPostToEachWriteRouteIsRefused403(UserRole $role): void
    {
        $trainer = $this->persistTrainer();
        $user = $this->persist(UserFactory::activeVerified($role, UserFactory::email('branding-forged')));
        $this->signIn($user);

        $this->client->request('POST', '/trainer/branding', ['trainer_branding_form' => ['primaryColorHex' => '#ff8800']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/trainer/branding/logo');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/trainer/branding/reset');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * The voter, not the role attribute, carries the admin allowance: the
     * class-level `#[IsGranted('ROLE_TRAINER')]` refuses a Super Admin
     * hitting the self-service route directly, even though
     * `BrandingVoter::EDIT_BRANDING` would grant them access to a *named*
     * trainer's branding through a different entry point.
     */
    public function testASuperAdminHittingTheSelfServiceRouteDirectlyIsRefused(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN, UserFactory::email('branding-admin')));
        $this->signIn($admin);

        $this->client->request('GET', '/trainer/branding');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testADeactivatedTrainerIsRefused(): void
    {
        $trainer = UserFactory::deactivated(UserRole::TRAINER, UserFactory::email('branding-deactivated'));
        $this->em->persist($trainer);
        $profile = new ProfileTrainer($trainer, 'Deactivated Academy');
        $this->em->persist($profile);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $trainer->getId();

        // A deactivated account cannot authenticate through the real login
        // form at all (S1's own rule) -- `loginUser()` bypasses that
        // authentication step to isolate this voter's own deactivated-target
        // clause from the firewall's separate refusal.
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEveryWriteRouteRefusesAMissingCsrfToken(): void
    {
        $trainer = $this->persistTrainer();
        $this->signIn($trainer);

        // No _token in the request at all.
        $this->client->request('POST', '/trainer/branding/logo');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/trainer/branding/reset');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEveryWriteRouteRefusesAnIncorrectCsrfToken(): void
    {
        $trainer = $this->persistTrainer();
        $this->signIn($trainer);

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => 'not-the-real-token']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/trainer/branding/reset', ['_token' => 'not-the-real-token']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function persistTrainer(): User
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('branding-trainer')));
        $profile = new ProfileTrainer($trainer, 'Elite Academy');
        $this->em->persist($profile);
        $this->em->flush();

        return $trainer;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));
    }
}
