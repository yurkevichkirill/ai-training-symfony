<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ProfileCoach;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 29: `tests/Functional/CoachProfileTest.php` (D1c, AC-11, AC-12,
 * AC-13, AC-14, AC-15, AC-16).
 */
final class CoachProfileTest extends WebTestCase
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

        $connection->executeStatement("DELETE FROM profile WHERE type = 'COACH'");

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * AC-11, AC-12, AC-13: a coach edits bio, credentials, certifications,
     * and the checkbox, and reads them back after a round trip. D1c: the
     * very first save creates the profile_coach row for a coach account
     * that had none.
     */
    public function testACoachEditsAllCoachFieldsAndReadsThemBackAndCreatesTheProfileLazilyAc11Ac12Ac13(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-profile-full')));
        $this->signIn($coach);

        $before = $this->em->getRepository(ProfileCoach::class)->findOneBy(['user' => $coach]);
        self::assertNull($before, 'D1c: no profile_coach row exists before the first save.');

        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save coach details')->form([
            'profile_coach_form[bio]' => 'Ten years coaching youth soccer.',
            'profile_coach_form[credentials]' => 'USSF B License',
            'profile_coach_form[certifications]' => 'CPR Certified',
            'profile_coach_form[isPublic]' => '1',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileCoach::class)->findOneBy(['user' => $coach]);
        self::assertInstanceOf(ProfileCoach::class, $profile, 'D1c: the profile_coach row is created on first save.');
        self::assertSame('Ten years coaching youth soccer.', $profile->getBio());
        self::assertSame('USSF B License', $profile->getCredentials());
        self::assertSame('CPR Certified', $profile->getCertifications());
        self::assertTrue($profile->isPublic());
    }

    /**
     * AC-16: an all-blank submit succeeds and stores nulls.
     */
    public function testAnAllBlankSubmitSucceedsAndStoresNullsAc16(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-profile-blank')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Save coach details')->form([
            'profile_coach_form[bio]' => '',
            'profile_coach_form[credentials]' => '',
            'profile_coach_form[certifications]' => '',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileCoach::class)->findOneBy(['user' => $coach]);
        self::assertInstanceOf(ProfileCoach::class, $profile);
        self::assertNull($profile->getBio());
        self::assertNull($profile->getCredentials());
        self::assertNull($profile->getCertifications());
        self::assertFalse($profile->isPublic());
    }

    /**
     * Edge case 5: a whitespace-only credentials value stores as null, not
     * as spaces.
     */
    public function testAWhitespaceOnlyCredentialsValueStoresAsNullEdgeCase5(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-profile-whitespace')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Save coach details')->form([
            'profile_coach_form[credentials]' => '    ',
        ]));

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $profile = $this->em->getRepository(ProfileCoach::class)->findOneBy(['user' => $coach]);
        self::assertInstanceOf(ProfileCoach::class, $profile);
        self::assertNull($profile->getCredentials());
    }

    /**
     * AC-14: the form renders no email/role/created-date field, and a
     * forged submit carrying those parameters changes none of them.
     */
    public function testTheFormRendersNoEmailRoleOrCreatedDateFieldAndAForgedSubmitChangesNoneOfThemAc14(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-profile-forge')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/profile');
        self::assertCount(0, $crawler->filter('input[name="profile_coach_form[email]"]'));
        self::assertCount(0, $crawler->filter('input[name="profile_coach_form[role]"]'));
        self::assertCount(0, $crawler->filter('input[name="profile_coach_form[createdAt]"]'));

        $originalEmail = $coach->getEmail();
        $originalRole = $coach->getRole();
        $originalCreatedAt = $coach->getCreatedAt();

        $this->client->request('POST', '/profile/coach', [
            'profile_coach_form' => [
                'bio' => 'Forged attempt.',
                'email' => 'attacker@example.test',
                'role' => 'ROLE_SUPER_ADMIN',
                'createdAt' => '2000-01-01',
            ],
        ]);

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($coach->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($originalEmail, $reloaded->getEmail());
        self::assertSame($originalRole, $reloaded->getRole());
        self::assertSame($originalCreatedAt->format('Y-m-d H:i:s'), $reloaded->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * AC-15: a trainer, a player, and a Super Admin each get a 403 from
     * POST /profile/coach.
     */
    public function testNonCoachRolesGet403FromPostProfileCoachAc15(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-profile-trainer')));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('coach-profile-player')));
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN, UserFactory::email('coach-profile-admin')));

        foreach ([$trainer, $player, $admin] as $user) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->disableReboot();

            $this->signIn($user);

            $this->client->request('POST', '/profile/coach', [
                'profile_coach_form' => ['bio' => 'Should be refused.'],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, \sprintf('%s must get 403 from POST /profile/coach.', $user->getRole()->value));
        }
    }

    /**
     * AC-16: a coach with no saved profile renders the visibility checkbox
     * unchecked.
     */
    public function testACoachWithNoSavedProfileRendersTheVisibilityCheckboxUncheckedAc16(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-profile-fresh')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('input[name="profile_coach_form[isPublic]"]');
        self::assertGreaterThan(0, $checkbox->count());
        self::assertNull($checkbox->attr('checked'));
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

        self::assertResponseRedirects();
    }
}
