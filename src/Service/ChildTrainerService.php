<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChildAccount;
use App\Entity\ChildTrainerRequest;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\ChildTrainerRequestResolution;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\ChildTrainerRequestRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\Exception\ChildNotOwnedByParentException;
use App\Service\Exception\ChildTrainerRequestAlreadyResolvedException;
use App\Service\Exception\NoActiveTrainerAssociationException;
use App\Service\Exception\ShareLinkUnavailableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The child<->trainer connection workflow (AC-4, AC-8, AC-9, AC-10, AC-15,
 * AC-16, AC-17): the family-flow front door onto S3's one association
 * writer, the unconditional ShareLink-click block for a signed-in child,
 * and the parent's review of a resulting request.
 *
 * **`connect()`/`disconnect()` never write a `TrainerPlayerAssociation`
 * themselves.** They delegate entirely to
 * {@see PlayerShareLinkService::associateWithTrainer()}/
 * {@see PlayerShareLinkService::endAssociation()} -- the single writer
 * (D2b) -- so AC-17's "no second, parallel connection mechanism" holds by
 * construction: `approveRequest()` below calls this class's own `connect()`,
 * never a bespoke insert.
 *
 * **The closed-EntityManager pitfall**, same discipline
 * `PlayerShareLinkService` documents at length:
 * `recordBlockedClick()`'s own insert against `child_trainer_request`'s
 * partial unique index can throw `UniqueConstraintViolationException`,
 * which leaves that EntityManager instance permanently closed. Recovery is
 * via `ManagerRegistry::resetManager()`, re-reading the winning row
 * directly against the freshly-reset manager -- never through
 * `$childTrainerRequestRepository`, which permanently caches whichever
 * `EntityRepository` it first resolves.
 */
