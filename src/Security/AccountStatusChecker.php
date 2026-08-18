<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Security\Exception\AccountDeactivatedException;
use App\Security\Exception\EmailNotVerifiedException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses accounts that exist but may not sign in: deactivated ones (AC-2) and
 * ones whose email was never verified (AC-1).
 *
 * Both checks live in checkPostAuth(), never checkPreAuth(). That is the whole
 * point: checkPreAuth() runs *before* the password is verified, so refusing
 * there would answer "does this account exist, and in what state" to anyone who
 * types an address with any password at all. Running after the credential check
 * means only someone who already holds the correct password learns anything --
 * and the failure handler collapses even that into the single uniform message.
 *
 * The two exceptions are distinct classes so the audit trail can record which
 * rule fired (AC-24) while the caller sees one message (AC-3).
 */
final class AccountStatusChecker implements UserCheckerInterface
{
    /**
     * Intentionally empty. See the class docblock: checking here would leak
     * account state to unauthenticated probing.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new AccountDeactivatedException();
        }

        if (!$user->isEmailVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
