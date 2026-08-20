<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CoachInvitation;
use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The HTTP-edge eligibility check for the two public ShareLink landing
 * pages (AC-20, AC-21): `FOLLOW_PLAYER_SHARE_LINK` (subject
 * `PlayerShareLink`) and `ACCEPT_COACH_INVITATION` (subject
 * `CoachInvitation`). Controllers call `denyAccessUnlessGranted()` with
 * these ahead of any service work, so an ineligible signed-in visitor gets
 * a refusal before `PlayerShareLinkService::associate()` /
 * `CoachInvitationService::accept()` ever run -- defence in depth, not the
 * sole guard: both services re-check the identical rules regardless (the
 * architecture's Decisions Q4), since a voter can be bypassed by a caller
 * that never reaches this controller path, but a service guard cannot.
 *
 * Votes only on `User::role`, `User::status` (via `isActive()`), and (for
 * the coach attribute) email equality against `invited_email`. Reads no
 * `Profile` -- S1's frozen "role is on User, capability is on Profile,
 * neither reads the other for authorization" invariant holds unchanged.
 *
 * Only ever meaningfully consulted for a signed-in visitor: an anonymous
 * follow is routed to the registration form by the controller before any
 * `denyAccessUnlessGranted()` call, never through this voter. A non-`User`
 * subject (this voter's `$token->getUser()`) abstains, matching every other
 * voter shape in a Symfony app that expects a fully authenticated subject.
 */
final class ShareLinkVoter extends Voter
{
    public const FOLLOW_PLAYER_SHARE_LINK = 'FOLLOW_PLAYER_SHARE_LINK';
    public const ACCEPT_COACH_INVITATION = 'ACCEPT_COACH_INVITATION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::FOLLOW_PLAYER_SHARE_LINK => $subject instanceof PlayerShareLink,
            self::ACCEPT_COACH_INVITATION => $subject instanceof CoachInvitation,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            // AC-20: only a PLAYER, and only an active account, may follow a
            // player ShareLink -- a signed-in Coach, Trainer, or Super Admin
            // (any other role) is refused outright, and so is a
            // DEACTIVATED/DELETED player.
            self::FOLLOW_PLAYER_SHARE_LINK => $subject instanceof PlayerShareLink
                && UserRole::PLAYER === $user->getRole()
                && $user->isActive(),

            // AC-21: only a COACH, only an active account, and only the
            // exact (already-normalized) email the invitation was sent to
            // -- refuses a different email and a signed-in Player alike.
            self::ACCEPT_COACH_INVITATION => $subject instanceof CoachInvitation
                && UserRole::COACH === $user->getRole()
                && $user->isActive()
                && $user->getEmail() === $subject->getInvitedEmail(),

            default => false,
        };
    }
}
