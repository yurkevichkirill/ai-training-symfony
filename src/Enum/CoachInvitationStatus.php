<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * AC-17's derived invitation state -- Pending, Accepted, or Expired -- as
 * returned by `CoachInvitation::status()`. Deliberately not a stored column
 * (architecture Decisions Q1b'): Expired is purely a function of the clock,
 * so a stored value would need a scheduled sweep to stay truthful and would
 * create a second source of truth that can disagree with it.
 */
enum CoachInvitationStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case EXPIRED = 'EXPIRED';
}
