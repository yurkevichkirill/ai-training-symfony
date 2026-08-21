<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountEvent;
use App\Entity\ChildAccount;
use App\Entity\ChildTrainerRequest;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\ChildTrainerRequestRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\AccountEventRecorder;
use App\Service\ChildAccountResolver;
use App\Service\ChildTrainerService;
use App\Service\Exception\ChildNotOwnedByParentException;
use App\Service\Exception\ChildTrainerRequestAlreadyResolvedException;
use App\Service\Exception\NoActiveTrainerAssociationException;
use App\Service\Exception\ShareLinkUnavailableException;
use App\Service\NotificationAddressResolver;
use App\Service\PlayerShareLinkService;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service-level coverage for `ChildTrainerService` (Tasks 15-17, S4): the
 * child<->trainer connect/disconnect workflow (AC-4, AC-8, AC-9, AC-10,
 * AC-17), the unconditional blocked-ShareLink-click record with its 24h
 * re-notification throttle (AC-15, AC-16, D3b), and the parent's
 * approve/dismiss review of a resulting request (AC-17).
 *
 * `KernelTestCase` (direct service calls), not `WebTestCase`: no controller
 * exists yet for any of these three methods -- `Family\ChildTrainerController`
 * and `Family\ChildTrainerRequestController` are a later batch (Tasks 30-32)
 * -- so this file exercises `ChildTrainerService` exactly the way
 * `PlayerShareLinkUsageCountConcurrencyTest` exercises `PlayerShareLinkService`
 * before Task 23 wired the real route.
 *
 * **`ChildTrainerService` is instantiated directly, not fetched from the
 * container.** With no controller wiring it in yet (this batch's own
 * boundary -- controllers are Tasks 30-32), it has zero inbound references
 * anywhere in the service graph, so Symfony's container compiler removes it
 * as dead code: `self::getContainer()->get(ChildTrainerService::class)`
 * throws `ServiceNotFoundException` ("removed or inlined when the container
 * was compiled"), confirmed empirically. Every one of its eight constructor
 * collaborators, by contrast, already has a real consumer elsewhere (S3's
 * `PlayerShareLinkController` keeps `PlayerShareLinkService`,
 * `ChildAccountResolver`, `AccountEventRecorder`, `NotificationAddressResolver`,
 * `MessageBusInterface` alive; Doctrine's own repository-factory compiler
 * pass keeps both repositories alive regardless of a manual reference), so
 * this fetches each of those from the container and constructs the real
 * class directly -- the same "direct instantiation over container fetch for
 * a not-yet-wired class" idiom `ChildAccountResolverTest` already
 * establishes, just with real collaborators instead of stubs, since this
 * file needs genuine DB/transaction/event/mail-dispatch behavior, not unit
 * isolation.
 */
final class ChildTrainerServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChildTrainerService $service;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->service = new ChildTrainerService(
            $container->get(ManagerRegistry::class),
            $container->get(ChildTrainerRequestRepository::class),
            $container->get(TrainerPlayerAssociationRepository::class),
            $container->get(PlayerShareLinkService::class),
            $container->get(ChildAccountResolver::class),
            $container->get(AccountEventRecorder::class),
            $container->get(MessageBusInterface::class),
            $container->get(NotificationAddressResolver::class),
        );
    }

    /**
     * Same cascade discipline `PlayerShareLinkAssociationTest` documents:
     * `child_account`, `child_trainer_request`, `trainer_player_association`
     * and `player_share_link` all cascade from `app_user` on delete
     * (migration `Version20260820095413`); only `account_event` needs an
     * explicit delete first (`RESTRICT`).
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

    public function testConnectCreatesOneAssociationAndIsIdempotentOnRepeatAc4Ac8(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $childAccount = $this->persistChildAccount($childUser, $parent);

        $association = $this->service->connect($parent, $childAccount, $trainer, null);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $association);

        $again = $this->service->connect($parent, $childAccount, $trainer, null);
        self::assertSame($association->getId()->toRfc4122(), $again->getId()->toRfc4122(), 'AC-8: re-confirming returns the existing row untouched.');

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-8/double-submit: exactly one row after connecting twice.');

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_TRAINER_CONNECTED->value,
        ]);
        self::assertCount(1, $events, 'CHILD_TRAINER_CONNECTED must be recorded once, not once per confirm.');
        self::assertSame($parent->getId()->toRfc4122(), $events[0]->getActorUser()?->getId()->toRfc4122());
        self::assertSame($childUser->getId()->toRfc4122(), $events[0]->getSubjectUser()->getId()->toRfc4122());
        self::assertSame($trainer->getId()->toRfc4122(), $events[0]->getContext()['trainerId']);
    }

    public function testConnectRefusesWhenTheActingParentDoesNotOwnTheChild(): void
    {
        $actualParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('actual-parent')));
        $unrelatedParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('unrelated-parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('owned-child')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $childAccount = $this->persistChildAccount($childUser, $actualParent);

        $this->expectException(ChildNotOwnedByParentException::class);

        try {
            $this->service->connect($unrelatedParent, $childAccount, $trainer, null);
        } finally {
            $count = $this->em->getConnection()->executeQuery(
                'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player',
                ['player' => (string) $childUser->getId()],
            )->fetchOne();
            self::assertSame(0, (int) $count, 'A refused ownership guard must create nothing.');
        }
    }

    public function testConnectRefusesATrainerWhoIsNotRoleTrainer(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-role-check')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-role-check')));
        $notATrainer = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $childAccount = $this->persistChildAccount($childUser, $parent);

        $this->expectException(ShareLinkUnavailableException::class);
        $this->service->connect($parent, $childAccount, $notATrainer, null);
    }

    public function testConnectRefusesADeactivatedTrainer(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-status-check')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-status-check')));
        $inactiveTrainer = $this->persist(UserFactory::deactivated(UserRole::TRAINER));
        $childAccount = $this->persistChildAccount($childUser, $parent);

        $this->expectException(ShareLinkUnavailableException::class);
        $this->service->connect($parent, $childAccount, $inactiveTrainer, null);
    }

    public function testDisconnectEndsOneConnectionAndRefusesOnRepeatAc9Ac10(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-disconnect')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-disconnect')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $childAccount = $this->persistChildAccount($childUser, $parent);

        $this->service->connect($parent, $childAccount, $trainer, null);

        $this->service->disconnect($parent, $childAccount, $trainer);

        $endedAt = $this->em->getConnection()->executeQuery(
            'SELECT ended_at FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertNotFalse($endedAt);
        self::assertNotNull($endedAt, 'disconnect() must end the association, never delete it (audit trail).');

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_TRAINER_DISCONNECTED->value,
        ]);
        self::assertCount(1, $events);

        $this->expectException(NoActiveTrainerAssociationException::class);
        $this->service->disconnect($parent, $childAccount, $trainer);
    }

    public function testDisconnectDoesNotAffectAnyOtherConnectionAc10(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-multi')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-multi')));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a-multi')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b-multi')));
        $childAccount = $this->persistChildAccount($childUser, $parent);

        $this->service->connect($parent, $childAccount, $trainerA, null);
        $this->service->connect($parent, $childAccount, $trainerB, null);

        $this->service->disconnect($parent, $childAccount, $trainerA);

        $endedA = $this->em->getConnection()->executeQuery(
            'SELECT ended_at FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainerA->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        $endedB = $this->em->getConnection()->executeQuery(
            'SELECT ended_at FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainerB->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();

        self::assertNotNull($endedA, 'The disconnected trainer must be ended.');
        self::assertNull($endedB, 'AC-10: ending one connection must change nothing about any other connection.');
    }

    public function testRecordBlockedClickIsIdempotentAndThrottlesReNotificationAc15Ac16D3b(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-blocked')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-blocked')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-blocked')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $link = $this->persistLink($trainer);

        /** @var RecordingEmailMessageHandler $recorder */
        $recorder = self::getContainer()->get(RecordingEmailMessageHandler::class);

        $first = $this->service->recordBlockedClick($childAccount, $link);
        self::assertInstanceOf(ChildTrainerRequest::class, $first);
        self::assertTrue($first->isPending());

        $rowCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :child AND trainer_id = :trainer',
            ['child' => (string) $childUser->getId(), 'trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $rowCount);

        $blockedEvents = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_SHARE_LINK_BLOCKED->value,
        ]);
        self::assertCount(1, $blockedEvents, 'The first click records exactly one CHILD_SHARE_LINK_BLOCKED.');
        self::assertSame($childUser->getId()->toRfc4122(), $blockedEvents[0]->getActorUser()?->getId()->toRfc4122(), 'Actor = subject = the child.');

        $messagesAfterFirstClick = array_values(array_filter(
            $recorder->all(),
            static fn (SendEmailMessage $m): bool => SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST === $m->template,
        ));
        self::assertCount(1, $messagesAfterFirstClick, 'A newly-created request must (re-)notify once.');

        // Second click, immediately after: idempotent on the row, and the
        // 24h throttle means no second email yet (D3b).
        $second = $this->service->recordBlockedClick($childAccount, $link);
        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());

        $rowCountAfterSecondClick = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :child AND trainer_id = :trainer',
            ['child' => (string) $childUser->getId(), 'trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $rowCountAfterSecondClick, 'Still one pending row -- idempotent under the partial unique index.');

        $messagesAfterSecondClick = array_values(array_filter(
            $recorder->all(),
            static fn (SendEmailMessage $m): bool => SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST === $m->template,
        ));
        self::assertCount(1, $messagesAfterSecondClick, 'D3b: a click within the 24h window must not re-send the email.');

        $blockedEventsAfterSecondClick = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $childUser,
            'type' => AccountEventType::CHILD_SHARE_LINK_BLOCKED->value,
        ]);
        self::assertCount(2, $blockedEventsAfterSecondClick, 'The block itself is unconditional (D3): every click records the event, even when the email is throttled.');

        // Force last_notified_at more than 24h in the past, so the third
        // click's atomic conditional UPDATE affects the row and D3b's
        // throttle re-opens.
        $this->em->getConnection()->executeStatement(
            'UPDATE child_trainer_request SET last_notified_at = :stale WHERE id = :id',
            ['stale' => (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:sP'), 'id' => (string) $first->getId()],
        );

        $third = $this->service->recordBlockedClick($childAccount, $link);
        self::assertSame($first->getId()->toRfc4122(), $third->getId()->toRfc4122());

        $messagesAfterThirdClick = array_values(array_filter(
            $recorder->all(),
            static fn (SendEmailMessage $m): bool => SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST === $m->template,
        ));
        self::assertCount(2, $messagesAfterThirdClick, 'D3b: once last_notified_at is over 24h old, the click re-sends the email.');

        $newLastNotifiedAt = $this->em->getConnection()->executeQuery(
            'SELECT last_notified_at FROM child_trainer_request WHERE id = :id',
            ['id' => (string) $first->getId()],
        )->fetchOne();
        self::assertGreaterThan(
            new \DateTimeImmutable('-1 minute'),
            new \DateTimeImmutable((string) $newLastNotifiedAt),
            'last_notified_at must be updated in the same statement that decided to re-notify.',
        );
    }

    public function testRecordBlockedClickCreatesNoAssociationEvenWhenAlreadyConnectedAc15(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-already-connected')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-already-connected')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-already-connected')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $link = $this->persistLink($trainer);

        // The edge case: a child re-clicks a ShareLink for a trainer they
        // are already actively connected to -- D3's unconditional block, no
        // carve-out.
        $this->service->connect($parent, $childAccount, $trainer, null);

        $request = $this->service->recordBlockedClick($childAccount, $link);
        self::assertInstanceOf(ChildTrainerRequest::class, $request);

        $associationCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $associationCount, 'recordBlockedClick() must never create or touch an association.');
    }

    public function testApproveRequestConnectsUsingTheRequestsOwnShareLinkAndRefusesAnAlreadyResolvedRequestAc17(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-approve')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-approve')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-approve')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $link = $this->persistLink($trainer);

        $request = $this->service->recordBlockedClick($childAccount, $link);

        $association = $this->service->approveRequest($parent, $request);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $association);
        self::assertSame($trainer->getId()->toRfc4122(), $association->getTrainer()->getId()->toRfc4122());
        self::assertSame($link->getId()->toRfc4122(), $association->getShareLink()?->getId()->toRfc4122(), 'AC-17: connects using the request\'s own shareLink -- no second connection path.');

        $this->em->clear();
        /** @var ChildTrainerRequestRepository $requestRepository */
        $requestRepository = self::getContainer()->get(ChildTrainerRequestRepository::class);
        $reloaded = $requestRepository->find($request->getId());
        self::assertInstanceOf(ChildTrainerRequest::class, $reloaded);
        self::assertFalse($reloaded->isPending());
        self::assertSame($parent->getId()->toRfc4122(), $reloaded->getResolvedByUser()?->getId()->toRfc4122());

        $this->expectException(ChildTrainerRequestAlreadyResolvedException::class);
        $this->service->approveRequest($parent, $reloaded);
    }

    public function testDismissRequestMarksDismissedCreatesNoConnectionAndRefusesAnAlreadyResolvedRequestAc17(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-dismiss')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-dismiss')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-dismiss')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $link = $this->persistLink($trainer);

        $request = $this->service->recordBlockedClick($childAccount, $link);

        $this->service->dismissRequest($parent, $request);

        $associationCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $associationCount, 'dismissRequest() must never connect anything.');

        $this->em->clear();
        /** @var ChildTrainerRequestRepository $requestRepository */
        $requestRepository = self::getContainer()->get(ChildTrainerRequestRepository::class);
        $reloaded = $requestRepository->find($request->getId());
        self::assertInstanceOf(ChildTrainerRequest::class, $reloaded);
        self::assertFalse($reloaded->isPending());

        $this->expectException(ChildTrainerRequestAlreadyResolvedException::class);
        $this->service->dismissRequest($parent, $reloaded);
    }

    public function testApproveRequestRefusesAParentWhoIsNotThisRequestsParent(): void
    {
        $actualParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('actual-parent-approve')));
        $unrelatedParent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('unrelated-parent-approve')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-approve-guard')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-approve-guard')));
        $childAccount = $this->persistChildAccount($childUser, $actualParent);
        $link = $this->persistLink($trainer);

        $request = $this->service->recordBlockedClick($childAccount, $link);

        $this->expectException(ChildNotOwnedByParentException::class);
        $this->service->approveRequest($unrelatedParent, $request);
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
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
