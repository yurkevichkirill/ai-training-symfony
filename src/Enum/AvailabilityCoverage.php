<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three-way answer `App\Availability\CoverageEvaluator::evaluate()`
 * returns (AC-6): whether a candidate time range falls entirely within,
 * partially within, or entirely outside a coach's saved availability.
 * Backs `coach_assignment_override.coverage` -- what AC-7's override record
 * stores is the coverage the service itself evaluated, not a caller-supplied
 * verdict.
 */
enum AvailabilityCoverage: string
{
    case FULLY_AVAILABLE = 'FULLY_AVAILABLE';
    case PARTIALLY_AVAILABLE = 'PARTIALLY_AVAILABLE';
    case UNAVAILABLE = 'UNAVAILABLE';
}
