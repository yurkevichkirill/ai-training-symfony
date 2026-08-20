<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A freshly generated selector/verifier pair, as produced by
 * `SelectorVerifierTokenFactory::generate()`. `token` is `selector.verifier`
 * -- the raw string to embed in a link; only `hashedVerifier` is ever
 * persisted.
 */
final readonly class SelectorVerifierPair
{
    public function __construct(
        public string $selector,
        public string $verifier,
        public string $hashedVerifier,
        public string $token,
    ) {
    }
}
