<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Availability\TimeRange;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * The model<->view bridge for one `TimeRangeFormType` endpoint (`start` or
 * `end`): the model side is the stored `smallint` -- minutes from local
 * midnight (D5c) -- and the view side is the `\DateTimeImmutable` a
 * `TimeType` `single_text`/choice widget needs to render an `<input
 * type="time">`-style control.
 *
 * `transform()` (model -> view) anchors every value to the same fixed
 * reference date (`1970-01-01`, UTC) and adds the minute count as an
 * interval, so `endsAtMinute = 1440` ("midnight at the end of the day",
 * {@see TimeRange}) rolls cleanly over to `1970-01-02 00:00` instead of the
 * invalid "24:00" -- `\DateTimeImmutable` has no such hour, but adding an
 * interval always produces a valid instant.
 *
 * `reverseTransform()` (view -> model) reads only the hour/minute the widget
 * actually exposes, ignoring the calendar date entirely: no `TimeType`
 * widget can submit "hour 24", so a submitted end-of-day value always comes
 * back as hour 0 on whatever reference date the widget used, which
 * reverses to minute 0, not 1440. Round-tripping an existing `1440` row
 * through the widget unchanged therefore reads back as `0`, an intrinsic
 * asymmetry of the "add a whole day of minutes" encoding, not a bug in this
 * class -- distinguishing "runs to midnight" from "starts at midnight" on
 * resubmission is the owning form's job (a dedicated "until midnight"
 * affordance), not this transformer's.
 */
final class MinutesFromMidnightTransformer implements DataTransformerInterface
{
    private const REFERENCE_DATE = '1970-01-01 00:00:00';

    /**
     * @param mixed $value the stored minutes-from-midnight, `int` or `null`
     */
    public function transform(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if (!\is_int($value)) {
            throw new TransformationFailedException('Expected an int or null.');
        }

        if ($value < 0 || $value > TimeRange::MINUTES_PER_DAY) {
            throw new TransformationFailedException(\sprintf('Expected a value between 0 and %d, got %d.', TimeRange::MINUTES_PER_DAY, $value));
        }

        $reference = new \DateTimeImmutable(self::REFERENCE_DATE, new \DateTimeZone('UTC'));

        return $reference->modify(\sprintf('+%d minutes', $value));
    }

    /**
     * @param mixed $value the widget's `\DateTimeImmutable`, or `null`
     */
    public function reverseTransform(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeImmutable) {
            throw new TransformationFailedException('Expected a \DateTimeImmutable.');
        }

        return ((int) $value->format('H') * 60) + (int) $value->format('i');
    }
}
