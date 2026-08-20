<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachInvitationService::resolve()` and `::accept()` /
 * `CoachRegistrationService::registerAndAccept()` when a coach invitation's
 * `acceptedAt` is already set and there is no active `(trainer, coach)`
 * association left to return as an idempotent success (AC-18's "already
 * used", distinct from `CoachInvitationExpiredException`'s "expired"). The
 * trainer is expected to send a fresh invitation to the same address.
 */
final class CoachInvitationAlreadyAcceptedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation has already been used.', previous: $previous);
    }
}
