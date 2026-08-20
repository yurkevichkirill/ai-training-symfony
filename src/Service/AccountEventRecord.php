<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccountEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Everything `AccountEventRecorder::record()` needs (S2), and structurally
 * nothing else -- same "no field could hold a secret" discipline as
 * `AuthEventRecord`.
 *
 * @param array<string, scalar|null> $context
 */
final readonly class AccountEventRecord
{
    public function __construct(
        public AccountEventType $type,
        public ?Uuid $actorUserId,
        public Uuid $subjectUserId,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public array $context = [],
    ) {
    }
}
