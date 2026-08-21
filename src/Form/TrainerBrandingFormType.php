<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The colour half of `/trainer/branding` (AC-8, AC-9), over
 * `TrainerBrandingRequest`. In `ProfileTrainerFormType`'s established
 * array-data style: no `data_class`. `ColorType` renders `<input
 * type="color">`, which is the epic's "color picker" natively and emits
 * `#rrggbb` by construction; the `Regex`/`Length` pair is the server-side
 * authority regardless of what the browser widget sent, so a non-JS or
 * spoofed submission is still validated (AC-9). No logo field on this
 * type -- the upload is its own action.
 */
final class TrainerBrandingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('primaryColorHex', ColorType::class, [
                'label' => 'Primary brand color',
                'required' => false,
                'constraints' => [
                    new Regex(
                        pattern: '/^#[0-9a-f]{6}$/i',
                        message: 'Enter a valid hex color, e.g. #0b5fae.',
                    ),
                    new Length(exactly: 7),
                ],
            ])
        ;
    }
}
