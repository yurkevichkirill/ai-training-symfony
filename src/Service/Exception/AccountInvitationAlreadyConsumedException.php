<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by AccountInvitationService::consume() when the row's consumedAt is
 * already set: the trainer already set a password through this link (AC-6,
 * the "invitation link opened after already used" edge case).
 */
final class AccountInvitationAlreadyConsumedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation link has already been used.', previous: $previous);
    }
}
