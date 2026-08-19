<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PasswordResetService::request()` (and reusable by anything else
 * consuming the shared `password_reset_source` limiter, AC-20) when the
 * *source* (client IP) limiter is exhausted.
 *
 * Deliberately the **only** rate-limit outcome this service ever surfaces as
 * an exception. An exhausted *account* limiter must never throw -- the
 * architecture is explicit that a 429 there would announce that the address
 * exists, so `request()` swallows that case and still proceeds to let the
 * controller render the generic check-email outcome (AC-11). The source
 * limiter is independent of any one account, so a 429 for it discloses
 * nothing about which addresses are registered; the controller may
 * therefore branch on this exception to return 429 instead of the
 * check-email page.
 */
final class SourceRateLimitExceededException extends \RuntimeException
{
    public function __construct(private readonly \DateTimeImmutable $retryAfter, ?\Throwable $previous = null)
    {
        parent::__construct('Too many password reset requests from this source.', previous: $previous);
    }

    public function getRetryAfter(): \DateTimeImmutable
    {
        return $this->retryAfter;
    }
}
