<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
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
use App\Service\FileStorage;
use App\Service\NotificationAddressResolver;
use App\Service\PlayerContextProvider;
use App\Service\PlayerShareLinkService;
use App\Service\SelectorVerifierTokenFactory;
use App\Service\ShareLinkCodeGenerator;
use App\Service\UserAccountService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service-level coverage for `PlayerContextProvider` (Task 20, S4): an adult
 * with zero, one, and many children never has those children's trainer lists
 * merged (AC-11); a child sees only its own single context, never a
 * parent's or a sibling's (AC-12, AC-18).
 *
 * `KernelTestCase` (direct service call), same "not yet wired into any
 * controller" rationale `ChildTrainerServiceTest` documents. A child's own
 * trainer association is created through `ChildTrainerService::connect()`
 * (the parent acting for the child), never through
 * `PlayerShareLinkService::associateWithTrainer()` directly with the child as
 * its own actor -- that path is refused by
 * `ChildActionNotPermittedException` by design (a child cannot connect
 * itself to a trainer through any route).
 */
final class PlayerContextProviderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlayerContextProvider $provider;
    private ChildTrainerService $childTrainerService;
    private PlayerShareLinkService $playerShareLinkService;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->playerShareLinkService = $container->get(PlayerShareLinkService::class);

        $this->childTrainerService = new ChildTrainerService(
            $container->get(ManagerRegistry::class),
            $container->get(ChildTrainerRequestRepository::class),
            $container->get(TrainerPlayerAssociationRepository::class),
            $this->playerShareLinkService,
            $container->get(ChildAccountResolver::class),
            $container->get(AccountEventRecorder::class),
            $container->get(MessageBusInterface::class),
            $container->get(NotificationAddressResolver::class),
        );

        // ChildAccountService (like ChildTrainerService above) has no
        // controller consumer yet in this batch, so the container removes
        // it as dead code -- same "direct instantiation over container
        // fetch" idiom `ChildAccountServiceTest` already establishes.
        $childAccountService = new ChildAccountService(
            $container->get(ManagerRegistry::class),
            $container->get(UserAccountService::class),
            new ChildEmailFactory(),
            $container->get(UserRepository::class),
            $container->get(ChildAccountRepository::class),
            $container->get(ProfileRepository::class),
            $this->childTrainerService,
            $container->get(SelectorVerifierTokenFactory::class),
            $container->get(AccountEventRecorder::class),
            $container->get(MessageBusInterface::class),
            $container->get(FileStorage::class),
            $container->get('logger'),
        );

        $this->provider = new PlayerContextProvider(
            $container->get(ChildAccountResolver::class),
            $childAccountService,
            $container->get(TrainerPlayerAssociationRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testAdultWithNoChildrenGetsOnlyASelfContext(): void
    {
        $adult = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('adult-no-children')));

        $contexts = $this->provider->contextsFor($adult);

        self::assertCount(1, $contexts);
        self::assertTrue($contexts[0]->isSelf);
        self::assertSame('Me', $contexts[0]->label);
        self::assertSame($adult->getId()->toRfc4122(), $contexts[0]->player->getId()->toRfc4122());
        self::assertSame([], $contexts[0]->trainers);
    }

    public function testAdultWithOneChildGetsSelfFirstThenTheChildWithSeparateTrainerListsAc11(): void
    {
        $adult = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('adult-one-child')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('one-child')));
        $childAccount = $this->persistChildAccount($childUser, $adult);

        $parentTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-parent')));
        $childTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-child')));

        $this->connectSelf($adult, $parentTrainer);
        $this->connectChild($adult, $childAccount, $childTrainer);

        $contexts = $this->provider->contextsFor($adult);

        self::assertCount(2, $contexts);

        self::assertTrue($contexts[0]->isSelf);
        self::assertSame('Me', $contexts[0]->label);
        self::assertCount(1, $contexts[0]->trainers);
        self::assertSame($parentTrainer->getId()->toRfc4122(), $contexts[0]->trainers[0]->getTrainer()->getId()->toRfc4122());

        self::assertFalse($contexts[1]->isSelf);
        self::assertSame($childUser->getId()->toRfc4122(), $contexts[1]->player->getId()->toRfc4122());
        self::assertCount(1, $contexts[1]->trainers);
        self::assertSame($childTrainer->getId()->toRfc4122(), $contexts[1]->trainers[0]->getTrainer()->getId()->toRfc4122());

        self::assertNotSame($contexts[0]->trainers, $contexts[1]->trainers, 'AC-11: the parent\'s and child\'s trainer lists must never be the same/merged list.');
    }

    public function testAdultWithManyChildrenGetsOneContextPerChildEachWithItsOwnTrainersAc11(): void
    {
        $adult = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('adult-many-children')));
        $childA = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('many-child-a')));
        $childB = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('many-child-b')));
        $childAccountA = $this->persistChildAccount($childA, $adult);
        $childAccountB = $this->persistChildAccount($childB, $adult);

        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-many-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-many-b')));
        $this->connectChild($adult, $childAccountA, $trainerA);
        $this->connectChild($adult, $childAccountB, $trainerB);

        $contexts = $this->provider->contextsFor($adult);

        self::assertCount(3, $contexts, 'Self + two children, never combined into one list.');
        self::assertTrue($contexts[0]->isSelf);

        $childContexts = \array_slice($contexts, 1);
        $playerIds = array_map(static fn ($c) => $c->player->getId()->toRfc4122(), $childContexts);
        self::assertContains($childA->getId()->toRfc4122(), $playerIds);
        self::assertContains($childB->getId()->toRfc4122(), $playerIds);

        foreach ($childContexts as $context) {
            self::assertFalse($context->isSelf);
            self::assertCount(1, $context->trainers, 'Each child must see only its own trainer, never the sibling\'s.');
        }
    }

    public function testChildSeesOnlyItsOwnSingleSelfContextAc12Ac18(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-context-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-context-child')));
        $sibling = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-context-sibling')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $siblingAccount = $this->persistChildAccount($sibling, $parent);

        $parentTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-parent-2')));
        $childTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-child-2')));
        $siblingTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-sibling')));
        $this->connectSelf($parent, $parentTrainer);
        $this->connectChild($parent, $childAccount, $childTrainer);
        $this->connectChild($parent, $siblingAccount, $siblingTrainer);

        $contexts = $this->provider->contextsFor($childUser);

        self::assertCount(1, $contexts, 'A child must never see more than its own single self context.');
        self::assertTrue($contexts[0]->isSelf);
        self::assertSame('Me', $contexts[0]->label);
        self::assertSame($childUser->getId()->toRfc4122(), $contexts[0]->player->getId()->toRfc4122());

        self::assertCount(1, $contexts[0]->trainers);
        $trainerIds = array_map(static fn ($a) => $a->getTrainer()->getId()->toRfc4122(), $contexts[0]->trainers);
        self::assertSame([$childTrainer->getId()->toRfc4122()], $trainerIds, 'Only the child\'s own trainer -- never the parent\'s, never the sibling\'s.');
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
    }

    private function connectSelf(User $adult, User $trainer): void
    {
        $link = $this->persistLink($trainer);
        $this->playerShareLinkService->associateWithTrainer($adult, $trainer, $link, $adult);
    }

    private function connectChild(User $parent, ChildAccount $childAccount, User $trainer): void
    {
        $link = $this->persistLink($trainer);
        $this->childTrainerService->connect($parent, $childAccount, $trainer, $link);
    }

    private function persistLink(User $trainer): PlayerShareLink
    {
        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
