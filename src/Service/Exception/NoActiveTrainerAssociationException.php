<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `PlayerShareLinkService::leave()` when the signed-in player has
 * no currently-active association with the given trainer to end -- either
 * they were never connected, or they already left. Deliberately a refusal,
 * not a silent no-op: `AccountLifecycleService::deactivate()`/`reactivate()`
 * establish the same "an invalid state transition is a typed exception, not
 * a quiet success" convention this project follows throughout, and a
 * double-submitted "Leave" click (the only realistic way to hit this) is
 * safely absorbed by the controller rendering a flash message, not by the
 * service pretending the second click did something.
 */
final class NoActiveTrainerAssociationException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('You are not currently connected with this trainer.', previous: $previous);
    }
}
