<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChildAccount;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Repository\TrainerPlayerAssociationRepository;

/**
 * The context-selector data shape behind "Best Times" and any other
 * per-player family view (AC-11, AC-12, AC-18): the list of players the
 * signed-in account may act as, each with that player's own trainer
 * connections kept strictly separate.
 *
 * **Adult**: the self context first (label "Me"), then one context per
 * child, via `ChildAccountService::findChildrenOf()` -- each child's
 * `trainers` holds only that child's own active associations, never the
 * parent's and never a sibling's (AC-11 -- the whole point is that these
 * lists are never merged into one).
 *
 * **Child**: `ChildAccountResolver` is consulted first, and when it resolves
 * a `ChildAccount` this method returns a single self context and nothing
 * else -- it never widens to a parent's or a sibling's data (AC-12, AC-18).
 * A child account has no children of its own by construction (nothing in
 * this schema lets a `ChildAccount` row name a child user as a parent), so
 * this branch is what actually enforces the boundary, not an incidental
 * consequence of an empty query result.
 *
 * One {@see TrainerPlayerAssociationRepository::findActiveForPlayers()} call
 * covers every context in a single request, trainer eagerly joined, so a
 * family page with N children is O(1) queries, not O(N).
 */
final class PlayerContextProvider
{
    private const SELF_LABEL = 'Me';

    public function __construct(
        private readonly ChildAccountResolver $childAccountResolver,
        private readonly ChildAccountService $childAccountService,
        private readonly TrainerPlayerAssociationRepository $associationRepository,
    ) {
    }

    /**
     * @return list<PlayerContext>
     */
    public function contextsFor(User $user): array
    {
        if ($this->childAccountResolver->isChild($user)) {
            $trainers = $this->associationRepository->findActiveForPlayers([$user]);

            return [new PlayerContext($user, self::SELF_LABEL, true, $trainers)];
        }

        $children = $this->childAccountService->findChildrenOf($user);
        $childUsers = array_map(static fn (ChildAccount $child): User => $child->getChildUser(), $children);

        $trainersByPlayer = $this->groupByPlayer(
            $this->associationRepository->findActiveForPlayers([$user, ...$childUsers]),
        );

        $contexts = [new PlayerContext($user, self::SELF_LABEL, true, $trainersByPlayer[$user->getId()->toRfc4122()] ?? [])];

        foreach ($children as $child) {
            $childUser = $child->getChildUser();
            $contexts[] = new PlayerContext(
                $childUser,
                $childUser->getDisplayName(),
                false,
                $trainersByPlayer[$childUser->getId()->toRfc4122()] ?? [],
            );
        }

        return $contexts;
    }

    /**
     * @param list<TrainerPlayerAssociation> $associations
     *
     * @return array<string, list<TrainerPlayerAssociation>> keyed by the
     *                                                        player's id
     */
    private function groupByPlayer(array $associations): array
    {
        $byPlayer = [];

        foreach ($associations as $association) {
            $byPlayer[$association->getPlayer()->getId()->toRfc4122()][] = $association;
        }

        return $byPlayer;
    }
}
