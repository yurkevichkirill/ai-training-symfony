<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The coach-specific fields embedded in `/profile` when the signed-in user
 * is a coach (Task 19; AC-11, AC-12, AC-13). No `NotBlank` constraint
 * anywhere -- that absence is AC-16. No `email`, `role`, or `createdAt`
 * field on this type -- that absence is AC-14.
 */
final class ProfileCoachFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('bio', TextareaType::class, [
                'required' => false,
                'constraints' => [new Length(max: 4000)],
            ])
            ->add('credentials', TextareaType::class, [
                'required' => false,
                'constraints' => [new Length(max: 2000)],
            ])
            ->add('certifications', TextareaType::class, [
                'required' => false,
                'constraints' => [new Length(max: 2000)],
            ])
            ->add('isPublic', CheckboxType::class, [
                'label' => 'Public profile',
                'required' => false,
            ])
        ;
    }
}
