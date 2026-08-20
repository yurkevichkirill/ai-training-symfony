<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\UserRole;
use App\Enum\UserStatus;

/**
 * The Users-tool directory's one query shape (AC-1, AC-2, AC-3): optional
 * role/status filters, an optional tool-scoped search string, and a keyset
 * cursor rather than an offset -- so paging stays flat at 10,000+ rows
 * (NFR-002) instead of degrading like `OFFSET` does.
 */
final readonly class UserSearchCriteria
{
    public function __construct(
        public ?UserRole $role = null,
        public ?UserStatus $status = null,
        public ?string $query = null,
        public ?\DateTimeImmutable $afterCreatedAt = null,
        public ?string $afterId = null,
        public int $limit = 25,
    ) {
    }
}
