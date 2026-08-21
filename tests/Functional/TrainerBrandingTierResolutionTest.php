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
use App\Service\TrainerBrandingRequest;
use App\Service\TrainerBrandingService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tasks 29-30: the tier rule -- AC-12's real test surface -- and
 * immediacy (AC-11). D3, D3b, BR-002.
 */
final class TrainerBrandingTierResolutionTest extends WebTestCase
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

    public function testACoachSeesTrainerAsChromeThenSwitchesToTrainerBWithNothingCachedAc11(): void
    {
        $trainerA = $this->persistTrainer('tier-coach-a', '#111111');
        $trainerB = $this->persistTrainer('tier-coach-b', '#222222');
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('tier-coach')));

        $associationA = new TrainerCoachAssociation($trainerA, $coach, null);
        $this->em->persist($associationA);
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/coach');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--color-primary: #111111', (string) $crawler->filter('.branding')->attr('style'));

        // End the association with A, start one with B -- no cache, no
        // re-login: the next request already reflects B. No S3 service
        // call ends a TrainerCoachAssociation directly, so the fixture
        // writes `ended_at` the same way `CoachInvitationAcceptTest` does.
        // The connection's write is issued through this same, un-cleared
        // `EntityManager`'s own `Connection` so the two already-loaded
        // entities below stay valid for the second association's
        // constructor.
        $this->em->getConnection()->executeStatement(
            'UPDATE trainer_coach_association SET ended_at = NOW() WHERE id = :id',
            ['id' => (string) $associationA->getId()],
        );

        $associationB = new TrainerCoachAssociation($trainerB, $coach, null);
        $this->em->persist($associationB);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/coach');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--color-primary: #222222', (string) $crawler->filter('.branding')->attr('style'));
    }

    public function testAPlayerAssociatedWithTwoTrainersSeesNeitherInChromeButBothOnTheRosterAc12(): void
    {
        $trainerA = $this->persistTrainer('tier-player-a', '#333333');
        $trainerB = $this->persistTrainer('tier-player-b', '#444444');
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('tier-player')));

        $this->em->persist(new TrainerPlayerAssociation($trainerA, $player, null));
        $this->em->persist(new TrainerPlayerAssociation($trainerB, $player, null));
        $this->em->flush();

        $this->client->loginUser($player);

        // No chrome branding on the player's own dashboard (D3, D3b).
        $crawler = $this->client->request('GET', '/player');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.branding')->count(), 'A multi-trainer player must get no chrome branding.');

        // Both trainers' own branding on the roster, each beside its own row.
        $crawler = $this->client->request('GET', '/player/trainers');
        self::assertResponseIsSuccessful();
        $html = (string) $crawler->filter('body')->html();
        self::assertStringContainsString('#333333', $html);
        self::assertStringContainsString('#444444', $html);
    }

    public function testATrainerNeverSeesAnotherTrainersBrandingOnAnyPage(): void
    {
        $trainerA = $this->persistTrainer('tier-trainer-a', '#555555');
        $this->persistTrainer('tier-trainer-b', '#666666');

        $this->client->loginUser($trainerA);
        $crawler = $this->client->request('GET', '/trainer');
        self::assertResponseIsSuccessful();
        $html = (string) $crawler->filter('body')->html();
        self::assertStringContainsString('#555555', $html);
        self::assertStringNotContainsString('#666666', $html);
    }

    public function testAParentsCrossChildViewRendersPlatformDefaultChrome(): void
    {
        $this->persistTrainer('tier-family-trainer', '#777777');
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('tier-parent')));

        $this->client->loginUser($parent);
        $crawler = $this->client->request('GET', '/family');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.branding')->count(), 'The family cross-child chrome must carry no branding variable.');
    }

    public function testTheShareLinkLandingPageShowsTrainerAsBrandingToAnAnonymousVisitor(): void
    {
        $trainerA = $this->persistTrainer('tier-share-a', '#888888');
        $link = $this->createLinkFor($trainerA);

        // Anonymous visitor: `follow()` redirects straight to the
        // registration form (AC-7), which is what carries A's branding.
        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseRedirects('/join/'.$link->getCode().'/register');

        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--color-primary: #888888', (string) $crawler->filter('.branding')->attr('style'));
    }

    /**
     * A signed-in child, already associated with trainer B, follows
     * trainer A's ShareLink: `follow()`'s child-blocked branch renders
     * before the voter or any association check even runs, carrying A's
     * branding, never B's -- no bleed. Deliberately its own test method
     * (a fresh kernel/client, one single request) rather than a second
     * half appended after other requests on the same client, since this
     * scenario's own persistence (`ChildTrainerService::recordBlockedClick()`
     * writing inside the request) is what this isolation protects.
     */
    public function testTheShareLinkChildBlockedPageShowsOnlyThatLinksTrainerToASignedInChildOfAnotherTrainer(): void
    {
        $trainerA = $this->persistTrainer('tier-share-child-a', '#888888');
        $trainerB = $this->persistTrainer('tier-share-child-b', '#999999');
        $link = $this->createLinkFor($trainerA);

        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('tier-share-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('tier-share-child')));
        $this->em->persist(new ChildAccount($childUser, $parent));
        $this->em->persist(new TrainerPlayerAssociation($trainerB, $childUser, null));
        $this->em->flush();

        $this->client->loginUser($childUser);
        $crawler = $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseIsSuccessful();
        $html = (string) $crawler->filter('body')->html();
        self::assertStringContainsString('#888888', $html);
        self::assertStringNotContainsString('#999999', $html);
    }

    /**
     * AC-11: a trainer saves a new color in one session; a player's
     * already-authenticated session, with no logout and no cache clear,
     * renders the new color on its very next request to a branded surface
     * (the ShareLink landing page, tier C).
     */
    public function testImmediacyAPlayersNextRequestReflectsATrainersJustSavedColor(): void
    {
        $trainer = $this->persistTrainer('tier-immediacy-trainer', '#0b5fae');
        $link = $this->createLinkFor($trainer);

        // The anonymous player's own session -- a single client, never
        // logged in, mirroring "no re-login, no cache clear" (AC-11).
        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        self::assertStringContainsString('--color-primary: #0b5fae', (string) $crawler->filter('.branding')->attr('style'));

        // The trainer saves a new color from a separate actor entirely
        // (the service call itself, standing in for the trainer's own,
        // independent browser session) -- no action is taken on the
        // player's session above.
        /** @var TrainerBrandingService $brandingService */
        $brandingService = self::getContainer()->get(TrainerBrandingService::class);
        $brandingService->updateColor($trainer, new TrainerBrandingRequest('#abcdef'), $trainer);

        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        self::assertStringContainsString('--color-primary: #abcdef', (string) $crawler->filter('.branding')->attr('style'));
    }

    private function persistTrainer(string $prefix, string $primaryColorHex): User
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email($prefix)));
        $profile = new ProfileTrainer($trainer, $prefix.' Academy');
        $profile->setPrimaryColorHex($primaryColorHex);
        $this->em->persist($profile);
        $this->em->flush();

        return $trainer;
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
