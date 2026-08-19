<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The single field on `/reset-password` (AC-9, AC-11).
 *
 * Deliberately just format validation here, mirroring
 * `ResendVerificationFormType` exactly: whether the address belongs to a
 * registered account is `PasswordResetService::request()`'s business, not
 * this form's -- the form must accept and submit successfully for *any*
 * well-formed address, or the response shape itself would leak which
 * addresses exist (AC-11's non-enumeration guarantee).
 *
 * CSRF is the project-standard Symfony Form protection: `config/packages/
 * csrf.yaml` sets `framework.form.csrf_protection.token_id: submit` and lists
 * `submit` in `stateless_token_ids`, so a plain `createForm()` call already
 * picks up the same stateless, same-origin-checked token every other form in
 * this project uses -- nothing to configure here.
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'constraints' => [
                new NotBlank(),
                new Email(),
            ],
        ]);
    }
}
