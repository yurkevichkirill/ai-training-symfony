<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Enum\ImpersonationEndReason;
use App\Service\ImpersonationSearchCriteria;
use App\Service\ImpersonationSearchPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImpersonationSession>
 *
 * Repositories never authorize (S1 convention) -- every method here is a
 * plain query or a conditional write; `ImpersonationVoter` and
 * `ImpersonationService` are what decide whether a caller may act.
 */
class ImpersonationSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImpersonationSession::class);
    }

    /**
     * The NFR-001 lookup: one row, served by `uniq_impersonation_active_actor`.
     */
    public function findOpenForActor(User $actor): ?ImpersonationSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.actorUser = :actor')
            ->andWhere('s.endedAt IS NULL')
            ->setParameter('actor', $actor)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `$user` as actor **or** subject -- D7's forced-end callers
     * (`AccountLifecycleService::deactivate()`/`delete()`).
     *
     * @return list<ImpersonationSession>
     */
    public function findOpenFor(User $user): array
    {
        /** @var list<ImpersonationSession> */
        return $this->createQueryBuilder('s')
            ->andWhere('(s.actorUser = :user OR s.subjectUser = :user)')
            ->andWhere('s.endedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * The sweep command's batch (`app:impersonation:close-expired`).
     *
     * @return list<ImpersonationSession>
     */
    public function findExpiredOpen(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<ImpersonationSession> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.endedAt IS NULL')
            ->andWhere('s.expiresAt <= :now')
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The conditional close (D4b): `UPDATE ... WHERE id = :id AND ended_at
     * IS NULL`, returning whether it actually affected a row. A
     * zero-affected-rows result means somebody else (the sweep, another
     * request, a forced end) closed it first, and is not an error --
     * BR-003's "closed exactly once" is the database's answer, not this
     * method's.
     */
    public function closeIfOpen(ImpersonationSession $session, \DateTimeImmutable $endedAt, ImpersonationEndReason $reason): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE impersonation_session SET ended_at = :endedAt, end_reason = :reason WHERE id = :id AND ended_at IS NULL',
            [
                'endedAt' => $endedAt->format('Y-m-d H:i:s.uP'),
                'reason' => $reason->value,
                'id' => (string) $session->getId(),
            ],
        );

        if ($affected > 0) {
            // Reflects the row this UPDATE just wrote onto the already-
            // loaded entity. Deliberately not `$entityManager->refresh()`:
            // `ImpersonationSession::$id` is `readonly`, and Doctrine's ORM
            // hydrator re-assigns every mapped property during a refresh --
            // including the identifier, to the same value -- which a
            // readonly property refuses once initialized (a `LogicException`
            // from `ReadonlyAccessor`, thrown on every real close path if
            // this called refresh()). `markEnded()` already gives the caller
            // the accurate in-memory state for this one row.
            $session->markEnded($endedAt, $reason);
        }

        return $affected > 0;
    }

    /**
     * Keyset pagination on `(started_at DESC, id DESC)`, mirroring S2's
     * `UserRepository::search()` (D8). The date range is half-open:
     * `started_at >= :from AND started_at < :until`.
     *
     * `actorUser`/`subjectUser` are eagerly joined (and selected), the same
     * convention `TrainerPlayerAssociationRepository` documents for its own
     * `User` associations: an uninitialized lazy proxy to `User` crashes on
     * first property access in this project (`User::$id` is `readonly`,
     * which the lazy-proxy initializer cannot re-assign), so the report --
     * which renders `actorUser`/`subjectUser` display fields for every row
     * -- must never touch one.
     */
    public function search(ImpersonationSearchCriteria $criteria): ImpersonationSearchPage
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.actorUser', 'actorUser')->addSelect('actorUser')
            ->innerJoin('s.subjectUser', 'subjectUser')->addSelect('subjectUser')
            ->orderBy('s.startedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($criteria->limit + 1);

        if (null !== $criteria->actorId) {
            $qb->andWhere('s.actorUser = :actorId')->setParameter('actorId', $criteria->actorId);
        }

        if (null !== $criteria->subjectId) {
            $qb->andWhere('s.subjectUser = :subjectId')->setParameter('subjectId', $criteria->subjectId);
        }

        if (null !== $criteria->startedFrom) {
            $qb->andWhere('s.startedAt >= :startedFrom')->setParameter('startedFrom', $criteria->startedFrom);
        }

        if (null !== $criteria->startedUntil) {
            $qb->andWhere('s.startedAt < :startedUntil')->setParameter('startedUntil', $criteria->startedUntil);
        }

        if (null !== $criteria->afterStartedAt && null !== $criteria->afterId) {
            $qb->andWhere('s.startedAt < :afterStartedAt OR (s.startedAt = :afterStartedAt AND s.id < :afterId)')
                ->setParameter('afterStartedAt', $criteria->afterStartedAt)
                ->setParameter('afterId', $criteria->afterId);
        }

        /** @var list<ImpersonationSession> $rows */
        $rows = $qb->getQuery()->getResult();

        $hasMore = \count($rows) > $criteria->limit;
        $items = $hasMore ? array_slice($rows, 0, $criteria->limit) : $rows;
        $last = [] === $items ? null : $items[array_key_last($items)];

        return new ImpersonationSearchPage(
            $items,
            $hasMore && null !== $last ? $last->getStartedAt() : null,
            $hasMore && null !== $last ? (string) $last->getId() : null,
        );
    }
}
