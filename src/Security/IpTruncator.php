<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Truncates a client IP to the block LoginRateLimiter's `login_source`
 * limiter keys on: a /24 for IPv4 (zero the last octet), a /48 for IPv6
 * (zero the last 80 bits -- Task 38 hardening fix, widened from the
 * original /64. A /64 zeroes only the interface identifier, the part a
 * single host can rotate freely under privacy extensions/SLAAC, but a /64
 * is also the size ISPs commonly hand a single *subscriber*, not just a
 * single device -- keying that narrowly let every device behind one
 * subscriber's router count as a different source. /48 is the block size
 * realistically delegated to one subscriber site, so it is what actually
 * groups "the same real-world source" together). Truncating means a host
 * that rotates its address within the same block still shares one counter,
 * per the architecture's note on `login_source`.
 *
 * Both prefixes land on a byte boundary (24 = 3 bytes of 4, 48 = 6 bytes of
 * 16), so truncation is "keep the leading N bytes, zero the rest" -- no bit
 * masking inside a byte is needed, which is deliberately simple to keep the
 * one place this is easy to get wrong (the bitmask) easy to verify.
 *
 * A pure function with no collaborators, on purpose: it is exercised by its
 * own unit test with concrete address/result vectors, standalone from
 * LoginRateLimiter and the request stack.
 */
final class IpTruncator
{
    private function __construct()
    {
        // Not instantiable -- a static pure function, not a service.
    }

    /**
     * @param string $ip a client IP address, IPv4 or IPv6, textual form
     *
     * @return string the truncated address, textual form; an address
     *                 `inet_pton()` cannot parse is returned unchanged
     *                 rather than throwing, so a malformed
     *                 `Request::getClientIp()` never breaks the login
     *                 endpoint -- it degrades to keying on the raw value
     */
    public static function truncate(string $ip): string
    {
        $packed = @inet_pton($ip);

        if (false === $packed) {
            return $ip;
        }

        // 4 bytes (32 bits) for IPv4 -> keep 3 (/24); 16 bytes (128 bits)
        // for IPv6 -> keep 6 (/48).
        $length = \strlen($packed);
        $keepBytes = 4 === $length ? 3 : 6;

        $truncated = substr($packed, 0, $keepBytes).str_repeat("\0", $length - $keepBytes);

        $result = inet_ntop($truncated);

        return false !== $result ? $result : $ip;
    }
}
