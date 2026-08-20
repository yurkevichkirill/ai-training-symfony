<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachInvitationService::resolve()` and `::accept()` when the
 * token string does not resolve to a real, matching row -- a malformed
 * token, an unknown selector, or a verifier that does not `hash_equals()`
 * the stored hash (AC-3, AC-18). Mirrors `InvalidAccountInvitationException`;
 * carries no `CoachInvitation` for the same reason.
 */
final class InvalidCoachInvitationException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation link is invalid.', previous: $previous);
    }
}
