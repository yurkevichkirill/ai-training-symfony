<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\ChildAccountRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Trainer Portal Branding authorization (S7). Subject is always the
 * trainer's `User` (whether being written to or being viewed):
 *
 * - `EDIT_BRANDING` -- granted when the subject is an active `TRAINER`
 *   **and** (the token user *is* the subject, **or** the token user is an
 *   active `SUPER_ADMIN`) (AC-2, BR-001).
 * - `VIEW_BRANDING` -- granted under the same condition, **or** when the
 *   token user has an active `TrainerPlayerAssociation` or
 *   `TrainerCoachAssociation` with the subject, **or** is the parent of a
 *   child who has an active `TrainerPlayerAssociation` with the subject
 *   (AC-6, AC-7).
 *
 * Reads only `User`, `TrainerPlayerAssociation`, `TrainerCoachAssociation`
 * and the child/parent link -- no `Profile` is read, preserving S1's
 * "authorization never reads a Profile" invariant.
 *
 * `role_hierarchy` is flat, so the Super Admin clause on `EDIT_BRANDING` is
 * written out explicitly rather than inherited (S5's fact, matching
 * `CoachVoter`/`ImpersonationVoter`'s precedent).
 */
final class BrandingVoter extends Voter
{
    public const EDIT_BRANDING = 'EDIT_BRANDING';
    public const VIEW_BRANDING = 'VIEW_BRANDING';

    public function __construct(
        private readonly TrainerPlayerAssociationRepository $trainerPlayerAssociationRepository,
        private readonly TrainerCoachAssociationRepository $trainerCoachAssociationRepository,
        private readonly ChildAccountRepository $childAccountRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT_BRANDING, self::VIEW_BRANDING], true)
            && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof User) {
            return false;
        }

        if (!$this->isActiveTrainer($subject)) {
            return false;
        }

        $isSelfOrAdmin = $this->isSelfOrActiveSuperAdmin($user, $subject);

        return match ($attribute) {
            self::EDIT_BRANDING => $isSelfOrAdmin,
            self::VIEW_BRANDING => $isSelfOrAdmin
                || $this->hasActiveAssociation($user, $subject)
                || $this->isParentOfAnAssociatedChild($user, $subject),
            default => false,
        };
    }

    private function isActiveTrainer(User $subject): bool
    {
        return UserRole::TRAINER === $subject->getRole() && $subject->isActive();
    }

    private function isSelfOrActiveSuperAdmin(User $user, User $subject): bool
    {
        if ($user === $subject) {
            return true;
        }

        return UserRole::SUPER_ADMIN === $user->getRole() && $user->isActive();
    }

    private function hasActiveAssociation(User $user, User $trainer): bool
    {
        if (UserRole::PLAYER === $user->getRole()) {
            return null !== $this->trainerPlayerAssociationRepository->findOneFor($trainer, $user);
        }

        if (UserRole::COACH === $user->getRole()) {
            $association = $this->trainerCoachAssociationRepository->findActiveForCoach($user);

            return null !== $association && $association->getTrainer() === $trainer;
        }

        return false;
    }

    private function isParentOfAnAssociatedChild(User $user, User $trainer): bool
    {
        foreach ($this->childAccountRepository->findChildrenOf($user) as $childAccount) {
            $association = $this->trainerPlayerAssociationRepository->findOneFor($trainer, $childAccount->getChildUser());

            if (null !== $association) {
                return true;
            }
        }

        return false;
    }
}
