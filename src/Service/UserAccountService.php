<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\Exception\EmailAlreadyInUseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates `App\Entity\User` accounts (AC-4, AC-5).
 *
 * Email normalization is delegated entirely to `User`'s own constructor
 * (`User::normalizeEmail()`) -- this service never re-implements it. The
 * password is hashed via the autowired `UserPasswordHasherInterface`
 * (`config/packages/security.yaml`'s `password_hashers` maps
 * `PasswordAuthenticatedUserInterface` to `'auto'`, i.e. Argon2id here), and
 * the insert happens inside a single `EntityManager::wrapInTransaction()`
 * call so persistence is all-or-nothing.
 *
 * **The closed-EntityManager pitfall.** `app_user`'s `UNIQUE (email)` index
 * is a plain (non-deferrable) constraint, so a concurrent insert of the same
 * normalized email fails at INSERT time inside `flush()`, which
 * `wrapInTransaction()` calls internally. Doctrine ORM's `EntityManager`
 * unconditionally closes itself in that situation: both
 * `UnitOfWork::commit()` and `EntityManager::wrapInTransaction()` wrap the
 * write in a `try { ... } finally { if (!$successful) { $this->close(); ... } }`
 * block, so *any* exception escaping the wrapped callback -- including a
 * `UniqueConstraintViolationException` -- leaves that EntityManager instance
 * permanently closed. Every later `persist()`/`flush()`/`wrapInTransaction()`
 * call on that same instance throws `EntityManager is closed`, even for a
 * completely unrelated write. See the comment at the catch site below for how
 * this method avoids reusing a closed instance.
 */
final class UserAccountService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws EmailAlreadyInUseException if another account already holds this
     *                                     email address (case-insensitively)
     */
    public function create(string $email, string $plainPassword, UserRole $role): User
    {
        $entityManager = $this->openEntityManager();

        $user = new User($email, '', $role);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        try {
            $entityManager->wrapInTransaction(static function () use ($entityManager, $user): void {
                $entityManager->persist($user);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Do NOT touch $entityManager again from here on -- not in this
            // catch block, not later in this method. wrapInTransaction()
            // above already closed it (see the class docblock): it caught
            // this same exception internally, ran its own `finally` block,
            // and called `$entityManager->close()` before letting the
            // exception propagate to us. Calling persist()/flush()/
            // wrapInTransaction() on a closed EntityManager throws
            // `EntityManager is closed`, which would replace this well-typed
            // EmailAlreadyInUseException with an uncaught 500 -- exactly the
            // outcome AC-5 forbids. The next create() call gets a working
            // EntityManager from openEntityManager() below, which detects the
            // closed instance and asks ManagerRegistry to reset it, not from
            // reusing this one.
            throw new EmailAlreadyInUseException($user->getEmail(), $e);
        }

        return $user;
    }

    /**
     * Returns an open EntityManager for `User`, transparently recovering from
     * a previous call's close() (see the class docblock).
     *
     * `ManagerRegistry::getManagerForClass()` returns the container's cached
     * manager instance, which stays the *closed* one after a unique-violation
     * until something asks the registry to reset it -- `resetManager()` is
     * Doctrine's documented mechanism for exactly this ("force the creation
     * of a new manager if the current one is closed", per
     * `AbstractManagerRegistry::resetManager()`).
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(User::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', User::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
