<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AuthEventType;
use App\Message\SendEmailMessage;
use App\Repository\UserRepository;
use App\Security\IpTruncator;
use App\Service\Exception\VerificationTokenAlreadyConsumedException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The public surface for email verification (AC-13, AC-14): `resend()` is
 * the only account-creation-independent way S1 has to (re)issue a
 * verification link (see the spec's S1-boundary note on AC-13 -- there is no
 * account-creation trigger in this slice), and `consume()` is what a visited
 * verification link calls.
 *
 * **Non-enumeration (AC-11-shaped, by analogy).** `resend()` returns `void`
 * and never throws for "address not found", "already verified", or "rate
 * limit exhausted" -- all four outcomes (found-and-sent, not-found,
 * already-verified, rate-limited) are indistinguishable from the caller's
 * side. The eventual controller (Task 27) renders the same "check your
 * email" confirmation regardless, mirroring the reset-password flow's
 * `check-email` page.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailVerificationTokenService $tokenService,
        private readonly MessageBusInterface $messageBus,
        private readonly RateLimiterFactory $passwordResetAccountLimiter,
        private readonly RateLimiterFactory $passwordResetSourceLimiter,
        private readonly RequestStack $requestStack,
        private readonly AuthEventRecorder $authEventRecorder,
    ) {
    }

    /**
     * Consumes the shared `password_reset_account` / `password_reset_source`
     * limiters from `config/packages/rate_limiter.yaml` (AC-20) -- the same
     * pair `PasswordResetService` (Task 30) consumes, per the architecture's
     * "one pair of limiters, reused" decision, not four near-duplicates.
     *
     * Keying deliberately matches the architecture doc literally:
     * `password_reset_account` is keyed on the normalized email as-is (no
     * extra `hash($email . $secret)` step the way `LoginRateLimiter` keys
     * `login_account` -- the architecture only specifies that extra
     * confidentiality step for the login limiter, not this one);
     * `RateLimiterFactory`/`CacheStorage` already sha1-hashes every limiter
     * id before it reaches the cache pool, which is what keeps the raw email
     * safe as a cache key. `password_reset_source` is keyed on the client IP
     * truncated the same way `login_source` is (`IpTruncator`), read off the
     * current request via `RequestStack` since this method -- unlike
     * `AbstractRequestRateLimiter`-based limiters -- is not itself bound to a
     * `Request`.
     *
     * An exhausted limiter (either one) is handled by silently returning,
     * never by throwing: nothing about *why* a caller gets the same "sent"
     * outcome may be observable, so a rate-limited caller and a
     * successfully-processed caller are indistinguishable from here up.
     */
    public function resend(string $emailInput): void
    {
        $normalizedEmail = User::normalizeEmail($emailInput);

        $accountLimit = $this->passwordResetAccountLimiter->create($normalizedEmail)->consume();

        $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '';
        $sourceLimit = $this->passwordResetSourceLimiter->create(IpTruncator::truncate($clientIp))->consume();

        if (!$accountLimit->isAccepted() || !$sourceLimit->isAccepted()) {
            return;
        }

        $user = $this->userRepository->findOneBy(['email' => $normalizedEmail]);

        if (!$user instanceof User || $user->isEmailVerified()) {
            return;
        }

        $token = $this->tokenService->issue($user);

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $user->getEmail(),
            template: SendEmailMessage::TEMPLATE_VERIFY_EMAIL,
            context: ['token' => $token],
        ));
    }

    /**
     * Delegates to `EmailVerificationTokenService::consume()`.
     *
     * Idempotent re-verification (spec's edge case table: "Verification link
     * opened when the address is already verified" -> treated as success):
     * if the token was already spent but its subject ends up (or already is)
     * verified -- true whenever that earlier consumption is what verified
     * them -- this is a no-op success, not an error, even though the
     * specific token object was already consumed. Any other rejection
     * (invalid token, or a genuinely expired one) propagates unchanged: there
     * is no idempotent-success case for those.
     *
     * **Audit wiring (Task 34, AC-24).** `EMAIL_VERIFIED` is recorded only
     * on the branch that actually just verified the address --
     * `tokenService->consume()` has already committed its own transaction by
     * the time this returns, so recording here is "after success", the same
     * principle `PasswordResetService::complete()` follows. The idempotent
     * already-verified branch does not record a second event: nothing
     * changed on this call, and the genuine verification was already
     * recorded when it happened.
     */
    public function consume(string $token): void
    {
        try {
            $user = $this->tokenService->consume($token);
        } catch (VerificationTokenAlreadyConsumedException $e) {
            if ($e->getUser()->isEmailVerified()) {
                return;
            }

            throw $e;
        }

        $currentRequest = $this->requestStack->getCurrentRequest();

        $this->authEventRecorder->record(new AuthEventRecord(
            type: AuthEventType::EMAIL_VERIFIED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user->getId(),
            ip: $currentRequest?->getClientIp(),
            userAgent: $currentRequest?->headers->get('User-Agent'),
        ));
    }
}
