<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle state of an account.
 *
 * S1 only honours these values (a DEACTIVATED account cannot sign in); it is
 * S2 that gives administrators a way to set them.
 */
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DEACTIVATED = 'DEACTIVATED';
}
