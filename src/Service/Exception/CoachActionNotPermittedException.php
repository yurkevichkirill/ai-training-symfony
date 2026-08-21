<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachAvailabilityService::replaceWeek()` and
 * `CoachAssignmentOverrideService::record()` when the acting/subject user is
 * not an active coach, or when a coach is not acting on their own schedule
 * (AC-15): defence in depth, S3's Decision Q4 pattern -- the voter refuses
 * the same thing at the HTTP edge, this guard is what still makes it true
 * for a caller that never passes through a controller (a console command, a
 * future API controller, or a forged request).
 */
final class CoachActionNotPermittedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This action is not permitted for this coach.', previous: $previous);
    }
}
