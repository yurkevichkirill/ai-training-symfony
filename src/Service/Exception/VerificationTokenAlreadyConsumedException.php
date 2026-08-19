<?php

declare(strict_types=1);

namespace App\Service\Exception;

use App\Entity\User;

/**
 * Thrown by EmailVerificationTokenService::consume() when the row's
 * `consumedAt` is already set -- the verifier matched, so the subject is
 * known, but this exact token was already spent (single-use, AC-13, AC-14).
 *
 * Carries the token's `User` so EmailVerificationService::consume() can tell
 * a genuinely stale replay apart from the idempotent-re-verification edge
 * case (spec's edge case table: "Verification link opened when the address
 * is already verified" -> treated as success): if that user already ended up
 * verified -- most commonly because this very token was what verified them
 * the first time -- the replay is a no-op success, not an error.
 */
final class VerificationTokenAlreadyConsumedException extends \RuntimeException
{
    public function __construct(private readonly User $user, ?\Throwable $previous = null)
    {
        parent::__construct('This verification token has already been used.', previous: $previous);
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
