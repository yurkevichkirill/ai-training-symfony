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
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 36 (AC-1): `PhotoController::show()`'s owner-or-Super-Admin rule
 * gains one clause -- a parent may read their own child's photo via
 * `FamilyVoter::MANAGE_CHILD`. Without it, AC-1's optional child photo would
 * be uploadable and never viewable by anyone but a Super Admin.
 */
final class PhotoControllerFamilyAccessTest extends WebTestCase
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

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * @see AC-1 a parent can view their own child's photo
     */
    public function testAParentCanViewTheirOwnChildsPhotoAc1(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-child')));
        $this->persistChildAccount($childUser, $parent);
        $this->givePhoto($childUser);

        $this->client->loginUser($parent);
        $this->client->request('GET', '/photos/'.$childUser->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    /**
     * A malformed {userId} must 404, not crash `Uuid::fromString()` into an
     * uncaught 500 (the same sibling bug S7's review found and fixed in
     * `BrandingLogoController`).
     */
    public function testAMalformedUserIdGives404NotA500(): void
    {
        $viewer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-malformed-viewer')));

        $this->client->loginUser($viewer);
        $this->client->request('GET', '/photos/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * An unrelated player is still refused a child's photo -- MANAGE_CHILD
     * only grants the actual parent.
     */
    public function testAnUnrelatedUserCannotViewAChildsPhoto(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-parent-b')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-child-b')));
        $this->persistChildAccount($childUser, $parent);
        $this->givePhoto($childUser);

        $unrelated = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-unrelated')));

        $this->client->loginUser($unrelated);
        $this->client->request('GET', '/photos/'.$childUser->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function givePhoto(User $user): void
    {
        $key = \sprintf('test/%s.jpg', bin2hex(random_bytes(8)));
        $path = $this->uploadsDir.'/'.$key;

        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, 'fake-jpg-bytes');
        $this->writtenFiles[] = $path;

        $user->setPhotoKey($key);
        $this->em->flush();
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
