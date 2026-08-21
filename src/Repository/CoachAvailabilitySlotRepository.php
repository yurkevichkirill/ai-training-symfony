<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoachAvailabilitySlot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachAvailabilitySlot>
 */
class CoachAvailabilitySlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachAvailabilitySlot::class);
    }

    /**
     * The grid read (`CoachAvailabilityService::weekFor()`): every slot for
     * one coach, ordered for direct rendering into the weekly grid.
     *
     * @return list<CoachAvailabilitySlot>
     */
    public function weekFor(User $coach): array
    {
        /** @var list<CoachAvailabilitySlot> */
        return $this->createQueryBuilder('slot')
            ->andWhere('slot.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('slot.dayOfWeek', 'ASC')
            ->addOrderBy('slot.startsAtMinute', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * AC-5's batched roster-card read: every slot for the given coaches in
     * one query, no N+1 -- `Trainer\CoachController::index()` passes the
     * whole roster's coach ids here instead of querying per card.
     *
     * @param list<User> $coaches
     *
     * @return list<CoachAvailabilitySlot>
     */
    public function findForCoaches(array $coaches): array
    {
        if ([] === $coaches) {
            return [];
        }

        /** @var list<CoachAvailabilitySlot> */
        return $this->createQueryBuilder('slot')
            ->andWhere('slot.coach IN (:coaches)')
            ->setParameter('coaches', $coaches)
            ->orderBy('slot.coach', 'ASC')
            ->addOrderBy('slot.dayOfWeek', 'ASC')
            ->addOrderBy('slot.startsAtMinute', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * `CoachAvailabilityService::replaceWeek()`'s delete-then-insert (AC-2,
     * AC-3): a bulk DQL delete scoped by `coach_id` -- so a save for one
     * coach cannot touch another coach's rows, or any
     * `player_availability_slot` row -- executed immediately against the
     * database, followed by persisting the normalized replacement slots.
     * Flushing the new rows and committing the transaction remains the
     * caller's responsibility.
     *
     * @param list<CoachAvailabilitySlot> $slots
     */
    public function replaceWeekFor(User $coach, array $slots): void
    {
        $this->createQueryBuilder('slot')
            ->delete()
            ->where('slot.coach = :coach')
            ->setParameter('coach', $coach)
            ->getQuery()
            ->execute();

        $entityManager = $this->getEntityManager();
        foreach ($slots as $slot) {
            $entityManager->persist($slot);
        }
    }
}
