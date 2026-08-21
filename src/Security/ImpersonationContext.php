<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Uid\Uuid;

/**
 * S6 (AC-7, D6b): tiny, read-only. `impersonatorUserId()` returns the
 * original token's user id when the current token is a `SwitchUserToken`,
 * else `null`. Depends only on `TokenStorageInterface` -- no session, no
 * database -- so `AccountEventRecorder` can safely call it from its own
 * independent connection.
 */
final class ImpersonationContext
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function impersonatorUserId(): ?Uuid
    {
        $token = $this->tokenStorage->getToken();

        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        $originalUser = $token->getOriginalToken()->getUser();

        if (!$originalUser instanceof User) {
            return null;
        }

        return $originalUser->getId();
    }
}
