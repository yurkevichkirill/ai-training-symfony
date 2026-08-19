<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects a password that appears verbatim (case-insensitively) in the
 * bundled offline common-password list (`src/Resources/security/common-
 * passwords.txt`), independent of `NotCompromisedPassword`'s HIBP lookup.
 *
 * This is the G-22 "blocklist" half of the password policy: no composition
 * rules, no rotation, but a password on a known-common list is refused
 * regardless of length. It exists specifically so the policy still bites
 * when HIBP is unreachable -- see `NotCompromisedPasswordValidator` and the
 * architecture's Risk on HIBP outages -- because `NotCompromisedPassword`'s
 * `skipOnError` (and this project's `when@test` config, which disables the
 * HIBP call entirely) both fail *open*, and something in the policy must
 * fail closed instead.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class NotBlocklistedPassword extends Constraint
{
    public const BLOCKLISTED_PASSWORD_ERROR = 'c2314bc9-9342-4d13-bafa-4c6cdc6b7a2e';

    protected const ERROR_NAMES = [
        self::BLOCKLISTED_PASSWORD_ERROR => 'BLOCKLISTED_PASSWORD_ERROR',
    ];

    public string $message = 'This password is one of the most commonly used passwords and cannot be used. Please choose a different password.';

    /**
     * @param string[]|null $groups
     */
    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
