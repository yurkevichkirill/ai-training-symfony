<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoachInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CoachInvitation>
 *
 * Mirrors `AccountInvitationRepository`/`EmailVerificationTokenRepository`'s
 * selector/verifier query shape (row lock for single-use, a scalar-only read
 * of the `trainer` FK for the identity-map warm-up) -- see those classes for
 * the full rationale.
 */
class CoachInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachInvitation::class);
    }

    /**
     * `CoachInvitationService::resolve()`'s one plain read (AC-3, AC-14,
     * AC-18, edge case: trainer deactivated/deleted). `addSelect('trainer')`
     * hydrates the trainer in the *same* query/pass as the invitation, so
     * `$invitation->getTrainer()->isActive()` never touches a lazy proxy --
     * deliberately, since this is the pattern that avoids the readonly-id
     * hazard `EmailVerificationTokenRepository::findOneBySelectorForUpdate()`'s
     * caller documents at length, without needing a separate identity-map
     * warm-up call for a method that never opens a transaction.
     */
    public function findOneBySelectorWithTrainer(string $selector): ?CoachInvitation
    {
        return $this->createQueryBuilder('invitation')
            ->select('invitation', 'trainer')
            ->innerJoin('invitation.trainer', 'trainer')
            ->andWhere('invitation.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `SELECT ... FOR UPDATE` by selector, for `CoachInvitationService::accept()`
     * and `CoachRegistrationService::registerAndAccept()` (AC-3, edge case:
     * two devices on one link). Must only be called from inside
     * `EntityManagerInterface::wrapInTransaction()` (or an equivalent
     * explicit transaction) -- see
     * `EmailVerificationTokenRepository::findOneBySelectorForUpdate()`'s
     * docblock for why.
     */
    public function findOneBySelectorForUpdate(string $selector): ?CoachInvitation
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Returns the raw `trainer_id` FK for a selector without ever touching
     * the `trainer` *association* -- for `CoachInvitationService::accept()`
     * to warm the identity map with a fully-hydrated `User` before it runs
     * {@see self::findOneBySelectorForUpdate()}, mirroring
     * `AccountInvitationRepository::findUserIdBySelector()` /
     * `EmailVerificationTokenRepository::findUserIdBySelector()` exactly:
     * `IDENTITY()` reads the join-column scalar directly, without Doctrine
     * creating any proxy or reference for `User` at all.
     */
    public function findTrainerIdBySelector(string $selector): ?Uuid
    {
        $trainerId = $this->createQueryBuilder('invitation')
            ->select('IDENTITY(invitation.trainer)')
            ->andWhere('invitation.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SCALAR_COLUMN);

        return null !== $trainerId ? Uuid::fromString($trainerId) : null;
    }

    /**
     * `Trainer\CoachController::index()`'s invitation half (AC-17, AC-18's
     * re-invite affordance): every invitation this trainer has ever sent,
     * newest first, so the page can derive each row's Pending/Accepted/
     * Expired status via `CoachInvitation::status()` and offer a re-invite
     * for one that is no longer Pending. Deliberately unfiltered by status
     * -- the derivation, not this query, is what tells the two states
     * apart -- and deliberately includes already-accepted invitations too,
     * since AC-17 asks to see "every coach invitation ... sent", not just
     * the pending ones.
     *
     * @return list<CoachInvitation>
     */
    public function findForTrainer(User $trainer): array
    {
        /** @var list<CoachInvitation> */
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.trainer = :trainer')
            ->setParameter('trainer', $trainer)
            ->orderBy('invitation.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
