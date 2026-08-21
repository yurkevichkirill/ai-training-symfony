<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ChildAccountResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Family-management eligibility (Task 23): `MANAGE_FAMILY` (no subject --
 * granted to an active PLAYER who is not a child, gating `/family` itself)
 * and `MANAGE_CHILD` (subject `User`, the child -- granted when a
 * `ChildAccount(child, parent = token user)` exists via
 * `ChildAccountResolver` and both accounts are active).
 *
 * Reads only `User::role`, `User::status` (via `isActive()`), and
 * `ChildAccount` -- S1's frozen "authorization never reads a Profile"
 * invariant holds. AC-2, AC-7, AC-8, AC-9, AC-18: a signed-in child is
 * refused both attributes, and a parent can never manage another parent's
 * child.
 */
final class FamilyVoter extends Voter
{
    public const MANAGE_FAMILY = 'MANAGE_FAMILY';
    public const MANAGE_CHILD = 'MANAGE_CHILD';

    public function __construct(private readonly ChildAccountResolver $childAccountResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE_FAMILY => null === $subject,
            self::MANAGE_CHILD => $subject instanceof User,
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
            // A signed-in child (any role, though a child is always a
            // PLAYER) never manages the family: it is not the parent, it is
            // the subject. AC-18.
            self::MANAGE_FAMILY => UserRole::PLAYER === $user->getRole()
                && $user->isActive()
                && !$this->childAccountResolver->isChild($user),

            self::MANAGE_CHILD => $subject instanceof User
                && $user->isActive()
                && $subject->isActive()
                && $this->isParentOf($user, $subject),

            default => false,
        };
    }

    private function isParentOf(User $candidateParent, User $child): bool
    {
        $childAccount = $this->childAccountResolver->childAccountOf($child);

        return $childAccount instanceof ChildAccount
            && $childAccount->getParentUser() === $candidateParent;
    }
}
