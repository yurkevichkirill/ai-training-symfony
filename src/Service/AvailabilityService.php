<?php

declare(strict_types=1);

namespace App\Service;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\PlayerAvailabilitySlot;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Repository\PlayerAvailabilitySlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The weekly availability grid read/write (S4, AC-19...AC-21, AC-24; D5,
 * D5c, D5d): **one grid per player**, never per (player, trainer) pairing --
 * visible to every trainer that player is connected to, not answered once
 * per connection (D5d).
 *
 * `replaceWeek()` is the *only* writer of `player_availability_slot`
 * ({@see PlayerAvailabilitySlot}'s own docblock). One transaction:
 * `PlayerAvailabilitySlotRepository::replaceWeekFor()`'s delete-then-insert,
 * scoped by `player_id` -- so a save for one child cannot read or write
 * another player's rows (AC-20's isolation is that `WHERE` clause, not a
 * diffing algorithm). The submitted grid is normalized ({@see
 * WeeklyAvailability::normalized()}) before it is persisted, so two
 * submissions describing the same availability produce byte-identical rows
 * (D5c) and an empty day stores as zero rows, never a placeholder (D5,
 * AC-24). Post-commit, `PLAYER_AVAILABILITY_UPDATED` is recorded with
 * `actor` = the parent or the player themselves and `subject` = the player.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly PlayerAvailabilitySlotRepository $slotRepository,
        private readonly AccountEventRecorder $accountEventRecorder,
    ) {
    }

    /**
     * AC-19, AC-20, AC-21, AC-24. `$actor` is the parent acting for a child,
     * or the player themselves acting for their own grid -- never validated
     * here; that authorization boundary is `AvailabilityVoter::EDIT_AVAILABILITY`
     * at the edge, not this service's concern.
     */
    public function replaceWeek(User $player, WeeklyAvailability $week, User $actor): void
    {
        $normalized = $week->normalized();
        $now = new \DateTimeImmutable();

        $slots = [];
        foreach ($normalized->toArray() as $dayOfWeek => $ranges) {
            foreach ($ranges as $range) {
                \assert($range instanceof TimeRange);
                $slots[] = new PlayerAvailabilitySlot($player, $dayOfWeek, $range->startsAtMinute, $range->endsAtMinute, $now);
            }
        }

        $entityManager = $this->managerRegistry->getManagerForClass(PlayerAvailabilitySlot::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $entityManager->wrapInTransaction(function () use ($player, $slots): void {
            $this->slotRepository->replaceWeekFor($player, $slots);
        });

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::PLAYER_AVAILABILITY_UPDATED,
            actorUserId: $actor->getId(),
            subjectUserId: $player->getId(),
        ));
    }

    /**
     * The grid read: every slot for `$player`, grouped back into a
     * {@see WeeklyAvailability}. A day with no rows comes back as that day's
     * empty range list -- "Not Available" (AC-24), not a third state.
     */
    public function weekFor(User $player): WeeklyAvailability
    {
        $rangesByDay = [];

        foreach ($this->slotRepository->weekFor($player) as $slot) {
            $rangesByDay[$slot->getDayOfWeek()][] = new TimeRange($slot->getStartsAtMinute(), $slot->getEndsAtMinute());
        }

        return new WeeklyAvailability($rangesByDay);
    }
}
