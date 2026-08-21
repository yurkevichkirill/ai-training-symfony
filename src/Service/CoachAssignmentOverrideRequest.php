<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Input to `CoachAssignmentOverrideService::record()` (AC-7): the candidate
 * time that conflicted and the required reason. A plain readonly DTO, no
 * Form -- this capability has no HTTP surface in this slice (D3c).
 *
 * Note for the record: Epic-02 will add `?Uuid $eventId = null` as a
 * defaulted trailing constructor parameter later (D3b) -- no existing call
 * site changes when it does.
 */
final readonly class CoachAssignmentOverrideRequest
{
    public function __construct(
        public int $dayOfWeek,
        public int $startsAtMinute,
        public int $endsAtMinute,
        public string $reason,
    ) {
    }
}
