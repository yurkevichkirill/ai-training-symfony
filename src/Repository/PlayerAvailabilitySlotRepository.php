<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlayerAvailabilitySlot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerAvailabilitySlot>
 */
class PlayerAvailabilitySlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerAvailabilitySlot::class);
    }

    /**
     * The grid read (`AvailabilityService::weekFor()`): every slot for one
     * player, ordered for direct rendering into the weekly grid.
     *
     * @return list<PlayerAvailabilitySlot>
     */
    public function weekFor(User $player): array
    {
        /** @var list<PlayerAvailabilitySlot> */
        return $this->createQueryBuilder('slot')
            ->andWhere('slot.player = :player')
            ->setParameter('player', $player)
            ->orderBy('slot.dayOfWeek', 'ASC')
            ->addOrderBy('slot.startsAtMinute', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * AC-22's roster-card read: every slot for the given players in one
     * query, no N+1 -- `Trainer\PlayerRosterController::index()` passes the
     * whole roster's player ids here instead of querying per card.
     *
     * @param list<User> $players
     *
     * @return list<PlayerAvailabilitySlot>
     */
    public function findForPlayers(array $players): array
    {
        if ([] === $players) {
            return [];
        }

        /** @var list<PlayerAvailabilitySlot> */
        return $this->createQueryBuilder('slot')
            ->andWhere('slot.player IN (:players)')
            ->setParameter('players', $players)
            ->orderBy('slot.player', 'ASC')
            ->addOrderBy('slot.dayOfWeek', 'ASC')
            ->addOrderBy('slot.startsAtMinute', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * `AvailabilityService::replaceWeek()`'s delete-then-insert (D5d, AC-19,
     * AC-20): a bulk DQL delete scoped by `player_id` -- so a save for one
     * child cannot touch another player's rows -- executed immediately
     * against the database, followed by persisting the normalized
     * replacement slots. Flushing the new rows and committing the
     * transaction remains the caller's responsibility, exactly like
     * `AccountInvitationRepository::deleteAllForUser()` beside its own
     * callers.
     *
     * @param list<PlayerAvailabilitySlot> $slots
     */
    public function replaceWeekFor(User $player, array $slots): void
    {
        $this->createQueryBuilder('slot')
            ->delete()
            ->where('slot.player = :player')
            ->setParameter('player', $player)
            ->getQuery()
            ->execute();

        $entityManager = $this->getEntityManager();
        foreach ($slots as $slot) {
            $entityManager->persist($slot);
        }
    }
}
