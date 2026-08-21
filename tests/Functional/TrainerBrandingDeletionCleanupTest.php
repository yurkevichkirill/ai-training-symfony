<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\AccountLifecycleService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Task 31: the flagged Risk ("logo file not cleaned up by S2's GDPR
 * deletion path"), proven by test -- a trainer with an uploaded logo has
 * that file deleted from disk and `logo_key` nulled by
 * `AccountLifecycleService::delete()`, alongside the existing `photoKey`
 * assertion (`AccountLifecycleFlowTest::testDeletingAUserRemovesTheirPhotoFileFromDisk()`)
 * continuing to pass unedited.
 */
final class TrainerBrandingDeletionCleanupTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AccountLifecycleService $lifecycleService;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->lifecycleService = self::getContainer()->get(AccountLifecycleService::class);
    }

    /**
     * Deliberately no wrapping transaction -- same reason as
     * `AccountLifecycleFlowTest`: `AccountLifecycleService` records an
     * `AccountEvent` through its own independent physical connection, which
     * must be able to see already-committed fixture rows.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_deletion_log WHERE subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testDeletingATrainerRemovesTheirLogoFileFromDiskAndNullsTheColumn(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $profile = new ProfileTrainer($trainer, 'Elite Academy');

        $uploadsDir = self::getContainer()->getParameter('app.uploads_dir');
        \assert(\is_string($uploadsDir));
        $logoKey = 'branding/'.bin2hex(random_bytes(16)).'.png';
        $logoPath = $uploadsDir.'/'.$logoKey;
        @mkdir(\dirname($logoPath), 0775, true);
        file_put_contents($logoPath, 'fake-logo-bytes');
        self::assertFileExists($logoPath);

        $profile->setLogoKey($logoKey);
        $this->em->persist($profile);
        $this->em->flush();

        $this->lifecycleService->delete($trainer, $admin, null);

        self::assertFileDoesNotExist($logoPath);

        $this->em->clear();
        $freshProfile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $freshProfile);
        self::assertNull($freshProfile->getLogoKey());
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
