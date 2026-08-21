<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachAssignmentOverrideService::record()` when the submitted
 * reason is empty or whitespace-only (AC-7's "recording without a reason is
 * refused"). This is the first of two layers (D3d): the service checks
 * before the insert is even attempted, and `coach_assignment_override`'s
 * `CHECK (btrim(reason) <> '')` is the second, database-level layer that
 * holds even if some future caller bypasses this service.
 */
final class MissingOverrideReasonException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('An override reason is required.', previous: $previous);
    }
}
