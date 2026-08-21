<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountEvent;
use App\Entity\AccountInvitation;
use App\Entity\ChildAccount;
use App\Entity\ProfilePlayer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\PlayerGender;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\ChildAccountRepository;
use App\Repository\ChildTrainerRequestRepository;
use App\Repository\ProfileRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Repository\UserRepository;
use App\Service\AccountEventRecorder;
use App\Service\ChildAccountResolver;
use App\Service\ChildAccountService;
use App\Service\ChildEmailFactory;
use App\Service\ChildTrainerService;
use App\Service\CreateChildRequest;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\ShareLinkUnavailableException;
use App\Service\FileStorage;
use App\Service\NotificationAddressResolver;
use App\Service\PlayerShareLinkService;
use App\Service\SelectorVerifierTokenFactory;
use App\Service\UserAccountService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service-level coverage for `ChildAccountService` (Tasks 12-14, S4):
 * `createChild()`'s two-phase creation with its compensating-delete
 * discipline (AC-1, AC-2, AC-4, AC-5, AC-6), `enableSignIn()`'s placeholder
 * replacement and invitation issuance (D1d), and the family-list reads
 * (AC-7, BR-019's soft-warning `findSimilar()`).
 *
 * `KernelTestCase` (direct service calls), not `WebTestCase`: no controller
 * exists yet -- `Family\ChildController` is a later batch (Task 30) -- so
 * `ChildAccountService` is instantiated directly with real collaborators
 * pulled from the container, the same "removed as dead code, construct by
 * hand" idiom `ChildTrainerServiceTest` already establishes (confirmed
 * empirically: `self::getContainer()->get(ChildAccountService::class)`
 * throws `ServiceNotFoundException` today).
 */
