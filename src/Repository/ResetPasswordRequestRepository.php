<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 *
 * The bundle's own trait implements every method of the interface except
 * createResetPasswordRequest(), which has to know our entity's constructor.
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    use ResetPasswordRequestRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function createResetPasswordRequest(
        object $user,
        \DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ): ResetPasswordRequestInterface {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }

    /**
     * Returns the raw `user_id` FK for a selector without ever touching the
     * `user` *association* -- for `PasswordResetService::complete()` to warm
     * the identity map with a fully-hydrated `User` before it calls
     * `ResetPasswordHelper::validateTokenAndFetchUser()`, which internally
     * calls `getUser()` on the (otherwise lazily-loaded) `ResetPasswordRequest`
     * row. See that call site for the full explanation -- this mirrors
     * `EmailVerificationTokenRepository::findUserIdBySelector()` (Task 28)
     * exactly, since `ResetPasswordRequest` maps the identical `User`
     * association shape and was flagged there as carrying the same latent
     * bug. `IDENTITY()` reads the join-column scalar directly, the same way
     * a plain `SELECT user_id FROM ...` would, without Doctrine creating any
     * proxy or reference for `User` at all.
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
}
