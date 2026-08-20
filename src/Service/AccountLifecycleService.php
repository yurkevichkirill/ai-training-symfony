<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountDeletionLog;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserStatus;
use App\Repository\AccountDeletionLogRepository;
use App\Repository\AccountInvitationRepository;
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
    ) {
    }

    /**
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
     * @throws InvalidAccountStateTransitionException if $subject is already DELETED
     */
    public function delete(User $subject, User $actor, ?string $reason): void
    {
        if ($this->deletionLogRepository->existsForUser($subject) || UserStatus::DELETED === $subject->getStatus()) {
            throw new InvalidAccountStateTransitionException('This account has already been deleted.');
        }

        $entityManager = $this->openEntityManager();
        $photoKey = $subject->getPhotoKey();
        $now = new \DateTimeImmutable();

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $subject, $actor, $reason, $now): void {
                $subject->anonymize($now);
                $this->invitationRepository->deleteAllForUser($subject);

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