final class ChildAccountServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChildAccountService $service;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->mailHandler = $container->get(RecordingEmailMessageHandler::class);

        $this->service = $this->buildService();
    }

    /**
     * Same cascade discipline `ChildTrainerServiceTest` documents:
     * `child_account`, `profile`/`profile_player`, `account_invitation` and
     * `trainer_player_association` all cascade from `app_user` on delete;
     * only `account_event` needs an explicit delete first (`RESTRICT`).
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testCreateChildWithNoTrainersCreatesUserProfileAndAccountAc1Ac2Ac6(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-no-trainers')));

        $request = new CreateChildRequest(
            childName: 'Alex Junior',
            age: 9,
            gender: PlayerGender::MALE,
            school: 'Riverside Elementary',
        );

        $childAccount = $this->service->createChild($parent, $request);
        $this->persistedUserIds[] = (string) $childAccount->getChildUser()->getId();

        self::assertSame($parent->getId()->toRfc4122(), $childAccount->getParentUser()->getId()->toRfc4122(), 'AC-2: linked to the creating parent.');

        $childUser = $childAccount->getChildUser();
        self::assertSame(UserRole::PLAYER, $childUser->getRole(), 'D1: a child keeps ROLE_PLAYER.');
        self::assertSame('Alex Junior', $childUser->getDisplayName());

        $emailFactory = new ChildEmailFactory();
        self::assertTrue($emailFactory->isPlaceholder($childUser->getEmail()), 'D1c: starts on the derived placeholder address.');
        self::assertSame($emailFactory->forChild($childUser->getId()), $childUser->getEmail(), 'D1c: the placeholder is derived from this account\'s own id, corrected after User::create() mints it.');

        $profile = $this->em->getRepository(ProfilePlayer::class)->findOneBy(['user' => $childUser]);
        self::assertInstanceOf(ProfilePlayer::class, $profile);
        self::assertSame('Alex Junior', $profile->getPlayerName());
        self::assertSame(9, $profile->getDeclaredAge());
        self::assertSame(PlayerGender::MALE, $profile->getGender());
        self::assertSame('Riverside Elementary', $profile->getSchool());

        $associationCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player',
            ['player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $associationCount, 'AC-4: "No"/no trainer selected connects nothing.');

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_ACCOUNT_CREATED->value,
        ]);
        self::assertCount(1, $events);
        self::assertSame($parent->getId()->toRfc4122(), $events[0]->getActorUser()?->getId()->toRfc4122());
        self::assertSame(0, $events[0]->getContext()['trainerCount']);

        self::assertCount(0, array_filter(
            $this->mailHandler->all(),
            static fn (SendEmailMessage $m): bool => str_contains($m->to, (string) $childUser->getId()) || $m->to === $childUser->getEmail(),
        ), 'No email is sent from createChild() -- the parent is looking at the result.');
    }

    /**
     * AC-6 alongside AC-4: a parent can create more than one child, and each
     * created child connects to every trainer id it was given -- 1 trainer
     * for the first child, 2 for the second.
     */
    public function testCreateChildConnectsEachSelectedTrainerForOneAndForManyAc4Ac6(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-multi-child')));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a-create')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b-create')));

        $firstChild = $this->service->createChild($parent, new CreateChildRequest(
            childName: 'One Trainer Child',
            age: 7,
            gender: PlayerGender::FEMALE,
            trainerIds: [(string) $trainerA->getId()],
        ));
        $this->persistedUserIds[] = (string) $firstChild->getChildUser()->getId();

        $secondChild = $this->service->createChild($parent, new CreateChildRequest(
            childName: 'Two Trainer Child',
            age: 11,
            gender: PlayerGender::OTHER,
            trainerIds: [(string) $trainerA->getId(), (string) $trainerB->getId()],
        ));
        $this->persistedUserIds[] = (string) $secondChild->getChildUser()->getId();

        $firstCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player',
            ['player' => (string) $firstChild->getChildUser()->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $firstCount);

        $secondCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player',
            ['player' => (string) $secondChild->getChildUser()->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $secondCount);

        $secondEvent = $this->em->getRepository(AccountEvent::class)->findOneBy([
            'subjectUser' => $secondChild->getChildUser(),
            'type' => AccountEventType::CHILD_ACCOUNT_CREATED->value,
        ]);
        self::assertNotNull($secondEvent);
        self::assertSame(2, $secondEvent->getContext()['trainerCount']);

        // AC-6: both children are linked to the same parent.
        /** @var ChildAccountRepository $childAccountRepository */
        $childAccountRepository = self::getContainer()->get(ChildAccountRepository::class);
        $children = $childAccountRepository->findChildrenOf($parent);
        self::assertCount(2, $children);
    }

    /**
     * The forced-failure discipline `TrainerOnboardingFlowTest` establishes:
     * `ProfilePlayer::$school` is `#[ORM\Column(length: 160)]`; a 161-character
     * value reproduces a real Postgres "value too long" failure inside the
     * second phase, called directly (bypassing form validation, which would
     * never reach the database).
     */
    public function testAFailurePersistingTheProfileDoesNotOrphanTheUserRow(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-orphan')));

        $request = new CreateChildRequest(
            childName: 'Orphan By Profile Failure',
            age: 6,
            gender: PlayerGender::PREFER_NOT_TO_SAY,
            school: str_repeat('a', 161),
        );

        $threw = false;

        try {
            $this->service->createChild($parent, $request);
        } catch (\Throwable) {
            // Expected: a real Doctrine DBAL exception wrapping Postgres's
            // "value too long for type character varying(160)" error. Not
            // `self::fail()` inside this try block -- its own
            // AssertionFailedError would otherwise be swallowed by this same
            // catch (\Throwable), silently turning a real regression (the
            // create *succeeding* instead of failing) into a passing test.
            $threw = true;
        }

        self::assertTrue($threw, 'Expected the oversized school value to fail the second phase at the database level.');

        $this->em = $this->reopenManager();

        $orphan = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Orphan By Profile Failure']);
        self::assertNull($orphan, 'The User row committed by phase 1 must not survive a failure in phase 2.');
    }

    /**
     * The trickier compensation case: `resolveTrainers()` only checks that
     * each id names an existing account, not eligibility, so a valid but
     * ineligible trainer (wrong role) is only rejected once `connect()` runs
     * -- after the *first*, eligible trainer in the list has already
     * connected in its own, already-committed sub-transaction
     * (`PlayerShareLinkService::associateWithTrainer()`'s own
     * `wrapInTransaction()` call). The compensating `DELETE FROM app_user`
     * must cascade that already-committed association away too, leaving no
     * partial connection behind.
     */
    public function testAFailureConnectingASecondTrainerCompensatesTheFirstAlreadyConnectedOne(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-mid-loop')));
        $eligibleTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('eligible-trainer-mid-loop')));
        $ineligibleTrainer = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('ineligible-trainer-mid-loop')));

        $request = new CreateChildRequest(
            childName: 'Mid Loop Orphan',
            age: 8,
            gender: PlayerGender::MALE,
            trainerIds: [(string) $eligibleTrainer->getId(), (string) $ineligibleTrainer->getId()],
        );

        $caught = null;

        try {
            $this->service->createChild($parent, $request);
        } catch (ShareLinkUnavailableException $e) {
            // Expected: ChildTrainerService::connect()'s own role/active
            // guard. Captured rather than asserted via expectException() so
            // this test can still make its own post-failure assertions
            // below (see the sibling forced-failure test's comment for why
            // `self::fail()` must never live inside this try block).
            $caught = $e;
        }

        self::assertInstanceOf(ShareLinkUnavailableException::class, $caught, 'Expected the ineligible second trainer to fail connect().');

        $this->em = $this->reopenManager();

        $orphan = $this->em->getRepository(User::class)->findOneBy(['firstName' => 'Mid Loop Orphan']);
        self::assertNull($orphan, 'The User row must not survive a failure partway through connecting trainers.');

        $associationCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer',
            ['trainer' => (string) $eligibleTrainer->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $associationCount, 'The first trainer\'s already-committed association must be compensated away, not left behind.');
    }

    public function testEnableSignInReplacesPlaceholderIssuesInvitationAndRecordsEventD1d(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-enable')));
        $childAccount = $this->service->createChild($parent, new CreateChildRequest(
            childName: 'Enable Sign In Child',
            age: 12,
            gender: PlayerGender::FEMALE,
        ));
        $this->persistedUserIds[] = (string) $childAccount->getChildUser()->getId();

        $realEmail = UserFactory::email('child-real-address');
        $now = new \DateTimeImmutable('2026-08-20 12:00:00');

        $this->service->enableSignIn($parent, $childAccount, $realEmail, $now);

        $childUser = $childAccount->getChildUser();
        self::assertSame($realEmail, $childUser->getEmail());
        self::assertTrue($childAccount->isSignInEnabled());
        self::assertEquals($now, $childAccount->getSignInEnabledAt());

        $invitation = $this->em->getRepository(AccountInvitation::class)->findOneBy(['user' => $childUser]);
        self::assertInstanceOf(AccountInvitation::class, $invitation);
        self::assertSame($parent->getId()->toRfc4122(), $invitation->getIssuedBy()?->getId()->toRfc4122());

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_SIGN_IN_ENABLED->value,
        ]);
        self::assertCount(1, $events);
        self::assertSame($parent->getId()->toRfc4122(), $events[0]->getActorUser()?->getId()->toRfc4122());

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_CHILD_SIGN_IN_INVITATION, $mail->template);
        self::assertSame($realEmail, $mail->to);
        self::assertIsString($mail->context['token']);
    }

    public function testEnableSignInRefusesAnEmailAlreadyInUseAsAFieldLevelConcern(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-dup-email')));
        $existing = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('already-taken')));

        $childAccount = $this->service->createChild($parent, new CreateChildRequest(
            childName: 'Duplicate Email Child',
            age: 10,
            gender: PlayerGender::MALE,
        ));
        $this->persistedUserIds[] = (string) $childAccount->getChildUser()->getId();

        $this->expectException(EmailAlreadyInUseException::class);
        $this->service->enableSignIn($parent, $childAccount, $existing->getEmail(), new \DateTimeImmutable());
    }

    public function testFindChildrenOfReturnsOnlyThisParentsChildrenAc7(): void
    {
        $parentA = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-a-find')));
        $parentB = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-b-find')));

        $childForA = $this->service->createChild($parentA, new CreateChildRequest('A\'s Child', 5, PlayerGender::MALE));
        $this->persistedUserIds[] = (string) $childForA->getChildUser()->getId();

        $childForB = $this->service->createChild($parentB, new CreateChildRequest('B\'s Child', 6, PlayerGender::FEMALE));
        $this->persistedUserIds[] = (string) $childForB->getChildUser()->getId();

        $found = $this->service->findChildrenOf($parentA);

        self::assertCount(1, $found);
        self::assertSame($childForA->getId()->toRfc4122(), $found[0]->getId()->toRfc4122());
    }

    public function testFindSimilarWarnsOnACloseNameAndAgeButFindsNothingOtherwiseBr019(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-similar')));

        $existing = $this->service->createChild($parent, new CreateChildRequest('Jordan Smith', 10, PlayerGender::MALE));
        $this->persistedUserIds[] = (string) $existing->getChildUser()->getId();

        // Same name (case/whitespace-insensitive), age within one year.
        $closeMatches = $this->service->findSimilar($parent, '  jordan smith  ', 11);
        self::assertCount(1, $closeMatches);
        self::assertSame($existing->getId()->toRfc4122(), $closeMatches[0]->getId()->toRfc4122());

        // A different name entirely -- no warning.
        self::assertSame([], $this->service->findSimilar($parent, 'Completely Different Name', 10));

        // Same name, but far enough apart in age -- no warning.
        self::assertSame([], $this->service->findSimilar($parent, 'Jordan Smith', 17));
    }

    private function reopenManager(): EntityManagerInterface
    {
        $doctrine = self::getContainer()->get('doctrine');
        \assert($doctrine instanceof ManagerRegistry);

        $manager = $doctrine->getManagerForClass(User::class);
        if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
            $manager = $doctrine->resetManager();
        }
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function buildService(): ChildAccountService
    {
        $container = self::getContainer();

        // `ChildAccountService` has no consumer yet (no controller until a
        // later batch), so the container compiler removes it -- and, with
        // it, `ChildEmailFactory` (which nothing else in this app
        // constructs) and `ChildTrainerService` (which
        // `ChildTrainerServiceTest` already establishes must be built by
        // hand for the same reason). Every other collaborator below already
        // has a real consumer elsewhere and stays fetchable.
        $childTrainerService = new ChildTrainerService(
            $container->get(ManagerRegistry::class),
            $container->get(ChildTrainerRequestRepository::class),
            $container->get(TrainerPlayerAssociationRepository::class),
            $container->get(PlayerShareLinkService::class),
            $container->get(ChildAccountResolver::class),
            $container->get(AccountEventRecorder::class),
            $container->get(MessageBusInterface::class),
            $container->get(NotificationAddressResolver::class),
        );

        return new ChildAccountService(
            $container->get(ManagerRegistry::class),
            $container->get(UserAccountService::class),
            new ChildEmailFactory(),
            $container->get(UserRepository::class),
            $container->get(ChildAccountRepository::class),
            $container->get(ProfileRepository::class),
            $childTrainerService,
            $container->get(SelectorVerifierTokenFactory::class),
            $container->get(AccountEventRecorder::class),
            $container->get(MessageBusInterface::class),
            $container->get(FileStorage::class),
            $container->get('logger'),
        );
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
