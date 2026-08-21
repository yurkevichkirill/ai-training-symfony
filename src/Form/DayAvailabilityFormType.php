<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Task 28 (AC-19, AC-24): one weekday's availability -- a `CollectionType`
 * of `TimeRangeFormType` (`ranges`, `allow_add`/`allow_delete` for the
 * "add another range"/"remove this range" UI, `Count(max: 6)` per day),
 * plus a "Not Available" `notAvailable` checkbox.
 *
 * `notAvailable` is `mapped: false` deliberately: it is a client-side
 * affordance only ("check this to clear every range for the day"), never a
 * stored value -- canonical storage stays "zero rows" for a day with no
 * availability (D5, `WeeklyAvailability`'s own docblock), so this field
 * never reaches the model data this form produces. Clearing `ranges` itself
 * when the box is ticked is the owning template's job (progressive
 * enhancement / JS), not this form type's.
 */
final class DayAvailabilityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ranges', CollectionType::class, [
                'entry_type' => TimeRangeFormType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'constraints' => [new Count(max: 6)],
            ])
            ->add('notAvailable', CheckboxType::class, [
                'label' => 'Not available',
                'mapped' => false,
                'required' => false,
            ])
        ;
    }
}
