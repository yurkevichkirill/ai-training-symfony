<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PlayerShareLinkService::associate()` when the player is not
 * `isActive()` -- a `DEACTIVATED` or `DELETED` account cannot gain a new
 * trainer association until reactivated (the spec's edge case). Belt-and-
 * braces: `User::isEqualTo()` already ends such a session on its next
 * request, so this guard mainly protects the single request racing that
 * boundary.
 */
final class AccountNotEligibleException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        // Task 33 hardening fix: a default message so the rendered refusal
        // is never a blank alert.
        parent::__construct('Your account is not eligible to use this link.', previous: $previous);
    }
}
