<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountDeletionLog;
use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\ImpersonationEndReason;
use App\Enum\UserStatus;
use App\Repository\AccountDeletionLogRepository;
use App\Repository\AccountInvitationRepository;
use App\Repository\ProfileRepository;
use App\Service\Exception\ChildActionNotPermittedException;
use App\Service\Exception\InvalidAccountStateTransitionException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The account state machine (AC-14…AC-23): ACTIVE <-> DEACTIVATED, and the
 * one-way ACTIVE|DEACTIVATED -> DELETED.
 *
 * No new session-invalidation code anywhere here: `User::isEqualTo()`
 * (S1's `EquatableInterface` implementation) already compares `status`, so
 * every method below ends any session already open for the affected account
 * at its next request, for free -- see `specs/auth-foundation-architecture.md`.
 */
final class AccountLifecycleService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AccountDeletionLogRepository $deletionLogRepository,
        private readonly AccountInvitationRepository $invitationRepository,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly FileStorage $fileStorage,
        private readonly ChildAccountResolver $childAccountResolver,
        private readonly ImpersonationService $impersonationService,
        private readonly ProfileRepository $profileRepository,
    ) {
    }

    /**
     * **Decision (S4, deferred risk -- deactivation does not cascade to a
     * child).** Deactivating a parent flips only the parent's own `User`
     * row (`status` -> `DEACTIVATED`), exactly as it does for any other
     * account. It never touches the `child_account` row, the child's own
     * `User`, any `TrainerPlayerAssociation`, or any
     * `PlayerAvailabilitySlot` -- there is deliberately no code here that
     * looks up "does this subject have children" at all. A deactivated
     * parent's child keeps signing in and using the platform (attending
     * sessions, its own availability) exactly as before; what the parent
     * loses is the ability to *manage the family* -- add/remove the
     * child's trainer, create another child -- until reactivated, because
     * every one of those actions is gated on the parent's own account
     * being active (`FamilyVoter::MANAGE_FAMILY`), not on anything this
     * method writes. This is the chosen behavior, not an accident of this
     * class never touching anything but `$subject`; see
     * `tests/Functional/AccountLifecycleFlowTest.php` for the test that
     * locks it in, and the architecture's Risks section for the reasoning.
     *
     * **S6 (D7):** if `$subject` is the actor or subject of an open
     * impersonation session, that session is force-ended as
     * `ACCOUNT_STATE_CHANGE`. Stated plainly rather than smoothed over: the
     * affected browser session is invalidated *entirely* by S1's existing
     * `isEqualTo()` mechanism (not returned to the Super Admin's own
     * session) -- fail-closed, consistent with this method's existing
     * deactivation behavior.
     *
     * @throws InvalidAccountStateTransitionException if $subject is already DELETED
     */
    public function deactivate(User $subject, User $actor): void
    {
        if (UserStatus::DELETED === $subject->getStatus()) {
            throw new InvalidAccountStateTransitionException('A deleted account cannot be deactivated.');
        }

        $subject->setStatus(UserStatus::DEACTIVATED);
        $subject->touch();
        $this->invitationRepository->deleteAllForUser($subject);
        $this->flush($subject);

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::USER_DEACTIVATED,
            actorUserId: $actor->getId(),
            subjectUserId: $subject->getId(),
        ));

        // S6 (D7): fail closed -- a deactivated account can no longer be
        // impersonated, and cannot go on impersonating. The affected
        // browser session is invalidated entirely by S1's isEqualTo()
        // mechanism, not returned to the admin's own session.
        $this->impersonationService->forceEndFor($subject, ImpersonationEndReason::ACCOUNT_STATE_CHANGE);
    }

    /**
     * @throws InvalidAccountStateTransitionException unless $subject is currently DEACTIVATED
     */
    public function reactivate(User $subject, User $actor): void
    {
        if (UserStatus::DEACTIVATED !== $subject->getStatus()) {
            throw new InvalidAccountStateTransitionException('Only a deactivated account can be reactivated.');
        }

        $subject->setStatus(UserStatus::ACTIVE);
        $subject->touch();
        $this->flush($subject);

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::USER_REACTIVATED,
            actorUserId: $actor->getId(),
            subjectUserId: $subject->getId(),
        ));
    }

    /**
     * GDPR erasure (AC-18…AC-23). The authoritative "already deleted" guard
     * is the database, via `AccountDeletionLog`'s unique `subject_user_id`
     * index -- checked here before mutating anything, so a concurrent
     * double-delete is caught by that same constraint even if this
     * in-memory check ever raced.
     *
     * **S6 (D7):** same forced-end behavior as `deactivate()` -- if
     * `$subject` is the actor or subject of an open impersonation session,
     * it is force-ended as `ACCOUNT_STATE_CHANGE`, and the affected browser
     * session is invalidated entirely rather than restored to the Super
     * Admin's own session.
     *
     * `impersonation_session`'s two `RESTRICT` foreign keys make a user with
     * impersonation history undeletable *at the row level* -- but S2's
     * deletion path anonymizes `app_user` in place rather than deleting the
     * row, so a subject or actor of a past session remains deletable
     * through this method (architecture Risks).
     *
     * @throws InvalidAccountStateTransitionException if $subject is already DELETED
     * @throws ChildActionNotPermittedException        if $actor is a child account (Task 38,
     *                                                  S3 Decision Q4 defence-in-depth --
     *                                                  `PlayerActionVoter::DELETE_OWN_ACCOUNT`
     *                                                  refuses the same thing at the HTTP edge;
     *                                                  this guard is what still holds for a
     *                                                  console command or a forged request that
     *                                                  never reaches an annotated action. A
     *                                                  Super Admin deleting a child's account is
     *                                                  unaffected -- this only refuses a child
     *                                                  acting as itself)
     */
    public function delete(User $subject, User $actor, ?string $reason): void
    {
        if ($this->childAccountResolver->isChild($actor)) {
            throw new ChildActionNotPermittedException();
        }

        if ($this->deletionLogRepository->existsForUser($subject) || UserStatus::DELETED === $subject->getStatus()) {
            throw new InvalidAccountStateTransitionException('This account has already been deleted.');
        }

        $entityManager = $this->openEntityManager();
        $photoKey = $subject->getPhotoKey();
        $trainerProfile = $this->profileRepository->findTrainerProfile($subject);
        $logoKey = $trainerProfile?->getLogoKey();
        $now = new \DateTimeImmutable();

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $subject, $actor, $reason, $now, $trainerProfile): void {
                $subject->anonymize($now);
                $this->invitationRepository->deleteAllForUser($subject);

                // S7 (flagged Risk): mirrors the $photoKey cleanup below --
                // the logo file itself is deleted from disk after commit,
                // but the column is nulled inside this same transaction so
                // a rolled-back deletion never leaves the row pointing at a
                // file that this method is about to remove.
                if ($trainerProfile instanceof ProfileTrainer) {
                    $trainerProfile->setLogoKey(null);
                }

                $entityManager->persist(new AccountDeletionLog($subject, $actor, $subject->getEmail(), $reason, $now));
            });
        } catch (UniqueConstraintViolationException $e) {
            // Do NOT touch $entityManager again from here on -- same
            // discipline as UserAccountService::create()'s catch block (see
            // its class docblock): wrapInTransaction() above already closed
            // it before letting this exception propagate. The deletion never
            // happened, so none of the post-transaction side effects below
            // (event recording, photo removal) may run either.
            throw new InvalidAccountStateTransitionException('This account has already been deleted.', 0, $e);
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::USER_DELETED,
            actorUserId: $actor->getId(),
            subjectUserId: $subject->getId(),
        ));

        if (null !== $photoKey) {
            $this->fileStorage->delete($photoKey);
        }

        if (null !== $logoKey) {
            $this->fileStorage->delete($logoKey);
        }

        // S6 (D7): same fail-closed reasoning as deactivate() -- a deleted
        // account can no longer be impersonated, and cannot go on
        // impersonating.
        $this->impersonationService->forceEndFor($subject, ImpersonationEndReason::ACCOUNT_STATE_CHANGE);
    }

    private function flush(User $user): void
    {
        $entityManager = $this->managerRegistry->getManagerForClass(User::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->flush();
    }

    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(User::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', User::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
