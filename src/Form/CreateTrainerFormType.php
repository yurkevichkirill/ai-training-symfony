<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * "Create User" -> Trainer (US-01.01, AC-4). No `data_class`, mirroring
 * every S1 input form (`ResetPasswordRequestFormType`,
 * `ChangePasswordFormType`, ...): the controller builds the readonly
 * `CreateTrainerRequest` from the submitted array itself, since a readonly
 * DTO with required constructor arguments has no writable properties for
 * the form component's default `PropertyAccessor`-based mapping to target.
 *
 * Whether the email is already in use is `TrainerOnboardingService`'s
 * business (it maps the database's own unique-constraint rejection to a
 * field error, per S1's `UserAccountService` precedent) -- not this form's;
 * a `UniqueEntity` pre-check here would still be only a friendly fast path,
 * never authoritative under concurrency (AC-7).
 */
final class CreateTrainerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('businessName', TextType::class, [
                'label' => 'Business name',
                'constraints' => [new NotBlank(), new Length(max: 160)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Contact first name',
                'required' => false,
                'constraints' => [new Length(max: 80)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Contact last name',
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
