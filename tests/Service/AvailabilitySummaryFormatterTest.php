<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlayerAvailabilitySlot;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\AvailabilitySummaryFormatter;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `AvailabilitySummaryFormatter::summarize()` (Task 21,
 * AC-22): output format, day-truncation with the "+N more" tail, and the
 * "Not available" fallback for an empty slot list (D5/AC-24).
 */
final class AvailabilitySummaryFormatterTest extends TestCase
{
    private AvailabilitySummaryFormatter $formatter;
    private User $player;

    protected function setUp(): void
    {
        $this->formatter = new AvailabilitySummaryFormatter();
        $this->player = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('summary-player'));
    }

    public function testSummarizeProducesTheDocumentedFormat(): void
    {
        $slots = [
            $this->slot(1, 17 * 60, 20 * 60), // Mon 5-8pm
            $this->slot(3, 18 * 60, 21 * 60), // Wed 6-9pm
        ];

        self::assertSame('Mon 5-8pm, Wed 6-9pm', $this->formatter->summarize($slots));
    }

    public function testSummarizeCombinesMultipleRangesOnTheSameDay(): void
    {
        $slots = [
            $this->slot(1, 9 * 60, 10 * 60),
            $this->slot(1, 17 * 60, 18 * 60),
        ];

        self::assertSame('Mon 9-10am, 5-6pm', $this->formatter->summarize($slots));
    }

    public function testSummarizeTruncatesAfterMaxDaysWithAPlusNMoreTail(): void
    {
        $slots = [
            $this->slot(1, 17 * 60, 18 * 60),
            $this->slot(2, 17 * 60, 18 * 60),
            $this->slot(3, 17 * 60, 18 * 60),
            $this->slot(4, 17 * 60, 18 * 60),
            $this->slot(5, 17 * 60, 18 * 60),
        ];

        self::assertSame('Mon 5-6pm, Tue 5-6pm, Wed 5-6pm +2 more', $this->formatter->summarize($slots));
    }

    public function testSummarizeRespectsACustomMaxDays(): void
    {
        $slots = [
            $this->slot(1, 17 * 60, 18 * 60),
            $this->slot(2, 17 * 60, 18 * 60),
        ];

        self::assertSame('Mon 5-6pm +1 more', $this->formatter->summarize($slots, 1));
    }

    public function testSummarizeOfNoSlotsIsNotAvailable(): void
    {
        self::assertSame('Not available', $this->formatter->summarize([]));
    }

    public function testSummarizeKeepsBothPeriodsWhenARangeCrossesNoon(): void
    {
        $slots = [$this->slot(2, 11 * 60, 13 * 60)];

        self::assertSame('Tue 11am-1pm', $this->formatter->summarize($slots));
    }

    public function testSummarizeOrdersDaysByWeekdayRegardlessOfInputOrder(): void
    {
        $slots = [
            $this->slot(5, 17 * 60, 18 * 60),
            $this->slot(1, 17 * 60, 18 * 60),
        ];

        self::assertSame('Mon 5-6pm, Fri 5-6pm', $this->formatter->summarize($slots));
    }

    /**
     * Task 25 (D2d): a regression assertion, over the same fixture shapes
     * used by the tests above, that `summarize()`'s output is
     * byte-identical after Task 24 turned it into a two-line adapter over
     * `summarizeWeek()` -- this is what turns "behavior-preserving by
     * construction" into a checked fact rather than a claim.
     */
    public function testSummarizeOutputIsUnchangedAfterTheSummarizeWeekExtraction(): void
    {
        $slots = [
            $this->slot(1, 17 * 60, 20 * 60),
            $this->slot(3, 18 * 60, 21 * 60),
            $this->slot(1, 9 * 60, 10 * 60),
            $this->slot(5, 17 * 60, 18 * 60),
            $this->slot(2, 11 * 60, 13 * 60),
        ];

        self::assertSame('Mon 9-10am, 5-8pm, Tue 11am-1pm, Wed 6-9pm +1 more', $this->formatter->summarize($slots));
        self::assertSame('Not available', $this->formatter->summarize([]));
    }

    private function slot(int $dayOfWeek, int $startsAtMinute, int $endsAtMinute): PlayerAvailabilitySlot
    {
        return new PlayerAvailabilitySlot($this->player, $dayOfWeek, $startsAtMinute, $endsAtMinute);
    }
}
