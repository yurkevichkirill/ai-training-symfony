<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `ImpersonationService::start()` when any of its guards fails --
 * defence in depth alongside `ImpersonationVoter` (S3's Q4 / S5's D4
 * convention: the voter is what the framework consults, this is what
 * survives a console command or a future caller that never passes the
 * listener). Namespaced under `App\Service\Exception` to match this
 * repo's existing convention for service-layer domain exceptions (see
 * `ChildActionNotPermittedException`, `InvalidAccountStateTransitionException`),
 * rather than a new top-level `App\Exception` namespace.
 */
final class ImpersonationNotPermittedException extends \RuntimeException
{
    public function __construct(string $message = 'Impersonation of this account is not permitted.', ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
