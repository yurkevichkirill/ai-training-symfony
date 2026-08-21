<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImpersonationSession;

/**
 * One page of `ImpersonationSessionRepository::search()` (AC-13): the rows
 * plus the cursor for the next page, or null once there is nothing
 * further. Modelled on S2's `UserSearchPage`.
 */
final readonly class ImpersonationSearchPage
{
    /**
     * @param list<ImpersonationSession> $items
     */
    public function __construct(
        public array $items,
        public ?\DateTimeImmutable $nextAfterStartedAt,
        public ?string $nextAfterId,
    ) {
    }

    public function hasMore(): bool
    {
        return null !== $this->nextAfterId;
    }
}
