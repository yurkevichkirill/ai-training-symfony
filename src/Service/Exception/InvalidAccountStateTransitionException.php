<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by AccountLifecycleService when a requested state change does not
 * apply to the account's current status -- e.g. reactivating a DELETED
 * account, deactivating one that is already DELETED, or deleting one that
 * is already DELETED (AC-20, AC-23, and the deactivate-a-deleted-account
 * edge case).
 */
final class InvalidAccountStateTransitionException extends \RuntimeException
{
}
