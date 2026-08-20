<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The business fields embedded in `/profile` when the signed-in user is a
 * trainer (AC-11). No `data_class`, same reason as `ProfileCommonFormType`.
 */
final class ProfileTrainerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('businessName', TextType::class, [
                'label' => 'Business name',
                'constraints' => [new NotBlank(), new Length(max: 160)],
            ])
            ->add('website', UrlType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('address', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
            ])
        ;
    }
}
