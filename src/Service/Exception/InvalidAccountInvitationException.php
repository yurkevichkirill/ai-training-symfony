<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by AccountInvitationService::consume() when the token string does
 * not resolve to a real, matching row -- unknown selector, or a verifier that
 * does not hash_equals() the stored hash (AC-6). Mirrors
 * InvalidVerificationTokenException; carries no User for the same reason.
 */
final class InvalidAccountInvitationException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation link is invalid.', previous: $previous);
    }
}
