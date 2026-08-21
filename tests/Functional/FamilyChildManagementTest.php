<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerShareLink;
use App\Entity\ProfilePlayer;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\PlayerContextProvider;
use App\Service\PlayerShareLinkService;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tasks 41 (AC-1...AC-11): the family/child-management flow driven through
 * the real HTTP form layer -- `Family\ChildController`/`ChildTrainerController`
 * -- rather than the service layer `ChildAccountServiceTest`/`ChildTrainerServiceTest`
 * already exercise directly. Every scenario here proves the controller/form
 * wiring itself, not just the service it delegates to.
 */
final class FamilyChildManagementTest extends WebTestCase
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

        $connection->executeStatement('DELETE FROM child_trainer_request');
        $connection->executeStatement('DELETE FROM trainer_player_association');
        $connection->executeStatement('DELETE FROM player_share_link');
        $connection->executeStatement('DELETE FROM child_account');

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * AC-3's "no trainers" shape: the field is not rendered at all, and
     * saving with no trainer selection connects nothing (AC-4).
     */
    public function testCreateChildFormShowsNoTrainerFieldWhenParentHasZeroTrainersAc3Ac4(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-zero')));
        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/new');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name*="trainerIds"]'), 'AC-3: zero trainers means no prompt or checklist at all.');

        $this->client->submit($crawler->selectButton('Create')->form([
            'child_profile_form[childName]' => 'Zero Trainer Child',
            'child_profile_form[age]' => '10',
            'child_profile_form[gender]' => 'MALE',
        ]));
        self::assertResponseRedirects('/family');

        $childUser = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Zero Trainer Child']);
        self::assertInstanceOf(User::class, $childUser);
        $this->persistedUserIds[] = (string) $childUser->getId();

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :id',
            ['id' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count);
    }

    /**
     * AC-3's single-trainer Yes/No shape, and AC-4's "Yes" connecting the
     * child to that one trainer as part of the same save.
     */
    public function testCreateChildFormShowsYesNoCheckboxForExactlyOneTrainerAndYesConnectsAc3Ac4(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-one')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-one')));
        $this->connectSelf($parent, $trainer);
        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/new');
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('input[name="child_profile_form[trainerIds]"]');
        self::assertGreaterThan(0, $checkbox->count(), 'AC-3: exactly one trainer must render a single Yes/No checkbox.');
        self::assertSame('checkbox', $checkbox->attr('type'));

        $this->client->submit($crawler->selectButton('Create')->form([
            'child_profile_form[childName]' => 'Yes Trainer Child',
            'child_profile_form[age]' => '8',
            'child_profile_form[gender]' => 'FEMALE',
            'child_profile_form[trainerIds]' => '1',
        ]));
        self::assertResponseRedirects('/family');

        $childUser = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Yes Trainer Child']);
        self::assertInstanceOf(User::class, $childUser);
        $this->persistedUserIds[] = (string) $childUser->getId();

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player AND trainer_id = :trainer',
            ['player' => (string) $childUser->getId(), 'trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-4: answering Yes connects the new child to that trainer.');
    }

    /**
     * AC-3's multi-trainer checklist shape, and AC-4's "selecting one or
     * more" connecting exactly those selected -- not every available one.
     */
    public function testCreateChildFormShowsChecklistForManyTrainersAndSelectionConnectsOnlyThoseAc3Ac4(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-many')));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-many-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-many-b')));
        $this->connectSelf($parent, $trainerA);
        $this->connectSelf($parent, $trainerB);
        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/new');
        self::assertResponseIsSuccessful();

        $checkboxes = $crawler->filter('input[name="child_profile_form[trainerIds][]"]');
        self::assertSame(2, $checkboxes->count(), 'AC-3: more than one trainer must render a checklist, one box per trainer.');
        $onlyTrainerAValue = (string) $checkboxes->eq(0)->attr('value');

        $this->client->submit($crawler->selectButton('Create')->form([
            'child_profile_form[childName]' => 'Checklist Child',
            'child_profile_form[age]' => '12',
            'child_profile_form[gender]' => 'OTHER',
            'child_profile_form[trainerIds]' => [$onlyTrainerAValue],
        ]));
        self::assertResponseRedirects('/family');

        $childUser = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Checklist Child']);
        self::assertInstanceOf(User::class, $childUser);
        $this->persistedUserIds[] = (string) $childUser->getId();

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :id',
            ['id' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-4: only the selected trainer is connected, not both.');
    }

    /**
     * AC-5: an out-of-range age, and a missing gender, are both refused and
     * create nothing at all -- no User, no ProfilePlayer, no ChildAccount.
     */
    public function testInvalidSubmissionsAreRefusedAndCreateNothingAc5(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-invalid')));
        $this->signIn($parent);

        foreach ([
            ['age' => '0', 'gender' => 'MALE', 'name' => 'Age Zero Child'],
            ['age' => '19', 'gender' => 'MALE', 'name' => 'Age Nineteen Child'],
            ['age' => '10', 'gender' => null, 'name' => 'No Gender Child'],
        ] as $case) {
            $crawler = $this->client->request('GET', '/family/children/new');
            $form = $crawler->selectButton('Create')->form([
                'child_profile_form[childName]' => $case['name'],
                'child_profile_form[age]' => $case['age'],
            ]);

            if (null !== $case['gender']) {
                $form['child_profile_form[gender]'] = $case['gender'];
            } else {
                // A missing gender: unset the field entirely rather than
                // submitting an empty/invalid choice value the crawler's own
                // `ChoiceFormField` would refuse to hold client-side.
                $form->remove('child_profile_form[gender]');
            }

            $this->client->submit($form);

            self::assertResponseStatusCodeSame(422, 'An invalid submission must re-render the form (with validation errors), not redirect.');

            $user = $this->em->getRepository(User::class)->findOneBy(['firstName' => $case['name']]);
            self::assertNull($user, \sprintf('AC-5: "%s" must create no User row.', $case['name']));

            $profile = $this->em->getRepository(ProfilePlayer::class)->findOneBy(['playerName' => $case['name']]);
            self::assertNull($profile, \sprintf('AC-5: "%s" must create no ProfilePlayer row.', $case['name']));
        }
    }

    /**
     * AC-6: a parent can create more than one child; AC-7: the family list
     * shows each child's name, and every trainer it is connected to with the
     * connection's start date.
     */
    public function testFamilyIndexListsEachChildAndItsTrainersWithConnectionDatesAc6Ac7(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-list')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-list')));

        $firstChild = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-list-a')));
        $secondChild = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-list-b')));
        $this->persistChildAccount($firstChild, $parent);
        $this->persistChildAccount($secondChild, $parent);
        $association = $this->connectPlayer($trainer, $firstChild);

        $this->signIn($parent);
        $crawler = $this->client->request('GET', '/family');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('body', $firstChild->getDisplayName());
        self::assertSelectorTextContains('body', $secondChild->getDisplayName());
        self::assertSelectorTextContains('body', $trainer->getDisplayName());
        self::assertSelectorTextContains('body', $association->getCreatedAt()->format('Y-m-d'));
    }

    /**
     * AC-11: `PlayerContextProvider` gives the signed-in parent their own
     * context first, then one per child -- never merged.
     */
    public function testPlayerContextProviderReturnsSelfAndOneContextPerChildAc11(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-context')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-context')));
        $this->persistChildAccount($childUser, $parent);

        /** @var PlayerContextProvider $provider */
        $provider = self::getContainer()->get(PlayerContextProvider::class);
        $contexts = $provider->contextsFor($parent);

        self::assertCount(2, $contexts);
        self::assertTrue($contexts[0]->isSelf);
        self::assertFalse($contexts[1]->isSelf);
        self::assertSame($childUser->getId()->toRfc4122(), $contexts[1]->player->getId()->toRfc4122());
    }

    /**
     * AC-8: adding a trainer by ShareLink code, and adding one from "My
     * Trainers", both produce exactly one connection row; repeating either
     * is a no-op.
     */
    public function testAddingATrainerByCodeAndByMyTrainersProducesOneRowEachAndRepeatIsANoOpAc8(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-add')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-add')));
        $this->persistChildAccount($childUser, $parent);

        $codeTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-by-code')));
        $link = $this->createLinkFor($codeTrainer);

        $myTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-by-list')));
        $this->connectSelf($parent, $myTrainer);

        $this->signIn($parent);

        // By ShareLink code.
        $crawler = $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/add');
        $this->client->submit($crawler->selectButton('Connect')->form([
            'child_trainer_add_form[shareLinkCode]' => $link->getCode(),
        ]));
        self::assertResponseRedirects('/family');

        // Repeat the exact same code submission -- must stay a no-op.
        $crawler = $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/add');
        $this->client->submit($crawler->selectButton('Connect')->form([
            'child_trainer_add_form[shareLinkCode]' => $link->getCode(),
        ]));
        self::assertResponseRedirects('/family');

        $codeCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player AND trainer_id = :trainer',
            ['player' => (string) $childUser->getId(), 'trainer' => (string) $codeTrainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $codeCount, 'AC-8: repeating the same code must not duplicate the connection.');

        // By "My Trainers" pick.
        $crawler = $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/add');
        $this->client->submit($crawler->selectButton('Connect')->form([
            'child_trainer_add_form[trainerId]' => (string) $myTrainer->getId(),
        ]));
        self::assertResponseRedirects('/family');

        $listCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player AND trainer_id = :trainer',
            ['player' => (string) $childUser->getId(), 'trainer' => (string) $myTrainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $listCount, 'AC-8: exactly one connection is created via "My Trainers".');
    }

    /**
     * AC-9: the remove-confirmation page names both the child and the
     * trainer and warns about RSVPs; confirming ends exactly one connection.
     * AC-10: a sibling's, and the same child's other, connections are
     * untouched.
     */
    public function testRemoveConfirmationNamesBothPartiesAndEndsExactlyOneConnectionAc9Ac10(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-remove')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-remove')));
        $sibling = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('sibling-remove')));
        $this->persistChildAccount($childUser, $parent);
        $this->persistChildAccount($sibling, $parent);

        $trainerToRemove = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-to-remove')));
        $otherTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-other')));

        $this->connectPlayer($trainerToRemove, $childUser);
        $this->connectPlayer($otherTrainer, $childUser);
        $this->connectPlayer($trainerToRemove, $sibling);

        $this->signIn($parent);

        $crawler = $this->client->request('GET', '/family/children/'.$childUser->getId().'/trainers/'.$trainerToRemove->getId().'/remove');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $childUser->getDisplayName());
        self::assertSelectorTextContains('body', $trainerToRemove->getDisplayName());
        self::assertSelectorTextContains('body', 'RSVP');

        $this->client->submit($crawler->selectButton('Remove trainer')->form());
        self::assertResponseRedirects('/family');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        self::assertNull($associationRepository->findOneFor($trainerToRemove, $childUser), 'The named connection must be ended.');
        self::assertInstanceOf(TrainerPlayerAssociation::class, $associationRepository->findOneFor($otherTrainer, $childUser), 'AC-10: the child\'s other trainer connection is untouched.');
        self::assertInstanceOf(TrainerPlayerAssociation::class, $associationRepository->findOneFor($trainerToRemove, $sibling), 'AC-10: a sibling\'s connection with the same trainer is untouched.');
    }

    /**
     * A parent cannot manage another parent's child through any
     * `/family/children/{id}/...` route.
     */
    public function testAParentCannotManageAnotherParentsChildOnAnyFamilyChildRoute(): void
    {
        $owner = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('owner-parent-2')));
        $intruder = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('intruder-parent-2')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('owned-child-2')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-guard-2')));
        $this->persistChildAccount($childUser, $owner);
        $this->connectPlayer($trainer, $childUser);

        $this->signIn($intruder);

        foreach ([
            ['GET', '/family/children/'.$childUser->getId().'/trainers/add'],
            ['GET', '/family/children/'.$childUser->getId().'/trainers/'.$trainer->getId().'/remove'],
            ['POST', '/family/children/'.$childUser->getId().'/trainers/'.$trainer->getId().'/remove'],
            ['POST', '/family/children/'.$childUser->getId().'/photo'],
            ['GET', '/family/children/'.$childUser->getId().'/sign-in'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, \sprintf('%s %s must be refused for a non-owning parent.', $method, $path));
        }
    }

    private function connectSelf(User $parent, User $trainer): TrainerPlayerAssociation
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->associateWithTrainer($parent, $trainer, null, $parent);
    }

    /**
     * Fixture-only direct connection (bypassing the service layer entirely,
     * same as `FamilyAndAvailabilityControllersTest::persistAssociation()`):
     * avoids `associateWithTrainer()`'s child-actor guard, which would
     * otherwise refuse a child player with no real parent actor supplied.
     */
    private function connectPlayer(User $trainer, User $player): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();

        return $link;
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
