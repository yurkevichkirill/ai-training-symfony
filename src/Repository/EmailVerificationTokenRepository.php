<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EmailVerificationToken>
 */
class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationToken::class);
    }

    /**
     * `SELECT ... FOR UPDATE` by selector, for
     * `EmailVerificationTokenService::consume()` (AC-13, AC-14). The row lock
     * is what makes single-use survive two simultaneous clicks on the same
     * link: whichever request's transaction commits first wins, and the
     * second sees the already-updated `consumedAt` once its own lock is
     * granted.
     *
     * `Query::setLockMode(LockMode::PESSIMISTIC_WRITE)` throws
     * `TransactionRequiredException` unless the connection already has an
     * active transaction (confirmed in
     * `vendor/doctrine/orm/src/Query.php::setLockMode()`), so this must only
     * be called from inside `EntityManagerInterface::wrapInTransaction()` (or
     * an equivalent explicit transaction) -- never on its own.
     *
     * @throws TransactionRequiredException if called outside a transaction
     */
    public function findOneBySelectorForUpdate(string $selector): ?EmailVerificationToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Returns the raw `user_id` FK for a selector without ever touching the
     * `user` *association* -- deliberately, for
     * `EmailVerificationTokenService::consume()` to warm the identity map
     * with a fully-hydrated `User` before it runs
     * {@see self::findOneBySelectorForUpdate()} (see that method's caller
     * for the full explanation). `IDENTITY()` reads the join-column scalar
     * directly, the same way a plain `SELECT user_id FROM ...` would,
     * without Doctrine creating any proxy or reference for `User` at all.
     */
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

    /**
     * Bulk-deletes every outstanding token for this user, consumed or not.
     *
     * Called by `EmailVerificationTokenService::issue()` before inserting the
     * new token, so "most recently issued token valid, earlier ones refused"
     * holds for email verification exactly as it does for password reset --
     * mirrors `ResetPasswordRequestRepositoryTrait::removeRequests()`
     * (`vendor/symfonycasts/reset-password-bundle`), the same bulk-DQL-delete
     * shape this project already uses for that bundle's own table.
     */
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
