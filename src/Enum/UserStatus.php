<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle state of an account.
 *
 * S1 only honoured these values (a DEACTIVATED account cannot sign in); S2
 * gives administrators a way to set them, and adds DELETED: a terminal state
 * reached only through GDPR anonymization (AccountLifecycleService::delete()),
 * from which there is no reactivation path (AC-20).
 */
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DEACTIVATED = 'DEACTIVATED';
    case DELETED = 'DELETED';
}
