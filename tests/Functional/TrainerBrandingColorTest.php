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

/**
 * Task 28: colour save and reset (AC-8, AC-9, AC-10).
 */
final class TrainerBrandingColorTest extends WebTestCase
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

    public function testAValidColorSavesAndTheNextRenderCarriesItAc8(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $crawler = $this->client->request('GET', '/trainer/branding');
        $this->client->submit($crawler->selectButton('Save color')->form([
            'trainer_branding_form[primaryColorHex]' => '#ff8800',
        ]));
        self::assertResponseRedirects('/trainer/branding');

        $crawler = $this->client->request('GET', '/trainer/branding');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--color-primary: #ff8800', (string) $crawler->filter('#branding-preview')->attr('style'));

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertSame('#ff8800', $profile?->getPrimaryColorHex());
    }

    public function testAnUppercaseSubmissionStoresLowercasedD4bRisk(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $crawler = $this->client->request('GET', '/trainer/branding');
        $this->client->submit($crawler->selectButton('Save color')->form([
            'trainer_branding_form[primaryColorHex]' => '#FF8800',
        ]));
        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertSame('#ff8800', $profile?->getPrimaryColorHex());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHexValues(): iterable
    {
        yield 'missing hash' => ['ff8800'];
        yield 'wrong length' => ['#fff'];
        yield 'non-hex characters' => ['#gggggg'];
    }

    #[DataProvider('invalidHexValues')]
    public function testAnInvalidHexValueIsRefusedAndThePreviousColorIsUnchangedAc9(string $invalidValue): void
    {
        $trainer = $this->persistTrainer();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertNotNull($profile);
        $profile->setPrimaryColorHex('#123456');
        $this->em->flush();

        $this->client->loginUser($trainer);

        // ColorType renders <input type="color">, which browsers refuse to
        // submit anything but a well-formed #rrggbb value through --
        // submitting directly via a raw form POST bypasses that native
        // client-side constraint, which is exactly what proves the
        // server-side Regex/Length pair is the real authority (AC-9).
        $this->client->request('GET', '/trainer/branding');
        $this->client->request('POST', '/trainer/branding', [
            'trainer_branding_form' => [
                'primaryColorHex' => $invalidValue,
                '_token' => $this->currentFormToken(),
            ],
        ]);

        // The invalid submission re-renders the form with errors rather
        // than redirecting -- 422, not a save.
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertSame('#123456', $profile?->getPrimaryColorHex(), 'The previously saved color must remain in effect.');
    }

    public function testTwoRapidSavesOfDifferentColorsLeaveTheSecondValueInEffect(): void
    {
        $trainer = $this->persistTrainer();
        $this->client->loginUser($trainer);

        $crawler = $this->client->request('GET', '/trainer/branding');
        $this->client->submit($crawler->selectButton('Save color')->form([
            'trainer_branding_form[primaryColorHex]' => '#111111',
        ]));
        self::assertResponseRedirects('/trainer/branding');

        $crawler = $this->client->request('GET', '/trainer/branding');
        $this->client->submit($crawler->selectButton('Save color')->form([
            'trainer_branding_form[primaryColorHex]' => '#222222',
        ]));
        self::assertResponseRedirects('/trainer/branding');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertSame('#222222', $profile?->getPrimaryColorHex());
    }

    public function testResetClearsBothColumnsOnTheDatabaseRowAc10(): void
    {
        $trainer = $this->persistTrainer();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertNotNull($profile);
        $profile->setPrimaryColorHex('#654321');
        $profile->setLogoKey('branding/deadbeefdeadbeefdeadbeefdeadbeef.png');
        $this->em->flush();

        $this->client->loginUser($trainer);
        $crawler = $this->client->request('GET', '/trainer/branding');
        $this->client->submit($crawler->selectButton('Reset to default')->form());

        self::assertResponseRedirects('/trainer/branding');

        $connection = $this->em->getConnection();
        $row = $connection->executeQuery(
            'SELECT primary_color_hex, logo_key FROM profile_trainer WHERE id = :id',
            ['id' => (string) $profile->getId()],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertNull($row['primary_color_hex']);
        self::assertNull($row['logo_key']);

        $crawler = $this->client->request('GET', '/trainer/branding');
        self::assertStringContainsString('--color-primary: #0b5fae', (string) $crawler->filter('#branding-preview')->attr('style'));
    }

    private function persistTrainer(): User
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('branding-color')));
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

    private function currentFormToken(): string
    {
        return (string) $this->client->getCrawler()->filter('form[name="trainer_branding_form"] input[name$="[_token]"]')->attr('value');
    }
}
