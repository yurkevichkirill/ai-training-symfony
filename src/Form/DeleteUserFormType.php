<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The optional free-text reason on the GDPR-deletion confirmation (US-01.13,
 * AC-21). Confirmation itself is the CSRF-protected POST; there is nothing
 * else to validate here.
 */
final class DeleteUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextType::class, [
            'required' => false,
            'constraints' => [new Length(max: 120)],
        ]);
    }
}
