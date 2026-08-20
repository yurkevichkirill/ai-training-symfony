<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachInvitationService::accept()` and
 * `CoachRegistrationService::registerAndAccept()` (AC-16) when the coach
 * already has a *currently active* `TrainerCoachAssociation` with a
 * *different* trainer than this invitation's. A coach that has ended its
 * association with a previous trainer (`endedAt` set) is not blocked by
 * this -- only a currently-active one counts, exactly as the partial unique
 * index `uniq_trainer_coach_active_coach (coach_id) WHERE ended_at IS NULL`
 * enforces at the storage level. This exception is raised both by the
 * service-level pre-check (`TrainerCoachAssociationRepository::findActiveForCoach()`)
 * and by the catch block that converts a caught
 * `Doctrine\DBAL\Exception\UniqueConstraintViolationException` on that same
 * index into the identical typed outcome.
 */
final class CoachAlreadyActiveElsewhereException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This coach is already actively associated with another trainer.', previous: $previous);
    }
}
