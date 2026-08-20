<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by AccountInvitationService::consume() when the verifier matched a
 * row whose expiresAt has passed (7 days after issue -- AC-6). There is no
 * idempotent-success case here: only a fresh invitation, re-issued by a
 * Super Admin, unblocks the account.
 */
final class AccountInvitationExpiredException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation link has expired.', previous: $previous);
    }
}
