<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerShareLink;
use App\Entity\ProfileTrainer;
use App\Entity\TrainerCoachAssociation;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 27: `GET /branding/logo/{trainerId}` and `GET /join/{code}/logo`
 * authorization (AC-6, AC-7, NFR-002).
 */
final class TrainerBrandingLogoReadTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uploadsDir;

    /** @var list<string> */
    private array $persistedUserIds = [];

    /** @var list<string> */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = self::getContainer()->getParameter('app.uploads_dir');
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM profile WHERE type = 'TRAINER'");

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testAnAssociatedPlayerGets200WithImageBytes(): void
    {
        $trainer = $this->trainerWithLogo();
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-player')));
        $this->persistPlayerAssociation($trainer, $player);

        $this->client->loginUser($player);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testAnAssociatedCoachGets200WithImageBytes(): void
    {
        $trainer = $this->trainerWithLogo();
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('logo-read-coach')));
        $association = new TrainerCoachAssociation($trainer, $coach, null);
        $this->em->persist($association);
        $this->em->flush();

        $this->client->loginUser($coach);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testTheParentOfAnAssociatedChildGets200WithImageBytes(): void
    {
        $trainer = $this->trainerWithLogo();
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-child')));

        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->persistPlayerAssociation($trainer, $childUser);

        $this->client->loginUser($parent);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testTheTrainerThemselfGets200WithImageBytes(): void
    {
        $trainer = $this->trainerWithLogo();

        $this->client->loginUser($trainer);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testASuperAdminGets200WithImageBytes(): void
    {
        $trainer = $this->trainerWithLogo();
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN, UserFactory::email('logo-read-admin')));

        $this->client->loginUser($admin);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testAnUnassociatedPlayerGets403(): void
    {
        $trainer = $this->trainerWithLogo();
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-unassoc')));

        $this->client->loginUser($player);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnassociatedTrainerGets403(): void
    {
        $trainer = $this->trainerWithLogo();
        $otherTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('logo-read-other-trainer')));

        $this->client->loginUser($otherTrainer);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAnonymousRequestRedirectsToLogin(): void
    {
        $trainer = $this->trainerWithLogo();

        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testATrainerWithNoLogoGives404ForAnAssociatedPlayer(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('logo-read-nologo')));
        $profile = new ProfileTrainer($trainer, 'No Logo Academy');
        $this->em->persist($profile);
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-nologo-player')));
        $this->persistPlayerAssociation($trainer, $player);

        $this->client->loginUser($player);
        $this->client->request('GET', '/branding/logo/'.$trainer->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Task 36 (security review): a `{trainerId}` that is not a UUID at all
     * must be a 404, not an uncaught `\InvalidArgumentException` from
     * `Uuid::fromString()` surfacing as a 500 -- a malformed id is an
     * unknown logo, and an authenticated user must not be able to provoke a
     * server error (and a dev-environment stack trace) with one request.
     */
    public function testAMalformedTrainerIdGives404NotA500(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('logo-read-malformed')));

        $this->client->loginUser($player);
        $this->client->request('GET', '/branding/logo/not-a-uuid');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testJoinCodeLogoSucceedsAnonymouslyForThatCodesTrainer(): void
    {
        $trainer = $this->trainerWithLogo();
        $link = $this->createLinkFor($trainer);

        $this->client->request('GET', '/join/'.$link->getCode().'/logo');

        self::assertResponseIsSuccessful();
    }

    public function testJoinCodeLogo404sForAnUnknownCode(): void
    {
        $this->client->request('GET', '/join/does-not-exist-code/logo');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testJoinCodeLogo404sForARevokedTrainer(): void
    {
        // A ShareLink whose trainer has since been deactivated is treated by
        // `PlayerShareLinkResolver` the same as an unknown code (S3's fact).
        $trainer = $this->trainerWithLogo();
        $link = $this->createLinkFor($trainer);

        $trainer->setStatus(\App\Enum\UserStatus::DEACTIVATED);
        $this->em->flush();

        $this->client->request('GET', '/join/'.$link->getCode().'/logo');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function trainerWithLogo(): User
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('logo-read-trainer')));
        $profile = new ProfileTrainer($trainer, 'Elite Academy');

        $key = \sprintf('branding/%s.png', bin2hex(random_bytes(16)));
        $path = $this->uploadsDir.'/'.$key;
        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, 'fake-png-bytes');
        $this->writtenFiles[] = $path;

        $profile->setLogoKey($key);
        $this->em->persist($profile);
        $this->em->flush();

        return $trainer;
    }

    private function persistPlayerAssociation(User $trainer, User $player): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->getOrCreateFor($trainer);
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
