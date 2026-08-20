<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PlayerShareLinkResolver::resolve()` for both an unknown `code`
 * and a code whose owning trainer is no longer `ACTIVE`, and by
 * `PlayerShareLinkService::associate()`'s belt-and-braces re-check of the
 * same trainer-active fact. Both causes render the identical "this
 * invitation is no longer available" outcome -- deliberately
 * non-enumerating, so a visitor cannot tell an unknown code from a
 * deactivated trainer's link (AC-1, edge case: trainer deactivated/deleted).
 */
final class ShareLinkUnavailableException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        // Task 33 hardening fix: without this default message,
        // `getMessage()` returned '' and the coach-invitation
        // "trainer deactivated" refusal page rendered a blank alert.
        // Deliberately the same wording `share_link/unavailable.html.twig`
        // already used verbatim for this exception's other call site
        // (PlayerShareLinkResolver::resolve()), so both render paths agree.
        parent::__construct('This invitation link is no longer available.', previous: $previous);
    }
}
