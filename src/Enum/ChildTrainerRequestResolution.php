<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a parent resolved a pending `ChildTrainerRequest` (AC-17). `null` on
 * the entity's own `resolution` column, paired with a `null` `resolvedAt`,
 * means still pending -- the hand-written
 * `CHECK ((resolved_at IS NULL) = (resolution IS NULL))` makes a
 * half-resolved row unstorable, so this enum only ever appears once a
 * request has actually been acted on.
 */
enum ChildTrainerRequestResolution: string
{
    case APPROVED = 'APPROVED';
    case DISMISSED = 'DISMISSED';
}
