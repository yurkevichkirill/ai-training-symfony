<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * One page of `UserRepository::search()` (AC-3): the rows plus the cursor
 * for the next page, or null once there is nothing further.
 */
final readonly class UserSearchPage
{
    /**
     * @param list<User> $items
     */
    public function __construct(
        public array $items,
        public ?\DateTimeImmutable $nextAfterCreatedAt,
        public ?string $nextAfterId,
    ) {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextAfterId;
    }
}
