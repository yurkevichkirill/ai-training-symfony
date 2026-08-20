<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PlayerGender;
use App\Validator\Constraints\NotBlocklistedPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The registration form a player reaches by following a player ShareLink
 * while signed out (AC-7). No `data_class`, same reason as
 * `CreateTrainerFormType`'s docblock: the controller builds the readonly
 * `PlayerRegistrationRequest` from the submitted array itself.
 *
 * `plainPassword` reuses `ChangePasswordFormType`'s exact constraint set
 * (`RepeatedType`, `Length(min: 12, COUNT_BYTES)`, `NotCompromisedPassword`,
 * `NotBlocklistedPassword`) -- S1's password policy, not re-derived here.
 * `phone` reuses S2's `Assert\Regex` (`CreateTrainerFormType`/
 * `ProfileCommonFormType`'s pattern). `playerAge`'s `Range(min: 1, max: 120)`
 * is the epic's placeholder for the out-of-scope Child model, not an
 * age-of-consent check. `playerGender` is a `Choice` over `PlayerGender`'s
 * cases, matching every other enum-backed field in this project's forms
 * being validated by an explicit constraint rather than relying only on the
 * form type's own transformation.
 */
final class PlayerShareLinkRegistrationFormType extends AbstractType
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
            ->add('email', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
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
            ->add('playerName', TextType::class, [
                'label' => "Player's name",
                'constraints' => [new NotBlank(), new Length(max: 160)],
            ])
            ->add('playerAge', IntegerType::class, [
                'label' => "Player's age",
                'constraints' => [new NotBlank(), new Range(min: 1, max: 120)],
            ])
            ->add('playerGender', ChoiceType::class, [
                'label' => "Player's gender",
                'choices' => PlayerGender::cases(),
                'choice_label' => static fn (PlayerGender $gender): string => $gender->name,
                'choice_value' => static fn (?PlayerGender $gender): string => $gender?->value ?? '',
                'constraints' => [new NotBlank(), new Choice(choices: PlayerGender::cases())],
            ])
        ;
    }
}
