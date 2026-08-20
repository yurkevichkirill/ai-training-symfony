<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A player's self-declared gender (AC-7), backing `profile_player.gender`.
 * The migration's hand-written `CHECK (gender IN (...))` mirrors
 * `app_user.role`'s, enforcing the same closed domain at the storage level
 * so an unrepresented value is unstorable even if some future code bypasses
 * the entity.
 */
enum PlayerGender: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
    case OTHER = 'OTHER';
    case PREFER_NOT_TO_SAY = 'PREFER_NOT_TO_SAY';
}
