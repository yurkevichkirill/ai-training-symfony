<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/**
 * D1c: a child's placeholder login identifier. `forChild()` derives
 * `child_<uuid>@children.invalid` from the child account's own immutable id,
 * so it cannot collide with another account -- no lookup, no retry, ever
 * needed. Lowercase hex over a lowercase RFC 2606 `.invalid` domain, so the
 * result already satisfies `User::normalizeEmail()`/S1's
 * `CHECK (email = lower(email))` without another normalization pass, and
 * `.invalid` guarantees the address can never be delivered to or mistaken
 * for a real one.
 *
 * Pure and Doctrine-free: no repository, no side effect, unit-testable in
 * isolation.
 */
final class ChildEmailFactory
{
    private const LOCAL_PREFIX = 'child_';
    private const DOMAIN = 'children.invalid';

    public function forChild(Uuid $childUserId): string
    {
        return self::LOCAL_PREFIX.$childUserId->toRfc4122().'@'.self::DOMAIN;
    }

    /**
     * What the UI calls to decide whether to offer "Enable sign-in"
     * (`ChildAccountService::enableSignIn()`, Task 13) -- an admin who
     * "fixes" a child's address by hand no longer has one that matches this.
     */
    public function isPlaceholder(string $email): bool
    {
        return 1 === preg_match(
            '/^'.self::LOCAL_PREFIX.'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}@'.preg_quote(self::DOMAIN, '/').'$/',
            $email,
        );
    }
}
