<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by EmailVerificationTokenService::consume() when the token string
 * does not resolve to a real, matching row: the selector is unknown, or the
 * selector exists but the supplied verifier's `hash('sha256', ...)` does not
 * `hash_equals()` the stored `hashedVerifier` (AC-13, AC-14).
 *
 * Deliberately carries no `User` -- unlike
 * {@see VerificationTokenAlreadyConsumedException} and
 * {@see VerificationTokenExpiredException}, this case is reached *before* the
 * verifier is proven to belong to whoever holds this token, so there is
 * nothing here that is safe to disclose about which account (if any) the
 * selector belongs to.
 */
final class InvalidVerificationTokenException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The verification token is invalid.', previous: $previous);
    }
}
