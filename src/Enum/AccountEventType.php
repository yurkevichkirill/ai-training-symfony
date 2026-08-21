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

    /**
     * S4 (Player/Family Availability, AC-1, AC-2, AC-6): written by
     * ChildAccountService::createChild(), post-commit, with context
     * {trainerCount}. Actor = parent, subject = the newly created child.
     */
    case CHILD_ACCOUNT_CREATED = 'CHILD_ACCOUNT_CREATED';

    /**
     * S4 (AC-4, AC-8, AC-17): written by ChildTrainerService::connect(),
     * post-commit, with context {trainerId}. Actor = parent, subject = child.
     */
    case CHILD_TRAINER_CONNECTED = 'CHILD_TRAINER_CONNECTED';

    /**
     * S4 (AC-9): written by ChildTrainerService::disconnect(), post-commit.
     * Actor = parent, subject = child.
     */
    case CHILD_TRAINER_DISCONNECTED = 'CHILD_TRAINER_DISCONNECTED';

    /**
     * S4 (AC-15, AC-16): written by ChildTrainerService::recordBlockedClick(),
     * post-commit. Actor = subject = the child (a self-action, the same
     * shape as PLAYER_TRAINER_ASSOCIATED).
     */
    case CHILD_SHARE_LINK_BLOCKED = 'CHILD_SHARE_LINK_BLOCKED';

    /**
     * S4 (D1d -- infrastructure for AC-13...AC-18's "a signed-in child
     * account"): written by ChildAccountService::enableSignIn(),
     * post-commit. Actor = parent, subject = child.
     */
    case CHILD_SIGN_IN_ENABLED = 'CHILD_SIGN_IN_ENABLED';

    /**
     * S4 (AC-19, AC-21): written by AvailabilityService::replaceWeek(),
     * post-commit. Actor = the parent or the player themselves, subject =
     * the player.
     */
    case PLAYER_AVAILABILITY_UPDATED = 'PLAYER_AVAILABILITY_UPDATED';

    /**
     * S5 (Coach Features, AC-4): written by
     * CoachAvailabilityService::replaceWeek(), post-commit. Actor = subject
     * = the coach.
     */
    case COACH_AVAILABILITY_UPDATED = 'COACH_AVAILABILITY_UPDATED';

    /**
     * S5 (AC-7, AC-8): written by CoachAssignmentOverrideService::record(),
     * post-commit. Actor = the trainer who overrode, subject = the coach.
     */
    case COACH_ASSIGNMENT_OVERRIDDEN = 'COACH_ASSIGNMENT_OVERRIDDEN';

    /**
     * S6 (Super Admin Impersonation, AC-10): written by
     * `ImpersonationService::start()`, post-commit. Actor = the Super
     * Admin, subject = the impersonated user. Context
     * `{impersonationSessionId, expiresAt, subjectRole}`.
     */
    case IMPERSONATION_STARTED = 'IMPERSONATION_STARTED';

    /**
     * S6 (AC-9, AC-10): written by `ImpersonationService::end()`,
     * post-commit, only when the close actually affected a row (D4b). Same
     * actor/subject as IMPERSONATION_STARTED. Context
     * `{impersonationSessionId, endReason, durationSeconds}`.
     *
     * No IMPERSONATION_REFUSED case (D6c): a refusal is a 403 plus
     * Symfony's own security log, not an audit row -- see the architecture
     * doc's Risks section.
     */
    case IMPERSONATION_ENDED = 'IMPERSONATION_ENDED';
}
