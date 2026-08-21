<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * D3c: who receives mail about a player. Always the parent's address for a
 * child -- regardless of whether the child has real sign-in credentials
 * (BR-011: the parent owns the family's contact information) -- and the
 * player's own address otherwise. This is what routes
 * `TEMPLATE_CHILD_SHARE_LINK_REQUEST` to the parent (AC-16), and it is also
 * what stops `TEMPLATE_PLAYER_TRAINER_CONNECTED` from ever being queued to a
 * child's undeliverable `.invalid` placeholder address (AC-8, AC-17).
 */
final class NotificationAddressResolver
{
    public function __construct(private readonly ChildAccountResolver $childAccountResolver)
    {
    }

    public function forPlayer(User $player): string
    {
        $childAccount = $this->childAccountResolver->childAccountOf($player);

        if (null !== $childAccount) {
            return $childAccount->getParentUser()->getEmail();
        }

        return $player->getEmail();
    }
}
