<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccountInvitation>
 *
 * Mirrors EmailVerificationTokenRepository's selector/verifier query shape
 * (row lock for single-use, bulk-delete-before-reissue) -- see that class for
 * the full rationale.
 */
class AccountInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountInvitation::class);
    }

    public function findOneBySelectorForUpdate(string $selector): ?AccountInvitation
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findUserIdBySelector(string $selector): ?Uuid
    {
        $userId = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.user)')
            ->andWhere('t.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SCALAR_COLUMN);

        return null !== $userId ? Uuid::fromString($userId) : null;
    }

    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
