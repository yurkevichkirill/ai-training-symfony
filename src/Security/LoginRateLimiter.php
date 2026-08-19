<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Wired as `security.firewalls.main.login_throttling.limiter`. Composes two
 * `rate_limiter.yaml` factories at G-22's numbers, not Symfony's defaults:
 *
 * - `login_account`, keyed on `hash('sha256', $normalizedEmail . $appSecret)`
 *   -- 5 attempts / 15 minutes.
 * - `login_source`, keyed on the client IP truncated to a /24 (IPv4) or /64
 *   (IPv6) block via {@see IpTruncator} -- 20 attempts / hour.
 *
 * Both key on the *submitted* identifier (`SecurityRequestAttributes::
 * LAST_USERNAME`, set by Symfony's own `LoginThrottlingListener` from the
 * passport's `UserBadge` before this limiter runs), never on a resolved
 * `User`. That is what keeps throttling from being an enumeration oracle
 * (AC-19): an unknown email is throttled at exactly the rate a real one is.
 * It is also why a *correct* password submitted after the limit trips is
 * still refused -- `login_throttling` runs before the authenticator checks
 * the password, not after.
 *
 * The email is hashed (with the app secret, so the stored limiter state
 * cannot be reversed to a plaintext address); the IP block is used as-is,
 * because `RateLimiterFactory`/`CacheStorage` sha1-hashes every limiter id
 * before it reaches the cache pool, so no separate hashing step is needed
 * to make it a safe cache key.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $loginAccountLimiter,
        private readonly RateLimiterFactory $loginSourceLimiter,
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    protected function getLimiters(Request $request): array
    {
        $submittedEmail = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $normalizedEmail = User::normalizeEmail($submittedEmail);
        $accountKey = hash('sha256', $normalizedEmail.$this->appSecret);

        $sourceKey = IpTruncator::truncate($request->getClientIp() ?? '');

        return [
            $this->loginAccountLimiter->create($accountKey),
            $this->loginSourceLimiter->create($sourceKey),
        ];
    }
}
