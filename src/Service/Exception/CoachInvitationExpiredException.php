<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachInvitationService::resolve()` and `::accept()` /
 * `CoachRegistrationService::registerAndAccept()` when the verifier matched
 * a row whose `expiresAt` (createdAt + P7D) has passed (AC-3, AC-18's
 * "expired", distinct from `CoachInvitationAlreadyAcceptedException`'s
 * "already used"). There is no idempotent-success case here: only a fresh
 * invitation, re-sent by the trainer, unblocks the address.
 */
final class CoachInvitationExpiredException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation has expired.', previous: $previous);
    }
}
