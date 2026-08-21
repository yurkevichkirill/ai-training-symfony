<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\TrainerCoachAssociationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Coach-feature eligibility (Task 18): `EDIT_COACH_PROFILE` (no subject --
 * granted when the token user is an active `COACH`); `EDIT_COACH_AVAILABILITY`
 * (subject `User`, the coach -- granted when the subject *is* the token
 * user and is an active `COACH`); `VIEW_COACH_AVAILABILITY` (subject
 * `User`, the coach -- granted under the same rule as
 * `EDIT_COACH_AVAILABILITY`, **or** when the token user is an active
 * `TRAINER` with an active `TrainerCoachAssociation` to that coach via
 * `TrainerCoachAssociationRepository::findActiveForCoach()`).
 *
 * Reads only `User` and `TrainerCoachAssociation`, never a `Profile` --
 * S1's "authorization never reads a Profile" invariant holds. `role_hierarchy`
 * is flat, so `ROLE_SUPER_ADMIN` grants nothing here by construction (AC-15).
 */
final class CoachVoter extends Voter
{
    public const EDIT_COACH_PROFILE = 'EDIT_COACH_PROFILE';
    public const EDIT_COACH_AVAILABILITY = 'EDIT_COACH_AVAILABILITY';
    public const VIEW_COACH_AVAILABILITY = 'VIEW_COACH_AVAILABILITY';

    public function __construct(
        private readonly TrainerCoachAssociationRepository $trainerCoachAssociationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::EDIT_COACH_PROFILE => null === $subject,
            self::EDIT_COACH_AVAILABILITY, self::VIEW_COACH_AVAILABILITY => $subject instanceof User,
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
            self::EDIT_COACH_PROFILE => $this->isActiveCoach($user),

            self::EDIT_COACH_AVAILABILITY => $subject instanceof User
                && $user === $subject
                && $this->isActiveCoach($user),

            self::VIEW_COACH_AVAILABILITY => $subject instanceof User
                && (($user === $subject && $this->isActiveCoach($user))
                    || $this->isConnectedTrainer($user, $subject)),

            default => false,
        };
    }

    private function isActiveCoach(User $user): bool
    {
        return UserRole::COACH === $user->getRole() && $user->isActive();
    }

    private function isConnectedTrainer(User $user, User $subject): bool
    {
        if (UserRole::TRAINER !== $user->getRole() || !$user->isActive()) {
            return false;
        }

        $association = $this->trainerCoachAssociationRepository->findActiveForCoach($subject);

        return null !== $association && $association->getTrainer() === $user;
    }
}
