<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\ChildAccountResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Availability eligibility (Task 25): `EDIT_AVAILABILITY` (subject `User`,
 * the player -- granted when the token user *is* the subject, or is the
 * subject's parent via `ChildAccountResolver`) and `VIEW_AVAILABILITY` (the
 * above, **or** the token user is a trainer with an active association to
 * the subject via `TrainerPlayerAssociationRepository::findOneFor()`).
 *
 * AC-18: a signed-in child never edits or views a parent's or sibling's
 * availability. AC-20, AC-22, AC-23: availability is edited only by the
 * owning player/parent and viewed by the player/parent or a connected
 * trainer. Reads no `Profile`.
 */
final class AvailabilityVoter extends Voter
{
    public const EDIT_AVAILABILITY = 'EDIT_AVAILABILITY';
    public const VIEW_AVAILABILITY = 'VIEW_AVAILABILITY';

    public function __construct(
        private readonly ChildAccountResolver $childAccountResolver,
        private readonly TrainerPlayerAssociationRepository $trainerPlayerAssociationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::EDIT_AVAILABILITY, self::VIEW_AVAILABILITY => $subject instanceof User,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::EDIT_AVAILABILITY => $this->isSelfOrParent($user, $subject),

            self::VIEW_AVAILABILITY => $this->isSelfOrParent($user, $subject)
                || $this->isConnectedTrainer($user, $subject),

            default => false,
        };
    }

    private function isSelfOrParent(User $user, User $subject): bool
    {
        if ($user === $subject) {
            return true;
        }

        $childAccount = $this->childAccountResolver->childAccountOf($subject);

        return $childAccount instanceof ChildAccount
            && $childAccount->getParentUser() === $user;
    }

    private function isConnectedTrainer(User $user, User $subject): bool
    {
        return UserRole::TRAINER === $user->getRole()
            && null !== $this->trainerPlayerAssociationRepository->findOneFor($user, $subject);
    }
}
