<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Administrative account-management events (S2), recorded via
 * `AccountEventRecorder`. Distinct from `AuthEventType`: an AuthEvent's actor
 * and subject are always the same person (you signed yourself in); an
 * AccountEvent is one user acting on another (or on themselves, for a
 * profile edit), which is why AccountEvent carries both an actor and a
 * subject.
 */
enum AccountEventType: string
{
    case TRAINER_CREATED = 'TRAINER_CREATED';
    case USER_DEACTIVATED = 'USER_DEACTIVATED';
    case USER_REACTIVATED = 'USER_REACTIVATED';
    case USER_DELETED = 'USER_DELETED';
    case PROFILE_UPDATED = 'PROFILE_UPDATED';

    /**
     * S3 (ShareLink Invitations, AC-9): the new player's own self-action,
     * recorded after PlayerRegistrationService::registerViaShareLink()'s
     * transaction commits. Actor = subject = the new player.
     */
    case PLAYER_REGISTERED_VIA_SHARE_LINK = 'PLAYER_REGISTERED_VIA_SHARE_LINK';

    /**
     * S3 (AC-15): written by CoachInvitationService::accept(), post-commit,
     * with context {trainerId, invitationId}. Not written for `invite()` --
     * there is no User subject yet for an invited address (see architecture
     * Decisions, "Audit event for invitation sent").
     */
    case COACH_INVITATION_ACCEPTED = 'COACH_INVITATION_ACCEPTED';

    /**
     * S3 (AC-9, AC-15): written by PlayerShareLinkService::associate(),
     * post-commit, only on a genuinely new TrainerPlayerAssociation row --
     * mirroring PlayerShareLink::incrementUsage()'s same new-row-only
     * condition. Actor = subject = the player.
     */
    case PLAYER_TRAINER_ASSOCIATED = 'PLAYER_TRAINER_ASSOCIATED';
}
