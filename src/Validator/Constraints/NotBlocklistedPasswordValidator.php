<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Loads the bundled offline common-password list once and checks the
 * submitted value against it case-insensitively.
 *
 * Scope decision (Task 25): the bundled list is a curated set of a few
 * hundred genuinely common passwords and patterns -- not a literal top-100k
 * download, since this environment has no network access to fetch one. This
 * is a pragmatic stand-in: it is defensible for demonstrating and testing
 * the mechanism end to end, but a production deployment should replace
 * `src/Resources/security/common-passwords.txt` with a larger, vetted list
 * (e.g. the actual "10-million-password-list" / SecLists top-100k) without
 * any code change, since only the file's contents need to grow.
 *
 * Matching is case-insensitive by design: the list stores lowercase entries,
 * and the submitted value is lowercased before comparison, so
 * "Password123456" is caught by the same entry as "password123456". G-22
 * asks for no composition rules, so case alone must not be treated as making
 * an otherwise-common password acceptable.
 */
final class NotBlocklistedPasswordValidator extends ConstraintValidator
{
    /** @var array<string, true>|null */
    private ?array $blocklist = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Resources/security/common-passwords.txt')]
        private readonly string $blocklistPath,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotBlocklistedPassword) {
            throw new UnexpectedTypeException($constraint, NotBlocklistedPassword::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $normalized = mb_strtolower((string) $value);

        if (isset($this->blocklist()[$normalized])) {
            $this->context->buildViolation($constraint->message)
                ->setCode(NotBlocklistedPassword::BLOCKLISTED_PASSWORD_ERROR)
                ->addViolation();
        }
    }

    /**
     * @return array<string, true>
     */
    private function blocklist(): array
    {
        if (null !== $this->blocklist) {
            return $this->blocklist;
        }

        $lines = file($this->blocklistPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        $entries = [];
        foreach ($lines as $line) {
            $line = mb_strtolower(trim($line));

            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $entries[$line] = true;
        }

        return $this->blocklist = $entries;
    }
}
