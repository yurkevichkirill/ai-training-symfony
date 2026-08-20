<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountDeletionLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountDeletionLog>
 */
class AccountDeletionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountDeletionLog::class);
    }

    public function existsForUser(User $user): bool
    {
        return null !== $this->createQueryBuilder('l')
            ->select('1')
            ->andWhere('l.subjectUser = :user')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
