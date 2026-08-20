<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CoachInvitation;
use App\Entity\User;
use App\Enum\CoachInvitationStatus;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `CoachInvitation::status()` (Task 29): Pending,
 * Accepted, and Expired derivation, and the exact expiry boundary second
 * (AC-3, AC-17). Pure -- no kernel, no database; `status()` is a function of
 * two in-memory timestamps and nothing else.
 */
final class CoachInvitationTest extends TestCase
{
    private const HASHED_VERIFIER = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    public function testStatusIsPendingBeforeBothAcceptanceAndExpiryAc17(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($now->modify('+1 second'));

        self::assertSame(CoachInvitationStatus::PENDING, $invitation->status($now));
    }

    public function testStatusIsExpiredOncePastTheDeadlineWithoutHavingBeenAcceptedAc3Ac17(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($now->modify('-1 second'));

        self::assertSame(CoachInvitationStatus::EXPIRED, $invitation->status($now));
    }

    /**
     * `isExpired()` uses `<=`: the exact instant that equals `expiresAt`
     * itself already counts as expired, not just the instant after it.
     */
    public function testStatusIsExpiredAtTheExactExpiryBoundarySecondAc3(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($expiresAt);

        self::assertSame(CoachInvitationStatus::EXPIRED, $invitation->status($expiresAt));
    }

    public function testStatusIsStillPendingOneSecondBeforeTheExpiryBoundaryAc3(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($expiresAt);

        self::assertSame(CoachInvitationStatus::PENDING, $invitation->status($expiresAt->modify('-1 second')));
    }

    public function testStatusIsAcceptedOnceAcceptedAc17(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($now->modify('+7 days'));

        $invitation->accept($now);

        self::assertSame(CoachInvitationStatus::ACCEPTED, $invitation->status($now));
    }

    /**
     * Accepted takes priority over the clock (architecture Decisions
     * Q1b'): an invitation accepted before its deadline stays Accepted
     * forever, it must never revert to Expired once the deadline passes.
     */
    public function testStatusStaysAcceptedLongAfterTheOriginalDeadlineOnceAcceptedBeforeItAc17(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $expiresAt = $now->modify('+1 second');
        $invitation = $this->makeInvitation($expiresAt);

        $invitation->accept($now);

        self::assertSame(CoachInvitationStatus::ACCEPTED, $invitation->status($expiresAt->modify('+1 year')));
    }

    /**
     * `accept()` itself is idempotent (`??=`): a second call must not move
     * an already-recorded `acceptedAt`, which `status()`'s Accepted-always-
     * wins behavior depends on staying true.
     */
    public function testAcceptIsIdempotentAndDoesNotMoveAnAlreadyRecordedAcceptedAt(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00+00:00');
        $invitation = $this->makeInvitation($now->modify('+7 days'));

        $invitation->accept($now);
        $invitation->accept($now->modify('+1 hour'));

        self::assertEquals($now, $invitation->getAcceptedAt());
    }

    private function makeInvitation(\DateTimeImmutable $expiresAt): CoachInvitation
    {
        $trainer = new User('trainer@example.test', 'hash', UserRole::TRAINER);

        return new CoachInvitation(
            $trainer,
            'coach@example.test',
            'Casey Coach',
            null,
            'selector1234',
            self::HASHED_VERIFIER,
            $expiresAt,
        );
    }
}
