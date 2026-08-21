<?php

declare(strict_types=1);

namespace App\Tests\Form\DataTransformer;

use App\Form\DataTransformer\MinutesFromMidnightTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Unit coverage for `MinutesFromMidnightTransformer` (Task 18): the
 * model(int)<->view(\DateTimeImmutable) round trip, and the 1440 boundary.
 */
final class MinutesFromMidnightTransformerTest extends TestCase
{
    private MinutesFromMidnightTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new MinutesFromMidnightTransformer();
    }

    public function testTransformNullIsNull(): void
    {
        self::assertNull($this->transformer->transform(null));
    }

    public function testReverseTransformNullIsNull(): void
    {
        self::assertNull($this->transformer->reverseTransform(null));
    }

    public function testTransformProducesTheCorrectWallClockTime(): void
    {
        $dateTime = $this->transformer->transform(17 * 60 + 30);

        self::assertSame('17:30', $dateTime->format('H:i'));
    }

    public function testReverseTransformProducesTheCorrectMinuteCount(): void
    {
        $dateTime = new \DateTimeImmutable('1970-01-01 09:15:00', new \DateTimeZone('UTC'));

        self::assertSame(9 * 60 + 15, $this->transformer->reverseTransform($dateTime));
    }

    public function testRoundTripsForAnOrdinaryValue(): void
    {
        $minutes = 8 * 60;

        self::assertSame($minutes, $this->transformer->reverseTransform($this->transformer->transform($minutes)));
    }

    public function testTransformOf1440RollsOverToTheNextCalendarDayAtMidnight(): void
    {
        $dateTime = $this->transformer->transform(1440);

        self::assertSame('1970-01-02 00:00', $dateTime->format('Y-m-d H:i'));
    }

    public function testTransformRejectsAnOutOfRangeValue(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->transform(1441);
    }

    public function testTransformRejectsANegativeValue(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->transform(-1);
    }

    public function testReverseTransformRejectsANonDateTimeValue(): void
    {
        $this->expectException(TransformationFailedException::class);

        $this->transformer->reverseTransform('17:30');
    }
}
