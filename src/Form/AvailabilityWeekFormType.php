<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Task 28 (AC-19, AC-24): the full weekly availability grid -- a
 * `CollectionType` of seven `DayAvailabilityFormType`s, one per ISO weekday
 * (`App\Availability\WeeklyAvailability`'s own `1` Monday .. `7` Sunday
 * keying). `allow_add`/`allow_delete` are `false` here: the seven days
 * themselves are fixed and never added to or removed -- only each day's
 * *ranges* grow/shrink (`DayAvailabilityFormType`'s own `CollectionType`).
 *
 * `getParent()` is `CollectionType` itself rather than a compound form
 * wrapping one: this type *is* the collection, so the owning controller
 * (Task 33) submits/renders it with data keyed `1..7` directly, no extra
 * nesting level.
 */
final class AvailabilityWeekFormType extends AbstractType
{
    public function getParent(): string
    {
        return CollectionType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'entry_type' => DayAvailabilityFormType::class,
            'allow_add' => false,
            'allow_delete' => false,
        ]);
    }
}
