<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates the plaintext `code` for a `PlayerShareLink` (AC-1, AC-2):
 * 12-char base64url of `random_bytes(9)` -- 9 bytes base64-encodes to
 * exactly 12 characters with no padding, since 9 is a multiple of 3.
 *
 * Deliberately its own ten-line class, not a method on
 * `SelectorVerifierTokenFactory` (architecture Decisions Q1a″): that class's
 * whole purpose is a *paired* secret whose two halves (`SELECTOR_LENGTH` /
 * `SELECTOR_BYTES`) must stay in lock-step, and a player ShareLink code is a
 * single, unpaired, unhashed value with a different security property
 * (unguessable at rest, not single-use) -- adding it there would invite a
 * future edit to break that pairing invariant.
 */
final class ShareLinkCodeGenerator
{
    private const CODE_BYTES = 9;

    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
    }
}
