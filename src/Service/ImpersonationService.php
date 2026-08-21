<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\ImpersonationEndReason;
use App\Enum\UserRole;
use App\Repository\ImpersonationSessionRepository;
use App\Service\Exception\ImpersonationNotPermittedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * S6: the only writer of `impersonation_session` (architecture Approach
 * #2, D2, D4, D4b, D4c). The row is the authority; the token
 * (`SwitchUserToken`) is a cache -- everything that must end an
 * impersonation does exactly one thing: close the row.
 */
final class ImpersonationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ImpersonationSessionRepository $repository,
        private readonly AccountEventRecorder $accountEventRecorder,
        #[Autowire('%app.impersonation_ttl_seconds%')]
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Re-derives `ImpersonationVoter`'s clauses (defence in depth, S3 Q4 /
     * S5 D4): `$actor` is an ACTIVE SUPER_ADMIN; `$subject` is not
     * SUPER_ADMIN; `$subject` is ACTIVE; `$subject !== $actor`; no open row
     * already exists for `$actor`. One transaction, one insert.
     *
     * A concurrent duplicate insert is caught as a
     * `UniqueConstraintViolationException` (from
     * `uniq_impersonation_active_actor`) and re-thrown as the same typed
     * exception -- the index, not this in-memory check, is what actually
     * serializes concurrent attempts.
     *
     * @throws ImpersonationNotPermittedException
     */
    public function start(User $actor, User $subject, ?string $ip = null, ?\DateTimeImmutable $now = null): ImpersonationSession
    {
        if (UserRole::SUPER_ADMIN !== $actor->getRole() || !$actor->isActive()) {
            throw new ImpersonationNotPermittedException('Only an active Super Admin may impersonate.');
        }

        if (UserRole::SUPER_ADMIN === $subject->getRole()) {
            throw new ImpersonationNotPermittedException('A Super Admin account cannot be impersonated.');
        }

        if (!$subject->isActive()) {
            throw new ImpersonationNotPermittedException('An inactive account cannot be impersonated.');
        }

        if ($subject === $actor || $subject->getId()->equals($actor->getId())) {
            throw new ImpersonationNotPermittedException('A Super Admin cannot impersonate their own account.');
        }

        if (null !== $this->repository->findOpenForActor($actor)) {
            throw new ImpersonationNotPermittedException('This Super Admin already has an active impersonation session.');
        }

        $now ??= new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval(\sprintf('PT%dS', $this->ttlSeconds)));

        $entityManager = $this->entityManager();
        $session = new ImpersonationSession($actor, $subject, $now, $expiresAt, $ip);

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $session): void {
                $entityManager->persist($session);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new ImpersonationNotPermittedException('This Super Admin already has an active impersonation session.', $e);
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::IMPERSONATION_STARTED,
            actorUserId: $actor->getId(),
            subjectUserId: $subject->getId(),
            ip: $ip,
            context: [
                'impersonationSessionId' => (string) $session->getId(),
                'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                'subjectRole' => $subject->getRole()->value,
            ],
        ));

        return $session;
    }

    /**
     * Closes the open row for `$actor`, if any. A zero-affected-rows
     * result (somebody else closed it first) is not an error --
     * `IMPERSONATION_ENDED` is written **only** when the update actually
     * closed the row (D4b), so the timeline never carries two ends for one
     * session.
     */
    public function end(User $actor, ImpersonationEndReason $reason, ?\DateTimeImmutable $now = null): ?ImpersonationSession
    {
        $session = $this->repository->findOpenForActor($actor);

        if (null === $session) {
            return null;
        }

        $this->closeAndRecord($session, $reason, $now);

        return $session;
    }

    /**
     * `end()` by row rather than by actor, for the sweep command and the
     * expiry subscriber.
     */
    public function expire(ImpersonationSession $session, ?\DateTimeImmutable $now = null): void
    {
        $this->closeAndRecord($session, ImpersonationEndReason::TIMEOUT, $now);
    }

    /**
     * D7: closes any open row where `$user` is either actor or subject --
     * the single entry point called post-commit from
     * `AccountLifecycleService::deactivate()`/`delete()`.
     */
    public function forceEndFor(User $user, ImpersonationEndReason $reason, ?\DateTimeImmutable $now = null): void
    {
        foreach ($this->repository->findOpenFor($user) as $session) {
            $this->closeAndRecord($session, $reason, $now);
        }
    }

    /**
     * The NFR-001 lookup, one row via the partial index.
     */
    public function activeFor(User $actor): ?ImpersonationSession
    {
        return $this->repository->findOpenForActor($actor);
    }

    /**
     * Thin delegation to the repository (D8).
     */
    public function search(ImpersonationSearchCriteria $criteria): ImpersonationSearchPage
    {
        return $this->repository->search($criteria);
    }

    private function closeAndRecord(ImpersonationSession $session, ImpersonationEndReason $reason, ?\DateTimeImmutable $now): void
    {
        $now ??= new \DateTimeImmutable();

        $closed = $this->repository->closeIfOpen($session, $now, $reason);

        if (!$closed) {
            return;
        }

        $endedAt = $session->getEndedAt();
        $durationSeconds = null !== $endedAt ? $endedAt->getTimestamp() - $session->getStartedAt()->getTimestamp() : null;

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::IMPERSONATION_ENDED,
            actorUserId: $session->getActorUser()->getId(),
            subjectUserId: $session->getSubjectUser()->getId(),
            context: [
                'impersonationSessionId' => (string) $session->getId(),
                'endReason' => $reason->value,
                'durationSeconds' => $durationSeconds,
            ],
        ));
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(ImpersonationSession::class);
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
