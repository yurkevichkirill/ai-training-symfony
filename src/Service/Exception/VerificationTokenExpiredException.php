<?php

declare(strict_types=1);

namespace App\Service\Exception;

use App\Entity\User;

/**
 * Thrown by EmailVerificationTokenService::consume() when the verifier
 * matched a row whose `expiresAt` has passed (more than 24 hours after
 * issue -- AC-14). Unlike an already-consumed token, an expired-but-unused
 * token is always a hard failure: there is no idempotent-success case for it,
 * only "request a new one" (per the spec's FR-005 / BR-003).
 *
 * Carries the token's `User` for callers that want to surface which account
 * needs a fresh link; EmailVerificationService::consume() currently lets this
 * propagate unchanged.
 */
final class VerificationTokenExpiredException extends \RuntimeException
{
    public function __construct(private readonly User $user, ?\Throwable $previous = null)
    {
        parent::__construct('This verification token has expired.', previous: $previous);
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
