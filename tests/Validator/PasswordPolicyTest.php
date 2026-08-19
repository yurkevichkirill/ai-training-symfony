<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\Constraints\NotBlocklistedPassword;
use App\Validator\Constraints\NotBlocklistedPasswordValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Exercises the G-22 password policy (AC-4) directly against the Symfony
 * Validator component, on a raw string value rather than a form or DTO --
 * `ChangePasswordFormType` (Task 31) and the console command's input DTO
 * (Task 36) do not exist yet, but the constraints they will apply are built
 * and proven here so those tasks only need to wire them up.
 *
 * `NotCompromisedPasswordValidator` is constructed directly with its
 * `$enabled` constructor argument set to `false` -- the exact mechanism this
 * project's own `config/packages/validator.yaml` (`when@test:
 * not_compromised_password: false`) uses to disable the real HIBP HTTP call
 * in the test environment. Passing `false` here is a stub of that same
 * "unreachable/disabled" state: the validator returns without ever making a
 * network call, i.e. it fails *open*. That is precisely the HIBP-outage
 * scenario the architecture's Risk section describes, and the point of this
 * test class is to prove `NotBlocklistedPassword` still fails *closed* when
 * it happens.
 */
final class PasswordPolicyTest extends TestCase
{
    private const BLOCKLIST_PATH = __DIR__.'/../../src/Resources/security/common-passwords.txt';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $factory = new ConstraintValidatorFactory([
            // Stubbed to fail-open, simulating HIBP being unreachable.
            NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator(
                httpClient: null,
                charset: 'UTF-8',
                enabled: false,
            ),
            NotBlocklistedPasswordValidator::class => new NotBlocklistedPasswordValidator(self::BLOCKLIST_PATH),
        ]);

        $this->validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory($factory)
            ->getValidator();
    }

    /**
     * @return list<Length|NotCompromisedPassword|NotBlocklistedPassword>
     */
    private function policyConstraints(): array
    {
        return [
            new Length(min: 12, max: 4096, countUnit: Length::COUNT_BYTES),
            new NotCompromisedPassword(),
            new NotBlocklistedPassword(),
        ];
    }

    public function testElevenCharacterPasswordFailsMinimumLength(): void
    {
        $violations = $this->validator->validate('abcdefghijk', $this->policyConstraints());

        self::assertCount(1, $violations);
        self::assertSame(Length::TOO_SHORT_ERROR, $violations->get(0)->getCode());
    }

    /**
     * The literal instruction is "a 12-character password with a non-ASCII
     * multi-byte character passes and is not truncated" -- but the only
     * assertion that actually *distinguishes* byte-counting from
     * character-counting is one where the two counts disagree on which side
     * of the minimum the value falls. A value with >=12 codepoints and extra
     * multi-byte bytes would pass under either counting mode, proving
     * nothing about which one ran.
     *
     * So this fixture has 11 codepoints (mb_strlen) -- which would fail
     * Length::COUNT_CODEPOINTS -- but 13 bytes (strlen), because it ends in
     * one 3-byte UTF-8 character (U+65E5, "日"). It must PASS under
     * COUNT_BYTES. The companion assertion below proves the codepoint-mode
     * alternative really would have rejected the same value, so the pass is
     * not a coincidence of a lenient constraint.
     */
    public function testMultiBytePasswordIsValidatedByByteLengthNotCharacterLength(): void
    {
        $password = 'abcdefghij日';

        self::assertSame(11, mb_strlen($password), 'fixture must have 11 codepoints');
        self::assertSame(13, \strlen($password), 'fixture must be 13 bytes');

        $byteViolations = $this->validator->validate($password, [
            new Length(min: 12, max: 4096, countUnit: Length::COUNT_BYTES),
        ]);
        self::assertCount(0, $byteViolations, 'byte length (13) satisfies the 12-byte minimum');

        $codepointViolations = $this->validator->validate($password, [
            new Length(min: 12, max: 4096, countUnit: Length::COUNT_CODEPOINTS),
        ]);
        self::assertCount(1, $codepointViolations, 'codepoint length (11) would have failed the same minimum');
        self::assertSame(Length::TOO_SHORT_ERROR, $codepointViolations->get(0)->getCode());
    }

    public function testPasswordOverByteLimitFails(): void
    {
        $password = str_repeat('a', 4097);

        $violations = $this->validator->validate($password, $this->policyConstraints());

        self::assertCount(1, $violations);
        $violation = $violations->get(0);
        self::assertSame(Length::TOO_LONG_ERROR, $violation->getCode());

        // The full, untruncated byte length is what was measured -- 4097,
        // not clipped to 4096 -- proving the value was never silently
        // truncated before validation (AC-4's edge case).
        self::assertSame('4097', (string) $violation->getParameters()['{{ value_length }}']);
    }

    /**
     * The offline blocklist must still catch a known-common password even
     * when NotCompromisedPassword has failed open (HIBP outage, simulated
     * per this class's docblock). "password123456" is an exact, verified
     * entry in src/Resources/security/common-passwords.txt.
     */
    public function testBlocklistedPasswordFailsEvenWhenNotCompromisedPasswordFailsOpen(): void
    {
        $password = 'password123456';
        self::assertGreaterThanOrEqual(12, \strlen($password));
        self::assertTrue($this->isInBundledBlocklist($password), 'fixture must exist in the bundled list');

        $violations = $this->validator->validate($password, $this->policyConstraints());

        self::assertCount(1, $violations, 'Length and the stubbed-open NotCompromisedPassword must not also complain');
        $violation = $violations->get(0);
        self::assertSame(NotBlocklistedPassword::BLOCKLISTED_PASSWORD_ERROR, $violation->getCode());
        self::assertInstanceOf(NotBlocklistedPassword::class, $violation->getConstraint());
    }

    /**
     * The blocklist match is case-insensitive on purpose (G-22: no
     * composition rules) -- capitalizing a common password must not be
     * treated as making it acceptable.
     */
    public function testBlocklistMatchIsCaseInsensitive(): void
    {
        $violations = $this->validator->validate('PASSWORD123456', $this->policyConstraints());

        self::assertCount(1, $violations);
        self::assertSame(NotBlocklistedPassword::BLOCKLISTED_PASSWORD_ERROR, $violations->get(0)->getCode());
    }

    /**
     * A password that is long enough and not on the blocklist passes the
     * whole policy cleanly.
     */
    public function testPolicyCompliantPasswordPasses(): void
    {
        $violations = $this->validator->validate('Xk7!qzR2vLm9pT', $this->policyConstraints());

        self::assertCount(0, $violations);
    }

    private function isInBundledBlocklist(string $password): bool
    {
        $lines = file(self::BLOCKLIST_PATH, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            if (0 === strcasecmp($line, $password)) {
                return true;
            }
        }

        return false;
    }
}
