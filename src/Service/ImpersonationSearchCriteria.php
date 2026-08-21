<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/**
 * The "Impersonation History" report's one query shape (AC-13, D8):
 * optional actor/subject filters, an optional half-open start-date range,
 * and a keyset cursor -- modelled field-for-field on S2's
 * `UserSearchCriteria`, for the same reason: an append-mostly table where
 * `OFFSET` paging drifts as rows arrive.
 */
final readonly class ImpersonationSearchCriteria
{
    public function __construct(
        public ?Uuid $actorId = null,
        public ?Uuid $subjectId = null,
        public ?\DateTimeImmutable $startedFrom = null,
        public ?\DateTimeImmutable $startedUntil = null,
        public ?\DateTimeImmutable $afterStartedAt = null,
        public ?Uuid $afterId = null,
        public int $limit = 25,
    ) {
    }
}
