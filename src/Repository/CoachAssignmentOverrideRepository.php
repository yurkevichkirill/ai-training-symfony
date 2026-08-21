<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoachAssignmentOverride;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachAssignmentOverride>
 */
class CoachAssignmentOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachAssignmentOverride::class);
    }

    /**
     * AC-8's "queryable later", the coach direction: every override
     * recorded against one coach's availability, newest first.
     *
     * @return list<CoachAssignmentOverride>
     */
    public function findForCoach(User $coach): array
    {
        /** @var list<CoachAssignmentOverride> */
        return $this->createQueryBuilder('override')
            ->andWhere('override.coach = :coach')
            ->setParameter('coach', $coach)
            ->orderBy('override.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * AC-8's "queryable later", the trainer direction: every override this
     * trainer recorded, newest first.
     *
     * @return list<CoachAssignmentOverride>
     */
    public function findForTrainer(User $trainer): array
    {
        /** @var list<CoachAssignmentOverride> */
        return $this->createQueryBuilder('override')
            ->andWhere('override.overriddenByUser = :trainer')
            ->setParameter('trainer', $trainer)
            ->orderBy('override.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