final class ChildTrainerService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ChildTrainerRequestRepository $childTrainerRequestRepository,
        private readonly TrainerPlayerAssociationRepository $associationRepository,
        private readonly PlayerShareLinkService $playerShareLinkService,
        private readonly ChildAccountResolver $childAccountResolver,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
        private readonly NotificationAddressResolver $notificationAddressResolver,
    ) {
    }

    /**
     * AC-4, AC-8, AC-17. Guards: the parent must own this child
     * (`FamilyVoter::MANAGE_CHILD` at the edge -- a later batch -- *and*
     * this service re-check, per S3 Decision Q4's defence-in-depth rule);
     * the trainer must be role `TRAINER` and `ACTIVE`. Then delegates
     * entirely to `PlayerShareLinkService::associateWithTrainer()`.
     *
     * The `CHILD_TRAINER_CONNECTED` event is recorded only when this call
     * produced a genuinely new association -- the same "an idempotent
     * no-op records nothing a second time" rule
     * `associateWithTrainer()`'s own `PLAYER_TRAINER_ASSOCIATED` event
     * already follows -- rather than on every call regardless of outcome.
     * Re-confirming an existing active pairing (AC-8's no-op, the
     * double-submit edge case) therefore returns the existing row
     * untouched and adds no second event.
     *
     * @throws ChildNotOwnedByParentException the acting user does not
     *                                         parent this child
     * @throws ShareLinkUnavailableException  the trainer is not an active
     *                                         `TRAINER`
     */
    public function connect(User $parent, ChildAccount $child, User $trainer, ?PlayerShareLink $link): TrainerPlayerAssociation
    {
        $this->assertParentOwnsChild($parent, $child);
        $this->assertTrainerEligible($trainer);

        $childUser = $child->getChildUser();
        $wasAlreadyConnected = null !== $this->associationRepository->findOneFor($trainer, $childUser);

        $association = $this->playerShareLinkService->associateWithTrainer($childUser, $trainer, $link, $parent);

        if (!$wasAlreadyConnected) {
            $this->accountEventRecorder->record(new AccountEventRecord(
                type: AccountEventType::CHILD_TRAINER_CONNECTED,
                actorUserId: $parent->getId(),
                subjectUserId: $childUser->getId(),
                context: ['trainerId' => $trainer->getId()->toRfc4122()],
            ));
        }

        return $association;
    }

    /**
     * AC-9, AC-10. Delegates to `PlayerShareLinkService::endAssociation()`:
     * an affected row (`true`) records `CHILD_TRAINER_DISCONNECTED`; no
     * affected row (`false`, never connected or a concurrent second
     * "Remove" click already won) refuses with
     * `NoActiveTrainerAssociationException`, which the controller renders
     * as "already removed", not an error. Because the underlying statement
     * names one `(trainer, player)` pair, AC-10's "changes nothing about
     * any other connection" is that `WHERE` clause; nothing here touches
     * `player_availability_slot`.
     *
     * @throws ChildNotOwnedByParentException     the acting user does not
     *                                             parent this child
     * @throws NoActiveTrainerAssociationException no currently-active
     *                                              association exists for
     *                                              this (trainer, child)
     *                                              pair
     */
    public function disconnect(User $parent, ChildAccount $child, User $trainer): void
    {
        $this->assertParentOwnsChild($parent, $child);

        if (!$this->playerShareLinkService->endAssociation($trainer, $child->getChildUser())) {
            throw new NoActiveTrainerAssociationException();
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::CHILD_TRAINER_DISCONNECTED,
            actorUserId: $parent->getId(),
            subjectUserId: $child->getChildUser()->getId(),
        ));
    }

    /**
     * AC-15, AC-16. Unconditional (D3): called before any association
     * check and regardless of whether one already exists -- no carve-out
     * for an already-connected trainer (the repeat-click edge case).
     *
     * One transaction: find the pending request for `(child, trainer)` or
     * insert one, catching `UniqueConstraintViolationException` on
     * `uniq_child_trainer_request_pending` and re-reading the winner
     * against a freshly-reset manager -- S3's closed-EntityManager
     * discipline, mirrored from
     * `PlayerShareLinkService::getOrCreateFor()`/`associateWithTrainer()`.
     *
     * Post-commit: `CHILD_SHARE_LINK_BLOCKED` is always recorded (actor =
     * subject = the child). `TEMPLATE_CHILD_SHARE_LINK_REQUEST` is
     * dispatched to `NotificationAddressResolver::forPlayer($childUser)`
     * (the parent's address) only when the row was newly created, or an
     * atomic, database-computed conditional `UPDATE ... SET
     * last_notified_at = :now WHERE request = :request AND
     * last_notified_at < :cutoff` actually affects the row -- D3b's 24h
     * re-notification throttle, using the same "the affected-row count is
     * the answer" idiom `PlayerShareLinkService::endAssociation()`/the
     * atomic `usage_count` increment already establish, so two genuinely
     * concurrent re-notification decisions for the same stale row can never
     * both fire the email.
     */
    public function recordBlockedClick(ChildAccount $child, PlayerShareLink $link): ChildTrainerRequest
    {
        $childUser = $child->getChildUser();
        $trainer = $link->getTrainer();
        $now = new \DateTimeImmutable();

        $existing = $this->childTrainerRequestRepository->findPendingFor($childUser, $trainer);
        $isNewRow = false;

        if (null !== $existing) {
            $request = $existing;
        } else {
            $entityManager = $this->openEntityManager();
            $candidate = new ChildTrainerRequest($childUser, $trainer, $child->getParentUser(), $link, $now);

            try {
                $entityManager->wrapInTransaction(static function () use ($entityManager, $candidate): void {
                    $entityManager->persist($candidate);
                });
                $request = $candidate;
                $isNewRow = true;
            } catch (UniqueConstraintViolationException $e) {
                // Do NOT touch $entityManager again -- wrapInTransaction()
                // above already closed it. Reset via the registry and query
                // the fresh manager directly, not through
                // $this->childTrainerRequestRepository (see
                // PlayerShareLinkService::getOrCreateFor()'s identical
                // comment for why).
                $freshManager = $this->managerRegistry->resetManager();
                \assert($freshManager instanceof EntityManagerInterface);

                $winner = $freshManager->createQueryBuilder()
                    ->select('request')
                    ->from(ChildTrainerRequest::class, 'request')
                    ->andWhere('request.childUser = :child')
                    ->andWhere('request.trainer = :trainer')
                    ->andWhere('request.resolvedAt IS NULL')
                    ->setParameter('child', $childUser)
                    ->setParameter('trainer', $trainer)
                    ->getQuery()
                    ->getOneOrNullResult();

                if (!$winner instanceof ChildTrainerRequest) {
                    // The unique violation was on (child_user_id,
                    // trainer_id) WHERE resolved_at IS NULL, so a winning
                    // pending row must exist; a null read here means
                    // something else is wrong and swallowing it would hide
                    // that.
                    throw $e;
                }

                $request = $winner;
            }
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::CHILD_SHARE_LINK_BLOCKED,
            actorUserId: $childUser->getId(),
            subjectUserId: $childUser->getId(),
        ));

        $shouldNotify = $isNewRow || $this->markNotifiedIfStale($request, $now);

        if ($shouldNotify) {
            $this->messageBus->dispatch(new SendEmailMessage(
                to: $this->notificationAddressResolver->forPlayer($childUser),
                template: SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST,
                context: [
                    'childName' => $childUser->getDisplayName(),
                    'trainerName' => $trainer->getDisplayName(),
                    'requestId' => $request->getId()->toRfc4122(),
                ],
            ));
        }

        return $request;
    }

    /**
     * AC-17. Marks the request `APPROVED`/`resolvedBy`, then calls this
     * class's own `connect()` with the request's own `shareLink` -- no
     * second connection path; a pairing that already exists resolves
     * against the existing association (`connect()`'s own idempotent
     * branch).
     *
     * @throws ChildTrainerRequestAlreadyResolvedException the request was
     *                                                      already resolved
     * @throws ChildNotOwnedByParentException               `$parent` is not
     *                                                       this request's
     *                                                       parent
     */
    public function approveRequest(User $parent, ChildTrainerRequest $request): TrainerPlayerAssociation
    {
        $this->assertRequestBelongsToParent($parent, $request);
        $this->assertPending($request);

        $this->resolve($request, ChildTrainerRequestResolution::APPROVED, $parent);

        $child = $this->childAccountResolver->childAccountOf($request->getChildUser());

        if (null === $child) {
            // Cannot happen under normal operation: a ChildTrainerRequest
            // is only ever created for a child (recordBlockedClick() takes
            // a ChildAccount), and ChildAccount rows are never deleted in
            // this slice (see that entity's own docblock). Guards against a
            // silent null-pointer if that invariant is ever broken.
            throw new \LogicException('ChildTrainerRequest names a child_user_id with no ChildAccount row.');
        }

        return $this->connect($parent, $child, $request->getTrainer(), $request->getShareLink());
    }

    /**
     * AC-17. Marks the request `DISMISSED`/`resolvedBy`. No connection is
     * ever made on this path.
     *
     * @throws ChildTrainerRequestAlreadyResolvedException the request was
     *                                                      already resolved
     * @throws ChildNotOwnedByParentException               `$parent` is not
     *                                                       this request's
     *                                                       parent
     */
    public function dismissRequest(User $parent, ChildTrainerRequest $request): void
    {
        $this->assertRequestBelongsToParent($parent, $request);
        $this->assertPending($request);

        $this->resolve($request, ChildTrainerRequestResolution::DISMISSED, $parent);
    }

    private function resolve(ChildTrainerRequest $request, ChildTrainerRequestResolution $resolution, User $resolvedBy): void
    {
        $request->resolve($resolution, $resolvedBy, new \DateTimeImmutable());

        $manager = $this->managerRegistry->getManagerForClass(ChildTrainerRequest::class);
        \assert($manager instanceof EntityManagerInterface);
        $manager->flush();
    }

    private function assertPending(ChildTrainerRequest $request): void
    {
        if (!$request->isPending()) {
            throw new ChildTrainerRequestAlreadyResolvedException();
        }
    }

    private function assertRequestBelongsToParent(User $parent, ChildTrainerRequest $request): void
    {
        if (!$request->getParentUser()->getId()->equals($parent->getId())) {
            throw new ChildNotOwnedByParentException();
        }
    }

    private function assertParentOwnsChild(User $parent, ChildAccount $child): void
    {
        if (!$child->getParentUser()->getId()->equals($parent->getId())) {
            throw new ChildNotOwnedByParentException();
        }
    }

    private function assertTrainerEligible(User $trainer): void
    {
        if (UserRole::TRAINER !== $trainer->getRole() || !$trainer->isActive()) {
            // Same "no longer available" family as
            // PlayerShareLinkService::associateWithTrainer()'s own
            // belt-and-braces trainer-active re-check -- an ineligible
            // trainer (wrong role, or no longer ACTIVE) is unavailable for
            // a new connection either way.
            throw new ShareLinkUnavailableException();
        }
    }

    /**
     * D3b: an atomic, database-computed conditional `UPDATE`, mirroring
     * `PlayerShareLinkService::endAssociation()`'s "affected-row count is
     * the answer" idiom rather than a read-decide-write round trip in PHP.
     * Two genuinely concurrent calls for the same stale row can therefore
     * never both decide to notify: exactly one connection's `UPDATE`
     * affects the row, the other affects none, with the database
     * serializing the two writes at the row level.
     */
    private function markNotifiedIfStale(ChildTrainerRequest $request, \DateTimeImmutable $now): bool
    {
        $manager = $this->managerRegistry->getManagerForClass(ChildTrainerRequest::class);
        \assert($manager instanceof EntityManagerInterface);

        $cutoff = $now->sub(new \DateInterval('P1D'));

        $affectedRows = $manager->createQueryBuilder()
            ->update(ChildTrainerRequest::class, 'request')
            ->set('request.lastNotifiedAt', ':now')
            ->where('request = :request')
            ->andWhere('request.lastNotifiedAt < :cutoff')
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->setParameter('request', $request)
            ->getQuery()
            ->execute();

        return $affectedRows > 0;
    }

    /**
     * Same recovery pattern as `PlayerShareLinkService::openEntityManager()`:
     * detect a manager a previous call left closed and ask the registry to
     * reset it, rather than ever reusing a closed instance.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(ChildTrainerRequest::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', ChildTrainerRequest::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
