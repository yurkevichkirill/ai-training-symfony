<?php

declare(strict_types=1);

namespace App\Service;

use App\Availability\CoverageEvaluator;
use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\CoachAvailabilitySlot;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\AvailabilityCoverage;
use App\Enum\UserRole;
use App\Repository\CoachAvailabilitySlotRepository;
use App\Service\Exception\CoachActionNotPermittedException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A coach's own weekly availability grid, read/write, plus the AC-6
 * conflict lookup wrapped around it (AC-1...AC-4, AC-6; D2, D2b).
 *
 * `replaceWeek()` is the *only* writer of `coach_availability_slot`
 * ({@see CoachAvailabilitySlot}'s own docblock). One transaction:
 * `CoachAvailabilitySlotRepository::replaceWeekFor()`'s delete-then-insert,
 * scoped by `coach_id` -- so a save for one coach cannot read or write
 * another coach's rows, or any `player_availability_slot` row (AC-2, AC-3's
 * isolation is that `WHERE` clause, not a diffing algorithm). The submitted
 * grid is normalized ({@see WeeklyAvailability::normalized()}) before it is
 * persisted. Post-commit, `COACH_AVAILABILITY_UPDATED` is recorded with
 * actor = subject = the coach.
 */
final class CoachAvailabilityService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CoachAvailabilitySlotRepository $slotRepository,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly CoverageEvaluator $coverageEvaluator,
    ) {
    }

    /**
     * AC-2, AC-3, AC-4, AC-15. Service guard (defence in depth, S3's Q4
     * pattern): refuses unless `$coach` is an active `UserRole::COACH` and
     * `$actor === $coach` -- this is what makes AC-15 and the
     * forged-request edge case hold for any caller that never passes
     * through a controller.
     */
    public function replaceWeek(User $coach, WeeklyAvailability $week, User $actor): void
    {
        if (UserRole::COACH !== $coach->getRole() || !$coach->isActive() || $actor !== $coach) {
            throw new CoachActionNotPermittedException();
        }

        $normalized = $week->normalized();
        $now = new \DateTimeImmutable();

        $slots = [];
        foreach ($normalized->toArray() as $dayOfWeek => $ranges) {
            foreach ($ranges as $range) {
                \assert($range instanceof TimeRange);
                $slots[] = new CoachAvailabilitySlot($coach, $dayOfWeek, $range->startsAtMinute, $range->endsAtMinute, $now);
            }
        }

        $entityManager = $this->managerRegistry->getManagerForClass(CoachAvailabilitySlot::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $entityManager->wrapInTransaction(function () use ($coach, $slots): void {
            $this->slotRepository->replaceWeekFor($coach, $slots);
        });

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::COACH_AVAILABILITY_UPDATED,
            actorUserId: $actor->getId(),
            subjectUserId: $coach->getId(),
        ));
    }

    /**
     * The grid read: every slot for `$coach`, grouped back into a
     * {@see WeeklyAvailability}. A day with no rows comes back as that
     * day's empty range list -- "not available" (AC-1), not a third state.
     */
    public function weekFor(User $coach): WeeklyAvailability
    {
        $rangesByDay = [];

        foreach ($this->slotRepository->weekFor($coach) as $slot) {
            $rangesByDay[$slot->getDayOfWeek()][] = new TimeRange($slot->getStartsAtMinute(), $slot->getEndsAtMinute());
        }

        return new WeeklyAvailability($rangesByDay);
    }

    /**
     * AC-6. Read-only, no write, no event: a pure lookup wrapped around
     * {@see CoverageEvaluator::evaluate()}.
     */
    public function evaluate(User $coach, int $dayOfWeek, TimeRange $candidate): AvailabilityCoverage
    {
        return $this->coverageEvaluator->evaluate($this->weekFor($coach), $dayOfWeek, $candidate);
    }
}
