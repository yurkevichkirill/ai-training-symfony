<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChildTrainerRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ChildTrainerRequest>
 */
class ChildTrainerRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChildTrainerRequest::class);
    }

    /**
     * `ChildTrainerService::recordBlockedClick()`'s pre-check and the
     * best-effort read after a caught `UniqueConstraintViolationException`
     * on the partial unique index `uniq_child_trainer_request_pending
     * (child_user_id, trainer_id) WHERE resolved_at IS NULL` -- that index,
     * not this lookup, is the authority.
     */
    public function findPendingFor(User $child, User $trainer): ?ChildTrainerRequest
    {
        return $this->findOneBy(['childUser' => $child, 'trainer' => $trainer, 'resolvedAt' => null]);
    }

    /**
     * AC-16's CTA target: every pending request this parent needs to
     * review, oldest first (the review queue).
     *
     * @return list<ChildTrainerRequest>
     */
    public function findPendingForParent(User $parent): array
    {
        /** @var list<ChildTrainerRequest> */
        return $this->createQueryBuilder('request')
            ->andWhere('request.parentUser = :parent')
            ->andWhere('request.resolvedAt IS NULL')
            ->setParameter('parent', $parent)
            ->orderBy('request.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * `Family\ChildTrainerRequestController`'s review/approve/dismiss lookup
     * (AC-16, AC-17). Eagerly joins `trainer`/`childUser`/`parentUser` --
     * the review template renders all three -- so the family list's own
     * "one query, no N+1" discipline
     * (`TrainerPlayerAssociationRepository::findActiveForPlayers()`'s own
     * docblock) applies here too, rather than three separate lazy loads per
     * page view.
     */
    public function findByIdWithAssociations(Uuid $id): ?ChildTrainerRequest
    {
        return $this->createQueryBuilder('request')
            ->addSelect('trainer', 'childUser', 'parentUser')
            ->innerJoin('request.trainer', 'trainer')
            ->innerJoin('request.childUser', 'childUser')
            ->innerJoin('request.parentUser', 'parentUser')
            ->andWhere('request.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
