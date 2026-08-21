<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ChildAccountResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The deny-list for a signed-in child (Task 24, AC-14, AC-18): all four
 * attributes are no-subject and identically granted to an active `PLAYER`
 * who is **not** a child --
 * `MANAGE_OWN_TRAINER_CONNECTIONS`, `DELETE_OWN_ACCOUNT`,
 * `MANAGE_PAYMENT_METHOD`, `COMPLETE_PURCHASE`. The latter two have no
 * caller in this slice (no payment route exists -- Epic-05) but ship anyway
 * with unit tests so a future payments slice fails closed rather than
 * having to remember a rule written in another slice's spec.
 *
 * Defence in depth per S3's Decision Q4: `DELETE_OWN_ACCOUNT` is paired with
 * a service-level guard in `AccountLifecycleService::delete()` refusing a
 * child as actor, since a voter alone cannot cover a console command or a
 * forged request that never reaches an annotated action.
 *
 * Reads only `User::role`, `User::status`, and `ChildAccount` -- no
 * `Profile`.
 */
final class PlayerActionVoter extends Voter
{
    public const MANAGE_OWN_TRAINER_CONNECTIONS = 'MANAGE_OWN_TRAINER_CONNECTIONS';
    public const DELETE_OWN_ACCOUNT = 'DELETE_OWN_ACCOUNT';
    public const MANAGE_PAYMENT_METHOD = 'MANAGE_PAYMENT_METHOD';
    public const COMPLETE_PURCHASE = 'COMPLETE_PURCHASE';

    private const ATTRIBUTES = [
        self::MANAGE_OWN_TRAINER_CONNECTIONS,
        self::DELETE_OWN_ACCOUNT,
        self::MANAGE_PAYMENT_METHOD,
        self::COMPLETE_PURCHASE,
    ];

    public function __construct(private readonly ChildAccountResolver $childAccountResolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && \in_array($attribute, self::ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return UserRole::PLAYER === $user->getRole()
            && $user->isActive()
            && !$this->childAccountResolver->isChild($user);
    }
}
