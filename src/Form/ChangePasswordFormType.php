<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\Constraints\NotBlocklistedPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * The single field on `/reset-password/reset/{token}` (AC-9, AC-10, AC-12).
 *
 * `RepeatedType` wraps a `PasswordType` so the visitor confirms the new
 * password before it is submitted; `PasswordType`'s own `always_empty`
 * default keeps the field blank on re-render after a failed submission,
 * rather than echoing the typed value back.
 *
 * The three password-policy constraints are Task 25's exact combination,
 * applied together on the *first* password field only (per Task 25's own
 * text and `PasswordPolicyTest`'s coverage) -- `RepeatedType` already
 * enforces the two fields match via its own `invalid_message`, so repeating
 * the policy constraints on the confirmation field would only duplicate
 * violations for the same input:
 * - `Length(min: 12, max: 4096, countUnit: COUNT_BYTES)` -- AC-4's
 *   never-silently-truncated byte limit. Note `countUnit` (singular) --
 *   confirmed against the installed `symfony/validator`'s constructor
 *   signature, not assumed from the plan's own text, which uses the plural
 *   `countUnits` that this Symfony version does not accept.
 * - `NotCompromisedPassword` -- the HIBP breach-corpus check (disabled in
 *   `when@test`, per `config/packages/validator.yaml`).
 * - `NotBlocklistedPassword` -- the offline common-password list, so the
 *   policy still fails closed when HIBP is unreachable.
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'The password fields must match.',
            'first_options' => [
                'label' => 'New password',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 12, max: 4096, countUnit: Length::COUNT_BYTES),
                    new NotCompromisedPassword(),
                    new NotBlocklistedPassword(),
                ],
            ],
            'second_options' => [
                'label' => 'Repeat new password',
            ],
        ]);
    }
}
