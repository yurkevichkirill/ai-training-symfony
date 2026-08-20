<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\AccountInvitationRepository;
use App\Service\Exception\AccountInvitationAlreadyConsumedException;
use App\Service\Exception\AccountInvitationExpiredException;
use App\Service\Exception\InvalidAccountInvitationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Consumes a single-use `AccountInvitation` (AC-5, AC-6): sets the account's
 * real password and verifies its email in one action, then never allows the
 * same link to be replayed. Same selector/verifier/row-lock discipline as
 * `EmailVerificationTokenService::consume()`, including the identical
 * identity-map warm-up that avoids the readonly-id/lazy-proxy hazard that
 * class documents -- reused, not re-derived.
 *
 * Unlike email verification, there is no idempotent-replay case: setting a
 * password is not a fact that can be safely re-asserted, so a second
 * consumption of the same link is always a hard refusal.
 */
final class AccountInvitationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AccountInvitationRepository $invitationRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws InvalidAccountInvitationException
     * @throws AccountInvitationAlreadyConsumedException
     * @throws AccountInvitationExpiredException
     */
    public function consume(string $token, string $plainPassword): User
    {
        $parts = SelectorVerifierTokenFactory::split($token);

        if (null === $parts) {
            throw new InvalidAccountInvitationException();
        }

        [$selector, $verifier] = $parts;

        $entityManager = $this->openEntityManager();

        return $entityManager->wrapInTransaction(function () use ($entityManager, $selector, $verifier, $plainPassword): User {
            $userId = $this->invitationRepository->findUserIdBySelector($selector);

            if (null !== $userId) {
                $entityManager->find(User::class, $userId);
            }

            $invitation = $this->invitationRepository->findOneBySelectorForUpdate($selector);

            if (null === $invitation || !hash_equals($invitation->getHashedVerifier(), SelectorVerifierTokenFactory::hash($verifier))) {
                throw new InvalidAccountInvitationException();
            }

            if ($invitation->isConsumed()) {
                throw new AccountInvitationAlreadyConsumedException();
            }

            $now = new \DateTimeImmutable();

            if ($invitation->isExpired($now)) {
                throw new AccountInvitationExpiredException();
            }

            $invitation->consume($now);

            $user = $invitation->getUser();

            if (!$user->isActive()) {
                throw new InvalidAccountInvitationException();
            }

            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->markEmailVerified($now);
            $entityManager->persist($user);

            return $user;
        });
    }

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
