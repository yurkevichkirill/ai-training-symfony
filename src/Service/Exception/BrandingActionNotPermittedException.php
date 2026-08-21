<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `TrainerBrandingService` when the target user is not an active
 * trainer with a `ProfileTrainer`, or the acting user is not that trainer
 * or an active Super Admin (AC-2, BR-001): defence in depth behind
 * `BrandingVoter`, per S3/S5's convention -- the voter refuses the same
 * thing at the HTTP edge, this guard is what still makes it true for a
 * caller that never passes through a controller (a console command, or a
 * future API controller).
 */
final class BrandingActionNotPermittedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This action is not permitted for this trainer.', previous: $previous);
    }
}
