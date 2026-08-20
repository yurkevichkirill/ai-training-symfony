<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Invite a coach" (AC-5, AC-19). No `data_class`, same reason as
 * `CreateTrainerFormType`'s docblock: the controller builds the readonly
 * `CoachInvitationRequest` from the submitted array itself.
 *
 * `email` is the only required field (AC-19) -- `name` and `message` are
 * both optional, matching `CoachInvitationRequest`'s own nullable
 * constructor defaults.
 */
final class CoachInvitationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('name', TextType::class, [
                'label' => 'Coach name',
                'required' => false,
                'constraints' => [new Length(max: 160)],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Personal message',
                'required' => false,
                'constraints' => [new Length(max: 2000)],
            ])
        ;
    }
}
