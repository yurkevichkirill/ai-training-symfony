<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\UserSearchCriteria;
use App\Service\UserSearchPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @implements PasswordUpgraderInterface<User>
 *
 * The security entity provider is configured without a `property`, which is
 * exactly the mode where Doctrine's EntityUserProvider delegates the lookup to
 * this class. That is deliberate: it puts identifier normalization on the one
 * path every sign-in attempt takes, instead of leaving it to callers.
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Normalizes before querying, so the comparison is a plain equality against
     * the stored (already normalized) column and uses uniq_app_user_email.
     * Matching with LOWER(email) = :x would have been correct too, but it
     * cannot use that index and would turn every login into a sequential scan.
     *
     * The CHECK (email = lower(email)) constraint is what lets us rely on this:
     * an unnormalized row cannot exist, so normalizing only the input is enough
     * (AC-5).
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneBy(['email' => User::normalizeEmail($identifier)]);
    }

    /**
     * Whether any account already holds the given role -- used by
     * `CreateSuperAdminCommand` (Task 36, AC-25) to decide whether creating a
     * Super Admin needs an explicit confirmation/`--force`, since that
     * command doubles as the "every Super Admin was lost" recovery path.
     */
    public function existsWithRole(UserRole $role): bool
    {
        return null !== $this->createQueryBuilder('u')
            ->select('1')
            ->andWhere('u.role = :role')
            ->setParameter('role', $role)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Called by Symfony when the configured hasher reports that a stored hash
     * uses outdated parameters, so hashes migrate on successful sign-in without
     * anyone being asked to change their password.
     */
    /**
     * The Users-tool directory's only query shape (AC-1, AC-2, AC-3):
     * keyset-paginated on `(created_at DESC, id DESC)` rather than `OFFSET`,
     * so the query stays flat at 10,000+ rows instead of degrading with the
     * page number. `%`/`_` in the search string are escaped so they are
     * matched literally, not as SQL LIKE wildcards -- a search for a name
     * that happens to contain one of those characters must not silently
     * turn into a broader match.
     */
    public function search(UserSearchCriteria $criteria): UserSearchPage
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin(ProfileTrainer::class, 'pt', Join::WITH, 'pt.user = u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setMaxResults($criteria->limit + 1);

        if (null !== $criteria->role) {
            $qb->andWhere('u.role = :role')->setParameter('role', $criteria->role);
        }

        if (null !== $criteria->status) {
            $qb->andWhere('u.status = :status')->setParameter('status', $criteria->status);
        }

        if (null !== $criteria->query && '' !== trim($criteria->query)) {
            $escaped = addcslashes(trim($criteria->query), '%_');
            $qb->andWhere(
                'LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(pt.businessName) LIKE :q',
            )->setParameter('q', '%'.mb_strtolower($escaped).'%');
        }

        if (null !== $criteria->afterCreatedAt && null !== $criteria->afterId) {
            $qb->andWhere('u.createdAt < :afterCreatedAt OR (u.createdAt = :afterCreatedAt AND u.id < :afterId)')
                ->setParameter('afterCreatedAt', $criteria->afterCreatedAt)
                ->setParameter('afterId', Uuid::fromString($criteria->afterId));
        }

        /** @var list<User> $rows */
        $rows = $qb->getQuery()->getResult();

        $hasMore = \count($rows) > $criteria->limit;
        $items = $hasMore ? array_slice($rows, 0, $criteria->limit) : $rows;
        $last = [] === $items ? null : $items[array_key_last($items)];

        return new UserSearchPage(
            $items,
            $hasMore && null !== $last ? $last->getCreatedAt() : null,
            $hasMore && null !== $last ? (string) $last->getId() : null,
        );
    }

    /**
     * `SweepUnverifiedAccountsCommand`'s candidate query (Task 37): accounts
     * created only through a public ShareLink/coach-invitation flow -- role
     * `PLAYER` or `COACH` -- that never completed email verification, older
     * than the caller's cutoff. `status != DELETED` is excluded deliberately,
     * beyond what the architecture's Risks section literally states: an
     * already-anonymized account (`User::anonymize()`) leaves
     * `emailVerifiedAt`/`role` untouched, so without this guard a DELETED row
     * could match too -- and hard-deleting it would both destroy its
     * `AccountDeletionLog` row (which this sweep has no business touching)
     * and hit that table's own `ON DELETE RESTRICT` on `subject_user_id`.
     *
     * @return list<User>
     */
    public function findStaleUnverifiedShareLinkAccounts(\DateTimeImmutable $cutoff, int $limit): array
    {
        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.role IN (:roles)')
            ->andWhere('u.emailVerifiedAt IS NULL')
            ->andWhere('u.createdAt < :cutoff')
            ->andWhere('u.status != :deleted')
            ->setParameter('roles', [UserRole::PLAYER, UserRole::COACH])
            ->setParameter('cutoff', $cutoff)
            ->setParameter('deleted', UserStatus::DELETED)
            ->orderBy('u.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total count for the same candidate set `findStaleUnverifiedShareLinkAccounts()`
     * selects -- used to report a true total in `--dry-run`, where no rows are
     * removed between calls and a plain paginated fetch would loop forever.
     */
    public function countStaleUnverifiedShareLinkAccounts(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role IN (:roles)')
            ->andWhere('u.emailVerifiedAt IS NULL')
            ->andWhere('u.createdAt < :cutoff')
            ->andWhere('u.status != :deleted')
            ->setParameter('roles', [UserRole::PLAYER, UserRole::COACH])
            ->setParameter('cutoff', $cutoff)
            ->setParameter('deleted', UserStatus::DELETED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPasswordHash($newHashedPassword, $user->getPasswordChangedAt());
        $user->touch();

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
