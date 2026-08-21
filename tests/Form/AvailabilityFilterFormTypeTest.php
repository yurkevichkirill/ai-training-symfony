<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Availability\WeeklyAvailability;
use App\Form\AvailabilityFilterFormType;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Coverage for `AvailabilityFilterFormType` (Task 29, AC-23): both fields
 * optional, and submitting neither is a valid ("unfiltered") submission.
 */
final class AvailabilityFilterFormTypeTest extends TypeTestCase
{
    public function testSubmittingNeitherFieldIsValid(): void
    {
        $form = $this->factory->create(AvailabilityFilterFormType::class);

        $form->submit(['dayOfWeek' => '', 'time' => '']);

        self::assertTrue($form->isValid());
        self::assertNull($form->get('dayOfWeek')->getData());
        self::assertNull($form->get('time')->getData());
    }

    public function testSubmittingOnlyDayOfWeekIsValid(): void
    {
        $form = $this->factory->create(AvailabilityFilterFormType::class);

        $form->submit(['dayOfWeek' => (string) WeeklyAvailability::WEDNESDAY, 'time' => '']);

        self::assertTrue($form->isValid());
        self::assertSame(WeeklyAvailability::WEDNESDAY, $form->get('dayOfWeek')->getData());
    }

    public function testSubmittingBothFieldsIsValid(): void
    {
        $form = $this->factory->create(AvailabilityFilterFormType::class);

        $form->submit(['dayOfWeek' => (string) WeeklyAvailability::MONDAY, 'time' => '14:00']);

        self::assertTrue($form->isValid());
        self::assertSame(WeeklyAvailability::MONDAY, $form->get('dayOfWeek')->getData());
        self::assertSame('14:00', $form->get('time')->getData()->format('H:i'));
    }
}
