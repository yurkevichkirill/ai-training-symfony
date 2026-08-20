<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
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
}
