<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PlayerShareLinkService::associateWithTrainer()` and `leave()`
 * when the acting user is the very child account the association is about
 * (AC-14): a child cannot connect itself to a trainer, or end one of its own
 * trainer connections, through any route -- signed-in UI action or a forged
 * request that skips the controller entirely. `PlayerActionVoter` refuses
 * the same thing at the HTTP edge; this guard is what still makes it true
 * for a caller that never passes through that voter (the spec's "child
 * forges a POST to the trainer add/remove route" edge case).
 */
final class ChildActionNotPermittedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('A child account cannot perform this action.', previous: $previous);
    }
}
