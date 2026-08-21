<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\MinutesFromMidnightTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Task 28 (AC-19, AC-24): one contiguous availability span, the form-level
 * counterpart of `App\Availability\TimeRange` -- but deliberately *not*
 * backed by it as a `data_class`. `TimeRange::__construct()` throws on
 * `startsAtMinute >= endsAtMinute`, which would turn an ordinary user
 * mistake into an uncaught exception instead of a field-level validation
 * error; this form keeps the submitted `start`/`end` as a plain compound
 * array (`['start' => int, 'end' => int]`, post-transform) and leaves
 * constructing the real, invariant-enforcing `TimeRange` value object to the
 * owning controller/service, once validation has already passed.
 *
 * `start`/`end` are `TimeType` `single_text` widgets; `Task 18`'s
 * `MinutesFromMidnightTransformer` bridges each one's `\DateTimeImmutable`
 * view value to the stored minutes-from-midnight `int` model value the
 * `Range(0, 1440)` constraint below validates directly.
 *
 * The form-level `Assert\Callback` asserts `start < end` -- the one
 * invariant `TimeRange` itself would otherwise throw on -- surfaced here as
 * an ordinary validation error instead.
 */
final class TimeRangeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('start', TimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotNull(), new Range(min: 0, max: 1440)],
            ])
            ->add('end', TimeType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotNull(), new Range(min: 0, max: 1440)],
            ])
        ;

        $builder->get('start')->addModelTransformer(new MinutesFromMidnightTransformer());
        $builder->get('end')->addModelTransformer(new MinutesFromMidnightTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [
                new Callback([self::class, 'validateStartBeforeEnd']),
            ],
        ]);
    }

    /**
     * @param array{start?: ?int, end?: ?int} $data
     */
    public static function validateStartBeforeEnd(array $data, ExecutionContextInterface $context): void
    {
        $start = $data['start'] ?? null;
        $end = $data['end'] ?? null;

        if (null !== $start && null !== $end && $start >= $end) {
            $context->buildViolation('The start time must be before the end time.')
                ->atPath('end')
                ->addViolation();
        }
    }
}
