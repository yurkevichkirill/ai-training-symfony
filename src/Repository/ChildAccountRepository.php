<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChildAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChildAccount>
 */
class ChildAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChildAccount::class);
    }

    /**
     * `ChildAccountResolver::childAccountOf()`'s one query -- the single
     * lookup every voter, service guard, and mail-recipient decision in this
     * slice calls. Backed by `UNIQUE (child_user_id)`, so at most one row
     * ever matches.
     */
    public function findOneByChildUser(User $child): ?ChildAccount
    {
        return $this->findOneBy(['childUser' => $child]);
    }

    /**
     * AC-7's family list: every child this parent created, newest first.
     *
     * @return list<ChildAccount>
     */
    public function findChildrenOf(User $parent): array
    {
        /** @var list<ChildAccount> */
        return $this->createQueryBuilder('childAccount')
            ->addSelect('childUser')
            ->innerJoin('childAccount.childUser', 'childUser')
            ->andWhere('childAccount.parentUser = :parent')
            ->setParameter('parent', $parent)
            ->orderBy('childAccount.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
