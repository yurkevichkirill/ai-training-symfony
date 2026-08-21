<?php

declare(strict_types=1);

namespace App\Service;

use App\Availability\TimeRange;
use App\Entity\CoachAssignmentOverride;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Repository\CoachAssignmentOverrideRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\Exception\CoachActionNotPermittedException;
use App\Service\Exception\MissingOverrideReasonException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The conflict-check-and-override-log capability's writer and audit-read
 * surface (AC-7, AC-8; D3, D3c, D3d). **No route, no form, no console
 * command calls `record()` in this slice** -- a writer with no real
 * conflict behind it would be a forgery primitive, not a test harness.
 * Epic-02 is this method's intended caller.
 */
final class CoachAssignmentOverrideService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CoachAssignmentOverrideRepository $overrideRepository,
        private readonly TrainerCoachAssociationRepository $trainerCoachAssociationRepository,
        private readonly CoachAvailabilityService $coachAvailabilityService,
        private readonly AccountEventRecorder $accountEventRecorder,
    ) {
    }

    /**
     * AC-7, AC-8. Trims the reason and throws
     * {@see MissingOverrideReasonException} on empty *before* the insert --
     * the database `CHECK (btrim(reason) <> '')` is the second layer
     * (D3d). Asserts `$coach` is an active `COACH` and `$trainer` is an
     * active `TRAINER` with an active `TrainerCoachAssociation` to that
     * coach. Re-evaluates coverage through
     * `CoachAvailabilityService::evaluate()` and stores what it evaluated,
     * rather than trusting a caller-supplied verdict. One transaction, one
     * insert. Post-commit: `COACH_ASSIGNMENT_OVERRIDDEN` (actor = trainer,
     * subject = coach).
     */
    public function record(CoachAssignmentOverrideRequest $request, User $coach, User $trainer): CoachAssignmentOverride
    {
        $reason = trim($request->reason);

        if ('' === $reason) {
            throw new MissingOverrideReasonException();
        }

        if (UserRole::COACH !== $coach->getRole() || !$coach->isActive()) {
            throw new CoachActionNotPermittedException();
        }

        if (UserRole::TRAINER !== $trainer->getRole() || !$trainer->isActive()) {
            throw new CoachActionNotPermittedException();
        }

        $association = $this->trainerCoachAssociationRepository->findActiveForCoach($coach);

        if (null === $association || $association->getTrainer() !== $trainer) {
            throw new CoachActionNotPermittedException();
        }

        $candidate = new TimeRange($request->startsAtMinute, $request->endsAtMinute);
        $coverage = $this->coachAvailabilityService->evaluate($coach, $request->dayOfWeek, $candidate);

        $override = new CoachAssignmentOverride(
            $coach,
            $trainer,
            $request->dayOfWeek,
            $request->startsAtMinute,
            $request->endsAtMinute,
            $coverage,
            $reason,
        );

        $entityManager = $this->managerRegistry->getManagerForClass(CoachAssignmentOverride::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $entityManager->wrapInTransaction(function () use ($entityManager, $override): void {
            $entityManager->persist($override);
        });

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::COACH_ASSIGNMENT_OVERRIDDEN,
            actorUserId: $trainer->getId(),
            subjectUserId: $coach->getId(),
        ));

        return $override;
    }

    /**
     * AC-8's "queryable later" as a service-layer surface, not only a
     * repository one. Thin delegation to the repository.
     *
     * @return list<CoachAssignmentOverride>
     */
    public function findForCoach(User $coach): array
    {
        return $this->overrideRepository->findForCoach($coach);
    }

    /**
     * @return list<CoachAssignmentOverride>
     */
    public function findForTrainer(User $trainer): array
    {
        return $this->overrideRepository->findForTrainer($trainer);
    }
}
