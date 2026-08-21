<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * S6 (Super Admin Impersonation): the who-may-impersonate-whom and
 * who-may-read-the-report policy, on the two attributes below.
 *
 * `ROLE_ALLOWED_TO_SWITCH` is deliberately the exact attribute name
 * `security.yaml`'s `switch_user.role` names. `SwitchUserListener` calls
 * the access decision manager with *the target user as the subject*, so
 * putting the rule in a voter on that attribute means the framework
 * enforces our rule directly -- there is no parallel check to keep in
 * sync (architecture D5). The same call is reused verbatim in the
 * Users-directory template (`is_granted('ROLE_ALLOWED_TO_SWITCH', user)`),
 * so AC-1's visibility and AC-3's refusal cannot drift apart.
 *
 * **Nobody holds `ROLE_ALLOWED_TO_SWITCH` as a role.** `role_hierarchy` is
 * flat and untouched, so under the default affirmative strategy this voter
 * is the only thing that can ever grant it -- `ImpersonationVoterTest`
 * asserts that explicitly (architecture Risks: "`ROLE_ALLOWED_TO_SWITCH` in
 * `role_hierarchy` would silently kill BR-002").
 *
 * Reads only `User::role`, `User::status`, and the current token's class --
 * no `Profile`, preserving S1's "authorization never reads a Profile"
 * invariant.
 */
final class ImpersonationVoter extends Voter
{
    public const ROLE_ALLOWED_TO_SWITCH = 'ROLE_ALLOWED_TO_SWITCH';
    public const VIEW_IMPERSONATION_HISTORY = 'VIEW_IMPERSONATION_HISTORY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::ROLE_ALLOWED_TO_SWITCH => $subject instanceof User,
            self::VIEW_IMPERSONATION_HISTORY => true,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $tokenUser = $token->getUser();

        if (!$tokenUser instanceof User) {
            return false;
        }

        $isSuperAdmin = UserRole::SUPER_ADMIN === $tokenUser->getRole() && $tokenUser->isActive();

        return match ($attribute) {
            // BR-001, BR-002, AC-1, AC-3: the token user must be an ACTIVE
            // Super Admin, not already impersonating (no nesting), and the
            // target must be neither a Super Admin, inactive, nor the token
            // user itself -- the two clauses "subject is not SUPER_ADMIN"
            // and "subject is not the token user" are each independently
            // sufficient for the "impersonate my own row" edge case, which
            // is why both are written.
            self::ROLE_ALLOWED_TO_SWITCH => $subject instanceof User
                && $isSuperAdmin
                && !$token instanceof SwitchUserToken
                && UserRole::SUPER_ADMIN !== $subject->getRole()
                && $subject->isActive()
                && $subject !== $tokenUser,

            // AC-12: only an ACTIVE Super Admin may view the report.
            self::VIEW_IMPERSONATION_HISTORY => $isSuperAdmin,

            default => false,
        };
    }
}
