<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The selector/verifier crypto S1's `EmailVerificationTokenService` first
 * implemented (same discipline as `symfonycasts/reset-password-bundle`),
 * extracted so `AccountInvitation` (S2) does not re-derive it a third time.
 * Pure: no persistence, no state.
 *
 * `random_bytes(9)` base64url-encodes to exactly 12 characters (9 is a
 * multiple of 3, so base64 never pads it) -- `SELECTOR_LENGTH` is what a
 * consumer splits a token on, and must stay in lock-step with
 * `SELECTOR_BYTES`.
 */
final class SelectorVerifierTokenFactory
{
    public const SELECTOR_LENGTH = 12;

    private const SELECTOR_BYTES = 9;

    private const VERIFIER_BYTES = 32;

    public function generate(): SelectorVerifierPair
    {
        $selector = self::encodeBase64Url(random_bytes(self::SELECTOR_BYTES));
        $verifier = self::encodeBase64Url(random_bytes(self::VERIFIER_BYTES));

        return new SelectorVerifierPair(
            $selector,
            $verifier,
            self::hash($verifier),
            $selector.$verifier,
        );
    }

    public static function hash(string $verifier): string
    {
        return hash('sha256', $verifier);
    }

    /**
     * Splits a `selector.verifier` token. Returns null if the string is too
     * short to contain a selector at all.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(string $token): ?array
    {
        if (\strlen($token) <= self::SELECTOR_LENGTH) {
            return null;
        }

        return [substr($token, 0, self::SELECTOR_LENGTH), substr($token, self::SELECTOR_LENGTH)];
    }

    private static function encodeBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
