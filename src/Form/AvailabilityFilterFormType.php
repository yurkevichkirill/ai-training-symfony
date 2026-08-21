<?php

declare(strict_types=1);

namespace App\Form;

use App\Availability\WeeklyAvailability;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Task 29 (AC-23): the trainer roster's optional availability filter --
 * `dayOfWeek` (ISO `1`..`7`, `WeeklyAvailability`'s own Monday=1..Sunday=7
 * constants) and `time`, both optional. Submitting neither means an
 * unfiltered roster (`Task 22`'s repository query already treats "no
 * filter" as "everyone"); this form has no constraints forcing either field
 * -- there is nothing invalid about leaving both blank.
 *
 * `method`/`csrf_protection` are set for a `GET` filter form (a roster
 * filter is a read, not a state change, and a `GET` form's query-string
 * submission has no CSRF surface to protect).
 */
final class AvailabilityFilterFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dayOfWeek', ChoiceType::class, [
                'label' => 'Day of week',
                'required' => false,
                'placeholder' => 'Any day',
                'choices' => [
                    'Monday' => WeeklyAvailability::MONDAY,
                    'Tuesday' => WeeklyAvailability::TUESDAY,
                    'Wednesday' => WeeklyAvailability::WEDNESDAY,
                    'Thursday' => WeeklyAvailability::THURSDAY,
                    'Friday' => WeeklyAvailability::FRIDAY,
                    'Saturday' => WeeklyAvailability::SATURDAY,
                    'Sunday' => WeeklyAvailability::SUNDAY,
                ],
            ])
            ->add('time', TimeType::class, [
                'label' => 'Time',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'method' => Request::METHOD_GET,
        ]);
    }
}
