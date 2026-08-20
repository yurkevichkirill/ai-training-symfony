<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\Constraints\NotBlocklistedPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The registration form a brand-new coach reaches by opening their coach
 * invitation link while signed out (AC-14). No `data_class`, same reason as
 * `CreateTrainerFormType`'s docblock.
 *
 * **Deliberately no `email` field at all.** The account's email always
 * comes from the `CoachInvitation` being accepted
 * (`CoachRegistrationRequest`'s own constructor has no `email` parameter
 * either) -- this is structural enforcement of AC-21, not a UI
 * simplification, and must not be "fixed" by adding one for convenience.
 *
 * `plainPassword` reuses `ChangePasswordFormType`'s exact constraint set,
 * same as `PlayerShareLinkRegistrationFormType`. `phone` reuses S2's
 * `Assert\Regex`.
 */
final class CoachRegistrationFormType extends AbstractType
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
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'first_options' => [
                    'label' => 'Password',
                    'constraints' => [
                        new NotBlank(),
                        new Length(min: 12, max: 4096, countUnit: Length::COUNT_BYTES),
                        new NotCompromisedPassword(),
                        new NotBlocklistedPassword(),
                    ],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                ],
            ])
            ->add('phone', TelType::class, [
                'required' => false,
                'constraints' => [new Regex(pattern: '/^[0-9+()\-.\s]{7,32}$/', message: 'Enter a valid phone number.')],
            ])
        ;
    }
}
