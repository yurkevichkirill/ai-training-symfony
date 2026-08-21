<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChildAccount;
use App\Entity\User;
use App\Repository\ChildAccountRepository;

/**
 * The single answer to "is the signed-in account a child, and whose?" --
 * one `ChildAccountRepository::findOneByChildUser()` call against the
 * `UNIQUE (child_user_id)` index, served from Doctrine's identity map for
 * the rest of the request. Every voter, service guard, and mail-recipient
 * decision in this slice calls this and nothing re-derives the answer
 * (AC-12, AC-13, AC-14, AC-18).
 */
final class ChildAccountResolver
{
    public function __construct(private readonly ChildAccountRepository $childAccountRepository)
    {
    }

    public function childAccountOf(User $user): ?ChildAccount
    {
        return $this->childAccountRepository->findOneByChildUser($user);
    }

    public function isChild(User $user): bool
    {
        return null !== $this->childAccountOf($user);
    }
}
