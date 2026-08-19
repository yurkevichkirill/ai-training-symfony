<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The single field on `/verify-email/resend` (AC-13, AC-14, AC-20).
 *
 * Deliberately just format validation here -- whether the address belongs to
 * an account, and whether that account is already verified, is
 * `EmailVerificationService::resend()`'s business, not this form's: the form
 * must accept and submit successfully for *any* well-formed address, or the
 * response shape itself would leak which addresses exist (AC-11-shaped
 * non-enumeration, by analogy).
 *
 * CSRF is the project-standard Symfony Form protection: `config/packages/
 * csrf.yaml` sets `framework.form.csrf_protection.token_id: submit` and lists
 * `submit` in `stateless_token_ids`, so a plain `createForm()` call already
 * picks up the same stateless, same-origin-checked token every other form in
 * this project uses -- nothing to configure here.
 */
final class ResendVerificationFormType extends AbstractType
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
