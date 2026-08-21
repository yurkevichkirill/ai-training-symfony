<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ImpersonationSession;
use App\Entity\User;
use App\Enum\ImpersonationEndReason;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * S6 (AC-9, AC-10, AC-14, D4c): `getDuration()` for a closed vs. still-open
 * row, and `hasExpired()` against an injected "now" on both sides of the
 * boundary -- the entity never reads the clock itself.
 */
final class ImpersonationSessionTest extends TestCase
{
    private User $actor;
    private User $subject;

    protected function setUp(): void
    {
        $this->actor = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN);
        $this->subject = new User('target@example.test', 'hash', UserRole::TRAINER);
    }

    public function testGetDurationIsNullWhileTheSessionIsStillOpen(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $startedAt->add(new \DateInterval('PT1H'));

        $session = new ImpersonationSession($this->actor, $this->subject, $startedAt, $expiresAt);

        self::assertNull($session->getDuration());
    }

    public function testGetDurationIsComputedOnceTheSessionIsClosed(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $startedAt->add(new \DateInterval('PT1H'));
        $endedAt = $startedAt->add(new \DateInterval('PT15M'));

        $session = new ImpersonationSession($this->actor, $this->subject, $startedAt, $expiresAt);
        $session->markEnded($endedAt, ImpersonationEndReason::EXPLICIT_EXIT);

        $duration = $session->getDuration();

        self::assertNotNull($duration);
        self::assertSame(0, $duration->days);
        self::assertSame(15, $duration->i);
    }

    public function testHasExpiredIsFalseBeforeAndAtTheExpiryInstantAndTrueAfter(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $startedAt->add(new \DateInterval('PT1H'));

        $session = new ImpersonationSession($this->actor, $this->subject, $startedAt, $expiresAt);

        self::assertFalse($session->hasExpired($expiresAt->modify('-1 second')));
        self::assertFalse($session->hasExpired($expiresAt), 'The exact expiry instant itself is not yet "expired" -- hasExpired() uses a strict >.');
        self::assertTrue($session->hasExpired($expiresAt->modify('+1 second')));
    }

    public function testIsOpenReflectsWhetherEndedAtHasBeenSet(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $startedAt->add(new \DateInterval('PT1H'));

        $session = new ImpersonationSession($this->actor, $this->subject, $startedAt, $expiresAt);
        self::assertTrue($session->isOpen());

        $session->markEnded($startedAt->add(new \DateInterval('PT5M')), ImpersonationEndReason::TIMEOUT);
        self::assertFalse($session->isOpen());
    }
}
