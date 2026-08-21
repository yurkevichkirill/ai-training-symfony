<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Task 26: logo upload validation and replace/no-logo edge cases (AC-3,
 * AC-4, AC-5, AC-6).
 */
final class TrainerBrandingLogoUploadTest extends WebTestCase
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

    public function testAValidOneMegabytePngSavesAndTheKeyMatchesTheOpaqueShapeAc3(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->pngUploadedFile(1024 * 1024);

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertMatchesRegularExpression('#^branding/[0-9a-f]{32}\.png$#', (string) $profile->getLogoKey());
        $this->writtenFiles[] = $this->uploadsDir.'/'.$profile->getLogoKey();
    }

    public function testAThreeMegabytePngIsRefusedAndLogoKeyIsUnchangedAc4(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->pngUploadedFile(3 * 1024 * 1024);

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertNull($profile->getLogoKey());
    }

    public function testAGifRenamedPngIsRefusedByContentSniffingAc4(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->gifUploadedFileNamedPng();

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertNull($profile->getLogoKey());
    }

    public function testAnSvgUploadIsRefusedWithTheUnsupportedTypeErrorD2(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->svgUploadedFile();

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertNull($profile->getLogoKey());
    }

    public function testAValidTwelveHundredSquarePngIsAcceptedAc5(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->pngUploadedFileWithDimensions(1200, 1200);

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertNotNull($profile->getLogoKey());
        $this->writtenFiles[] = $this->uploadsDir.'/'.$profile->getLogoKey();
    }

    public function testASixThousandSquarePngUnderTwoMegabytesIsRefusedByTheDimensionGuardAc5(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $upload = $this->pngUploadedFileWithDimensions(6000, 6000);

        self::assertLessThan(2 * 1024 * 1024, filesize($upload->getPathname()), 'The fixture must stay under the byte cap so only the dimension guard is exercised.');

        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $upload]);

        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertNull($profile->getLogoKey());
    }

    public function testUploadingASecondLogoReplacesTheFirstAndDeletesThePreviousFile(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $this->client->request('GET', '/trainer/branding');
        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $this->pngUploadedFile(1024)]);

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        $firstKey = $profile->getLogoKey();
        self::assertNotNull($firstKey);
        $firstPath = $this->uploadsDir.'/'.$firstKey;
        self::assertFileExists($firstPath);

        $this->client->request('GET', '/trainer/branding');
        $this->client->request('POST', '/trainer/branding/logo', ['_token' => $this->currentCsrfToken()], ['logo' => $this->pngUploadedFile(2048)]);

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        $secondKey = $profile->getLogoKey();
        self::assertNotNull($secondKey);
        self::assertNotSame($firstKey, $secondKey);
        $this->writtenFiles[] = $this->uploadsDir.'/'.$secondKey;

        self::assertFileDoesNotExist($firstPath, 'The previous logo file must not be left orphaned on disk.');
    }

    public function testATrainerWithNoLogoRendersThePlaceholderWithNoBrokenImg(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $crawler = $this->client->request('GET', '/trainer/branding');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#branding-preview-logo img')->count(), 'No <img> may render for a trainer with no logo.');
    }

    private function persistTrainer(): User
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('branding-logo')));
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

    private function currentCsrfToken(): string
    {
        return (string) $this->client->getCrawler()->filter('form[action$="/branding/logo"] input[name="_token"]')->attr('value');
    }

    private function pngUploadedFile(int $size): UploadedFile
    {
        return $this->pngBytesUploadedFile($this->minimalPngBytes(), $size);
    }

    private function pngUploadedFileWithDimensions(int $width, int $height): UploadedFile
    {
        $gd = imagecreatetruecolor($width, $height);
        self::assertNotFalse($gd);

        $path = tempnam(sys_get_temp_dir(), 'branding-dim-png-');
        self::assertNotFalse($path);
        imagepng($gd, $path);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function pngBytesUploadedFile(string $pngBytes, int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'branding-png-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fwrite($handle, $pngBytes);

        if ($size > \strlen($pngBytes)) {
            fseek($handle, $size - 1);
            fwrite($handle, "\0");
        }

        fclose($handle);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function minimalPngBytes(): string
    {
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($pngBytes);

        return $pngBytes;
    }

    private function gifUploadedFileNamedPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'branding-gif-');
        self::assertNotFalse($path);
        file_put_contents($path, "GIF89a\x01\x00\x01\x00\x00\x00\x00;");

        return new UploadedFile($path, 'logo.png', 'image/gif', null, true);
    }

    private function svgUploadedFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'branding-svg-');
        self::assertNotFalse($path);
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        return new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);
    }
}
