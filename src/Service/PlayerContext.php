<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;

/**
 * One entry in a family's "who am I setting this for?" switcher (AC-11,
 * AC-12), produced by {@see PlayerContextProvider::contextsFor()}. Same
 * co-location convention as `AccountEventRecord`: the DTO a service returns
 * lives next to that service, not under a generic DTO namespace.
 *
 * @param list<TrainerPlayerAssociation> $trainers this player's own active
 *                                                  trainer connections --
 *                                                  never merged with any
 *                                                  other context's list
 *                                                  (AC-11, AC-18)
 */
final readonly class PlayerContext
{
    public function __construct(
        public User $player,
        public string $label,
        public bool $isSelf,
        public array $trainers,
    ) {
    }
}
