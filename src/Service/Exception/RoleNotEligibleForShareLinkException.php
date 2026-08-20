<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PlayerShareLinkService::associate()` when the signed-in account
 * following a player ShareLink is not a Player (AC-20): a player link only
 * ever creates or extends a Player-role association, so a Coach, Trainer, or
 * Super Admin account is refused outright rather than being silently given a
 * second role or a Player association bolted onto a different one.
 */
final class RoleNotEligibleForShareLinkException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        // Task 33 hardening fix: a default message so the rendered refusal
        // is never a blank alert.
        parent::__construct('This link is not available for your account type.', previous: $previous);
    }
}
