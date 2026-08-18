<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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
     * Called by Symfony when the configured hasher reports that a stored hash
     * uses outdated parameters, so hashes migrate on successful sign-in without
     * anyone being asked to change their password.
     */
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
