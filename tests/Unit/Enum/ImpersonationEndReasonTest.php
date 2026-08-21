<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ImpersonationEndReason;
use PHPUnit\Framework\TestCase;

/**
 * S6 (AC-9): exactly three cases, backed by their own literal string
 * values -- the shape AC-9's "ended exactly once, by exactly one of two
 * reasons [or the forced third]" is auditable against.
 */
final class ImpersonationEndReasonTest extends TestCase
{
    public function testHasExactlyThreeCases(): void
    {
        self::assertCount(3, ImpersonationEndReason::cases());
    }

    public function testEachCaseHasItsExpectedBackingValue(): void
    {
        self::assertSame('EXPLICIT_EXIT', ImpersonationEndReason::EXPLICIT_EXIT->value);
        self::assertSame('TIMEOUT', ImpersonationEndReason::TIMEOUT->value);
        self::assertSame('ACCOUNT_STATE_CHANGE', ImpersonationEndReason::ACCOUNT_STATE_CHANGE->value);
    }

    public function testFromValueRoundTripsForEachCase(): void
    {
        foreach (ImpersonationEndReason::cases() as $case) {
            self::assertSame($case, ImpersonationEndReason::from($case->value));
        }
    }
}
