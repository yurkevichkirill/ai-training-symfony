<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wiring-level coverage for Tasks 30-33 (S4 batch 8): routing, basic
 * 200/302/403 behavior for every new route this batch adds. Deeper AC-level
 * coverage is Tasks 41-43, a later batch -- this file only proves the
 * controller -> service -> voter wiring actually works end to end.
 */
final class FamilyAndAvailabilityControllersTest extends WebTestCase
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

        // Some routes under test (e.g. POST /family/children/new) create a
        // child User indirectly through ChildAccountService rather than via
        // $this->persist(), so it never lands in $persistedUserIds. Collect
        // every child_account row's child_user_id up front -- covering both
        // explicitly-tracked and controller-created children -- before the
        // child_account rows themselves are deleted below.
        $childUserIds = $connection->executeQuery('SELECT child_user_id FROM child_account')->fetchFirstColumn();

        foreach ([...$this->persistedUserIds, ...$childUserIds] as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
        }

        $connection->executeStatement('DELETE FROM child_trainer_request');
        $connection->executeStatement('DELETE FROM trainer_player_association');
        $connection->executeStatement('DELETE FROM player_share_link');
        $connection->executeStatement('DELETE FROM child_account');

        foreach (array_unique([...$this->persistedUserIds, ...$childUserIds]) as $id) {
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testFamilyIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/family');

        self::assertResponseRedirects('/login');
    }

    public function testFamilyIndexRendersForAParentWithNoChildren(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-empty')));
        $this->signIn($parent);

        $this->client->request('GET', '/family');

        self::assertResponseIsSuccessful();
    }

    public function testFamilyIndexIsForbiddenToASignedInChild(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-of-child')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-user')));
        $this->persistChildAccount($childUser, $parent);
        $this->signIn($childUser);

        $this->client->request('GET', '/family');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testChildNewFormRendersAndCreatesAChild(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-create')));
        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Create')->form([
            'child_profile_form[childName]' => 'Junior',
            'child_profile_form[age]' => '8',
            'child_profile_form[gender]' => 'MALE',
        ]));

        self::assertResponseRedirects('/family');
    }

    public function testUploadPhotoAndSignInRoutesAreForbiddenForAnotherParentsChild(): void
    {
        $owner = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('owner-parent')));
        $intruder = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('intruder-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('owned-child')));
        $this->persistChildAccount($childUser, $owner);

        $this->signIn($intruder);

        $this->client->request('POST', '/family/children/'.$childUser->getId().'/photo', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/family/children/'.$childUser->getId().'/sign-in');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testChildTrainerAddFormRendersForTheOwningParent(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-trainer-add')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-trainer-add')));
        $this->persistChildAccount($childUser, $parent);
        $this->signIn($parent);

        $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/add');

        self::assertResponseIsSuccessful();
    }

    public function testChildTrainerRemoveConfirmAndRemoveWork(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-trainer-remove')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-trainer-remove')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-to-remove')));
        $this->persistChildAccount($childUser, $parent);
        $this->persistAssociation($trainer, $childUser);

        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/'.$trainer->getId().'/remove');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Remove trainer')->form());

        self::assertResponseRedirects('/family');
    }

    public function testRequestReviewApproveAndDismissRoutesRequireOwnership(): void
    {
        $actualParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('actual-parent-review')));
        $otherParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('other-parent-review')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-review')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-review')));
        $childAccount = $this->persistChildAccount($childUser, $actualParent);
        $link = $this->persistLink($trainer);

        $requestId = $this->recordBlockedClick($childAccount, $link);

        $this->signIn($otherParent);
        $this->client->request('GET', '/family/requests/'.$requestId);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->getCookieJar()->clear();
        $this->signIn($actualParent);

        $crawler = $this->client->request('GET', '/family/requests/'.$requestId);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Dismiss')->form());
        self::assertResponseRedirects('/family');
    }

    public function testAvailabilityEditRendersAndSavesForTheSignedInPlayer(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-player')));
        $this->signIn($player);

        $crawler = $this->client->request('GET', '/player/availability');
        self::assertResponseIsSuccessful();

        // No children -> exactly one context -> no switcher rendered.
        self::assertSelectorNotExists('nav[aria-label="Switch player"]');

        $this->client->submit($crawler->selectButton('Save availability')->form());

        self::assertResponseRedirects('/player/availability');
    }

    public function testAvailabilityEditShowsSwitcherAndIsForbiddenForAnotherFamilysChild(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-child')));
        $otherParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-other-parent')));
        $this->persistChildAccount($childUser, $parent);

        $this->signIn($parent);
        $this->client->request('GET', '/player/availability');
        self::assertSelectorExists('nav[aria-label="Switch player"]');

        $this->client->getCookieJar()->clear();
        $this->signIn($otherParent);
        $this->client->request('GET', '/player/availability?player='.$childUser->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
    }

    private function persistAssociation(User $trainer, User $player): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function persistLink(User $trainer): PlayerShareLink
    {
        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function recordBlockedClick(ChildAccount $childAccount, PlayerShareLink $link): string
    {
        /** @var \App\Service\ChildTrainerService $service */
        $service = self::getContainer()->get(\App\Service\ChildTrainerService::class);
        $request = $service->recordBlockedClick($childAccount, $link);

        return (string) $request->getId();
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
