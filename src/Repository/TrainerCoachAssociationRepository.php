<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainerCoachAssociation>
 */
class TrainerCoachAssociationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainerCoachAssociation::class);
    }

    /**
     * `CoachInvitationService::accept()`'s / `CoachRegistrationService::
     * registerAndAccept()`'s AC-16 exclusivity pre-check: the coach's one
     * currently-active association, if any, regardless of which trainer or
     * invitation produced it. `findOneBy()` is safe here (rather than a
     * `getSingleResult()` that could throw on more than one row) because the
     * partial unique index `uniq_trainer_coach_active_coach (coach_id)
     * WHERE ended_at IS NULL` already guarantees at most one such row exists
     * -- this is the message-quality fast path, not the authority; that
     * index is (architecture Decisions Q6). Used only before the caller's
     * own write transaction opens -- never after a
     * `ManagerRegistry::resetManager()` call, for the same reason
     * `TrainerPlayerAssociationRepository::findOneFor()` documents.
     */
    public function findActiveForCoach(User $coach): ?TrainerCoachAssociation
    {
        return $this->findOneBy(['coach' => $coach, 'endedAt' => null]);
    }

    /**
     * `Trainer\CoachController::index()`'s roster half (AC-15, AC-17): every
     * coach *currently* (`ended_at IS NULL`) associated with this trainer,
     * newest first, with `coach` **and** `invitation` both eagerly joined.
     * The `invitation` eager-load is not optional: `index()` also runs
     * `CoachInvitationRepository::findForTrainer()` in the same request, and
     * if this query left `association.invitation` as an uninitialized lazy
     * proxy, that second query would hydrate the *same* `CoachInvitation`
     * row into an identity map that already holds a proxy for it -- the
     * same readonly-`$id`-on-proxy-initialization crash
     * `PlayerShareLinkRepository::findActiveByCode()` had (see that
     * method's docblock). Eager-loading here means no such proxy is ever
     * created.
     *
     * @return list<TrainerCoachAssociation>
     */
    public function findActiveFor(User $trainer): array
    {
        /** @var list<TrainerCoachAssociation> */
        return $this->createQueryBuilder('association')
            ->addSelect('coach')
            ->addSelect('invitation')
            ->innerJoin('association.coach', 'coach')
            ->leftJoin('association.invitation', 'invitation')
            ->andWhere('association.trainer = :trainer')
            ->andWhere('association.endedAt IS NULL')
            ->setParameter('trainer', $trainer)
            ->orderBy('association.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
