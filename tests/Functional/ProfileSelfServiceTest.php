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
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service profile editing (AC-10, AC-11, AC-12, AC-13).
 */
final class ProfileSelfServiceTest extends WebTestCase
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
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id IN (SELECT id FROM app_user WHERE email = :email) OR actor_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testAPlayerEditsTheirOwnCommonFieldsAndReadOnlyFieldsAreNotOnTheForm(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($player);

        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dd', $player->getEmail());
        // No form field can carry email/role/created-date -- there is
        // nothing for a tampered request to even target (AC-10).
        self::assertCount(0, $crawler->filter('input[name="profile_common_form[email]"]'));

        $this->client->submit($crawler->selectButton('Save')->form([
            'profile_common_form[firstName]' => 'Alex',
            'profile_common_form[lastName]' => 'Player',
            'profile_common_form[phone]' => '+1 555-000-1111',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Alex', $reloaded->getFirstName());
        self::assertSame('Player', $reloaded->getLastName());
        self::assertSame('+1 555-000-1111', $reloaded->getPhone());
    }

    public function testATrainerAlsoEditsBusinessDetailsThroughTheSameProfileArea(): void
    {
        $trainer = $this->persistTrainerWithProfile('Original Co');
        $this->signIn($trainer);

        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="profile_trainer_form[businessName]"]');

        $this->client->submit($crawler->selectButton('Save business details')->form([
            'profile_trainer_form[businessName]' => 'Renamed Co',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileTrainer::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(ProfileTrainer::class, $profile);
        self::assertSame('Renamed Co', $profile->getBusinessName());
    }

    public function testACoachDoesNotSeeTheBusinessDetailsForm(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $this->signIn($coach);

        $this->client->request('GET', '/profile');

        self::assertSelectorNotExists('input[name="profile_trainer_form[businessName]"]');
    }

    /**
     * `/profile` carries no target id anywhere in the route or the form --
     * `ProfileController::edit()` always resolves the subject from
     * `$this->getUser()`. Submitting the form while signed in as one user
     * can therefore never reach another account, regardless of what a
     * tampered request tries to smuggle in (AC-13).
     */
    public function testProfileEditAlwaysActsOnTheSignedInUserNeverOnAnotherAccount(): void
    {
        $victim = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('victim')));
        $attacker = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('attacker')));
        $this->signIn($attacker);

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Save')->form([
            'profile_common_form[firstName]' => 'Hijacked',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloadedVictim = $this->em->getRepository(User::class)->find($victim->getId());
        $reloadedAttacker = $this->em->getRepository(User::class)->find($attacker->getId());

        self::assertNotSame('Hijacked', $reloadedVictim?->getFirstName());
        self::assertSame('Hijacked', $reloadedAttacker?->getFirstName());
    }

    public function testValidPhotoUploadIsAccepted(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($player);

        $this->client->request('GET', '/profile');
        $this->uploadPhoto($this->tinyPng());

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertNotNull($reloaded?->getPhotoKey());
    }

    public function testOversizedPhotoIsRejected(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($player);

        $this->client->request('GET', '/profile');

        // A genuinely oversized file on disk -- FileStorage checks
        // UploadedFile::getSize(), which reads the real filesystem size, so
        // this must not be faked via a smaller fixture.
        $path = tempnam(sys_get_temp_dir(), 'oversized-photo-').'.png';
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fseek($handle, 6 * 1024 * 1024);
        fwrite($handle, "\0");
        fclose($handle);

        $tooLarge = new UploadedFile($path, 'huge.png', 'image/png', null, true);
        $this->client->request('POST', '/profile/photo', ['_token' => $this->currentCsrfToken()], ['photo' => $tooLarge]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($player->getId());
        self::assertNull($reloaded?->getPhotoKey(), 'An oversized upload must not be stored.');
    }

    public function testAUserCannotViewAnotherUsersPhoto(): void
    {
        $owner = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-owner')));
        $viewer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('photo-viewer')));

        $this->signIn($owner);
        $this->client->request('GET', '/profile');
        $this->uploadPhoto($this->tinyPng());

        $this->client->getCookieJar()->clear();
        $this->signIn($viewer);
        $this->client->request('GET', '/photos/'.$owner->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function uploadPhoto(\SplFileInfo $file): void
    {
        $upload = new UploadedFile($file->getPathname(), 'photo.png', 'image/png', null, true);
        $this->client->request('POST', '/profile/photo', ['_token' => $this->currentCsrfToken()], ['photo' => $upload]);
    }

    /**
     * Reads the token from the already-rendered `/profile` page's hidden
     * field rather than calling `security.csrf.token_manager` directly from
     * test code: that service needs an active request/session in
     * `RequestStack` to persist the generated token, which is not
     * reliably present when called outside of a real request.
     */
    private function currentCsrfToken(): string
    {
        return (string) $this->client->getCrawler()->filter('form[action$="/profile/photo"] input[name="_token"]')->attr('value');
    }

    private function tinyPng(): \SplFileInfo
    {
        // A minimal, valid 1x1 PNG -- so FileStorage's content-sniffed MIME
        // check (finfo, not the filename) genuinely sees `image/png`.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==', true);
        $path = tempnam(sys_get_temp_dir(), 'profile-photo-').'.png';
        file_put_contents($path, (string) $bytes);

        return new \SplFileInfo($path);
    }

    private function persistTrainerWithProfile(string $businessName): User
    {
        $trainer = UserFactory::activeVerified(UserRole::TRAINER);
        $this->em->persist($trainer);
        $this->em->persist(new ProfileTrainer($trainer, $businessName));
        $this->em->flush();
        $this->persistedEmails[] = $trainer->getEmail();

        return $trainer;
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
