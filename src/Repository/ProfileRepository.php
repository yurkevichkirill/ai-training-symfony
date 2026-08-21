<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\ProfileCoach;
use App\Entity\ProfilePlayer;
use App\Entity\ProfileTrainer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Profile>
 */
class ProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    public function findTrainerProfile(User $user): ?ProfileTrainer
    {
        /** @var ProfileTrainer|null $profile */
        $profile = $this->createQueryBuilder('p')
            ->andWhere('p INSTANCE OF App\Entity\ProfileTrainer')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $profile;
    }

    /**
     * D1c, AC-11: the coach's own `ProfileCoach` row, if it has ever been
     * saved once (`ProfileService::updateCoachDetails()` creates it lazily
     * on first save -- no code path creates one earlier, and no backfill
     * migration runs for existing coaches). `null` means "not public and
     * nothing saved yet", not an error.
     */
    public function findCoachProfile(User $user): ?ProfileCoach
    {
        /** @var ProfileCoach|null $profile */
        $profile = $this->createQueryBuilder('p')
            ->andWhere('p INSTANCE OF App\Entity\ProfileCoach')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $profile;
    }

    /**
     * Batched for `Trainer\PlayerRosterController::index()` (AC-8): the
     * roster displays each player's own declared name, not the account's
     * `displayName` (which is the registrant's first/last name -- a
     * distinct field from AC-7's "player's own name" the moment Parent/Child
     * lands, and already distinct in the self-registration case whenever the
     * two names differ). One query for the whole roster, not one per row.
     *
     * @param list<User> $users
     *
     * @return array<string, ProfilePlayer> keyed by user id (RFC 4122 string)
     */
    public function findPlayerProfilesFor(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        /** @var list<ProfilePlayer> $profiles */
        $profiles = $this->createQueryBuilder('p')
            ->andWhere('p INSTANCE OF App\Entity\ProfilePlayer')
            ->andWhere('p.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $byUserId = [];

        foreach ($profiles as $profile) {
            $byUserId[$profile->getUser()->getId()->toRfc4122()] = $profile;
        }

        return $byUserId;
    }

    /**
     * S7's tier-B batched sibling of `findTrainerProfile()`, for
     * `TrainerBrandingResolver::forTrainers()`: every rendered roster/family
     * row's branding in one query, never one query per trainer (AC-11,
     * NFR-001). Repositories never authorize -- callers decide which
     * trainers belong on the page.
     *
     * @param list<User> $users
     *
     * @return array<string, ProfileTrainer> keyed by user id (RFC 4122 string)
     */
    public function findTrainerProfilesFor(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        /** @var list<ProfileTrainer> $profiles */
        $profiles = $this->createQueryBuilder('p')
            ->andWhere('p INSTANCE OF App\Entity\ProfileTrainer')
            ->andWhere('p.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $byUserId = [];

        foreach ($profiles as $profile) {
            $byUserId[$profile->getUser()->getId()->toRfc4122()] = $profile;
        }

        return $byUserId;
    }
}
