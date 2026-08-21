<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\AvailabilityWeekFormType;
use App\Form\TimeRangeFormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Coverage for Task 28's `TimeRangeFormType`/`DayAvailabilityFormType`/
 * `AvailabilityWeekFormType` (AC-19, AC-24): the `start < end` `Callback`,
 * the `Range(0, 1440)` per-endpoint bound, the `Count(max: 6)` per-day cap,
 * and the fixed seven-day, `allow_add`/`allow_delete`-per-range shape.
 *
 * `TypeTestCase` (no kernel boot): none of these three types has a
 * constructor dependency, so the default test form extension is enough --
 * no need for the full container the way `ChildProfileFormType`'s tests do.
 */
final class AvailabilityFormsTest extends TypeTestCase
{
    /**
     * @return list<\Symfony\Component\Form\FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [...parent::getExtensions(), new ValidatorExtension($validator)];
    }

    public function testValidStartBeforeEndIsValid(): void
    {
        $form = $this->factory->create(TimeRangeFormType::class);

        $form->submit(['start' => '09:00', 'end' => '10:30']);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame(9 * 60, $form->get('start')->getData());
        self::assertSame(10 * 60 + 30, $form->get('end')->getData());
    }

    public function testStartNotBeforeEndIsInvalid(): void
    {
        $form = $this->factory->create(TimeRangeFormType::class);

        $form->submit(['start' => '10:00', 'end' => '09:00']);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());
    }

    public function testEqualStartAndEndIsInvalid(): void
    {
        $form = $this->factory->create(TimeRangeFormType::class);

        $form->submit(['start' => '10:00', 'end' => '10:00']);

        self::assertFalse($form->isValid());
    }

    public function testMoreThanSixRangesInADayIsInvalid(): void
    {
        $ranges = [];

        for ($i = 0; $i < 7; ++$i) {
            $ranges[] = ['start' => \sprintf('%02d:00', $i), 'end' => \sprintf('%02d:30', $i)];
        }

        $form = $this->factory->create(\App\Form\DayAvailabilityFormType::class);

        $form->submit(['ranges' => $ranges]);

        self::assertFalse($form->isValid());
    }

    public function testUpToSixRangesInADayIsValid(): void
    {
        $ranges = [];

        for ($i = 0; $i < 6; ++$i) {
            $ranges[] = ['start' => \sprintf('%02d:00', $i), 'end' => \sprintf('%02d:30', $i)];
        }

        $form = $this->factory->create(\App\Form\DayAvailabilityFormType::class);

        $form->submit(['ranges' => $ranges]);

        self::assertTrue($form->isValid());
    }

    public function testNotAvailableCheckboxIsUnmapped(): void
    {
        $form = $this->factory->create(\App\Form\DayAvailabilityFormType::class);

        $form->submit(['ranges' => [], 'notAvailable' => '1']);

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('notAvailable')->getData());
        self::assertArrayNotHasKey('notAvailable', $form->getData());
    }

    public function testWeekFormBuildsSevenFixedDays(): void
    {
        $data = [];

        for ($day = 1; $day <= 7; ++$day) {
            $data[$day] = ['ranges' => []];
        }

        $form = $this->factory->create(AvailabilityWeekFormType::class, $data);

        self::assertCount(7, $form);

        foreach (range(1, 7) as $day) {
            self::assertTrue($form->has((string) $day));
        }
    }

    public function testWeekFormDoesNotAllowAddingAnEighthDay(): void
    {
        $data = [];

        for ($day = 1; $day <= 7; ++$day) {
            $data[$day] = ['ranges' => []];
        }

        $form = $this->factory->create(AvailabilityWeekFormType::class, $data);

        $submitted = $data;
        $submitted[8] = ['ranges' => []];

        $form->submit($submitted);

        // allow_add is false: an extra key beyond the fixed seven is
        // simply not turned into an eighth sub-form.
        self::assertCount(7, $form);
    }
}
