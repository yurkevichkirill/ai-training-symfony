<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainerPlayerAssociation>
 */
class TrainerPlayerAssociationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainerPlayerAssociation::class);
    }

    /**
     * `PlayerShareLinkService::associate()`'s pre-check (AC-13) and
     * `PlayerShareLinkService::leave()`'s own lookup of the row to end: a
     * best-effort read for the idempotent-success message, not the
     * authority -- the partial unique index `uniq_trainer_player_active_
     * association (trainer_id, player_id) WHERE ended_at IS NULL` is
     * (architecture Decisions Q3, amended by Task 36). Used only before
     * `associate()`'s own write transaction ever opens; a re-read after a
     * caught `UniqueConstraintViolationException` queries the freshly-reset
     * manager directly instead, for the same reason
     * `PlayerShareLinkRepository::findOneByTrainer()` documents.
     *
     * **Task 36: matches only a currently-active row.** A player who left
     * this trainer and later re-follows the same link must get a fresh
     * association, not a resurrected ended one -- see the entity's own
     * docblock. This is the one change that makes that true: every caller
     * automatically sees only the active row, if any.
     */
    public function findOneFor(User $trainer, User $player): ?TrainerPlayerAssociation
    {
        return $this->findOneBy(['trainer' => $trainer, 'player' => $player, 'endedAt' => null]);
    }

    /**
     * `Trainer\PlayerRosterController::index()`'s one query (AC-8): every
     * player *currently* (`ended_at IS NULL`) associated with this trainer,
     * newest first, with the `player` eagerly joined so the roster view
     * never touches a lazy proxy per row. Task 36: a player who has left no
     * longer appears here -- the roster is a membership list, not a
     * historical log.
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findRosterFor(User $trainer): array
    {
        /** @var list<TrainerPlayerAssociation> */
        return $this->createQueryBuilder('association')
            ->addSelect('player')
            ->innerJoin('association.player', 'player')
            ->andWhere('association.trainer = :trainer')
            ->andWhere('association.endedAt IS NULL')
            ->setParameter('trainer', $trainer)
            ->orderBy('association.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Task 36: the signed-in player's own "leave this trainer" page
     * (`Player\TrainerRosterController::index()`) -- every trainer this
     * player is currently (`ended_at IS NULL`) associated with, newest
     * first, with `trainer` eagerly joined so the view never touches a lazy
     * proxy per row.
     *
     * @return list<TrainerPlayerAssociation>
     */
    public function findActiveForPlayer(User $player): array
    {
        /** @var list<TrainerPlayerAssociation> */
        return $this->createQueryBuilder('association')
            ->addSelect('trainer')
            ->innerJoin('association.trainer', 'trainer')
            ->andWhere('association.player = :player')
            ->andWhere('association.endedAt IS NULL')
            ->setParameter('player', $player)
            ->orderBy('association.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
