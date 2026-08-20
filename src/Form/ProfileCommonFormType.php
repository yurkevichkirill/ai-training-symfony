<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The fields every role edits about its own profile (AC-10). Email, role,
 * skill level, and account-created date are read-only per FR-011 and never
 * appear on this form -- there is nothing for a client to tamper with to
 * make them editable, since the form does not carry them at all.
 *
 * No `data_class`: the caller builds the readonly `ProfileCommonRequest`
 * from the submitted array itself (see `CreateTrainerFormType`'s docblock
 * for why).
 */
final class ProfileCommonFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 80)],
            ])
            ->add('lastName', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 80)],
            ])
            ->add('phone', TelType::class, [
                'required' => false,
                'constraints' => [new Regex(pattern: '/^[0-9+()\-.\s]{7,32}$/', message: 'Enter a valid phone number.')],
            ])
        ;
    }
}
