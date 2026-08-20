<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerShareLink>
 */
class PlayerShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerShareLink::class);
    }

    /**
     * `PlayerShareLinkResolver::resolve()`'s one query (AC-1, edge case:
     * trainer deactivated/deleted): joins the trainer and filters
     * `trainer.status = ACTIVE` in the same query, so an unknown `code` and
     * a code whose trainer is no longer active are indistinguishable to the
     * caller -- both simply yield null.
     *
     * `select('link', 'trainer')` hydrates the trainer in the same pass as
     * the link, exactly like `CoachInvitationRepository::findOneBySelectorWithTrainer()` --
     * without it, `$link->getTrainer()` is an uninitialized lazy proxy, and
     * the first method call on it (`isActive()`, `getDisplayName()`, both
     * genuinely reached by `PlayerShareLinkService::associate()` and
     * `PlayerRegistrationService::registerViaShareLink()`) crashes trying to
     * re-set the proxy's already-populated readonly `$id` during
     * initialization.
     */
    public function findActiveByCode(string $code): ?PlayerShareLink
    {
        return $this->createQueryBuilder('link')
            ->select('link', 'trainer')
            ->innerJoin('link.trainer', 'trainer')
            ->andWhere('link.code = :code')
            ->andWhere('trainer.status = :status')
            ->setParameter('code', $code)
            ->setParameter('status', UserStatus::ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `PlayerShareLinkService::getOrCreateFor()`'s first-attempt read
     * (AC-4). Used only before that method's own transaction ever opens --
     * never after a `ManagerRegistry::resetManager()` call, since this
     * injected repository service permanently caches whichever
     * `EntityRepository` it first resolves (DoctrineBundle's
     * `ServiceEntityRepository::resolveRepository()` uses `??=`), so it
     * would keep handing back an `EntityRepository` bound to the closed
     * manager instead of the fresh one. The post-reset re-read after a
     * caught `UniqueConstraintViolationException` therefore queries the
     * freshly-reset manager directly instead of going through this method.
     */
    public function findOneByTrainer(User $trainer): ?PlayerShareLink
    {
        return $this->findOneBy(['trainer' => $trainer]);
    }
}
