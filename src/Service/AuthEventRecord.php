<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AuthEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Everything `AuthEventRecorder::record()` needs to write one `AuthEvent`
 * row (AC-24), and structurally nothing else.
 *
 * The constructor is deliberately narrow: `type`, `outcome`, `userId`,
 * `identifierAttempted`, `ip`, `userAgent`, `context`. There is no
 * `$request`, no `$password`, no `$token`, and no free-form payload a
 * password or token could ride in on via `context` -- every value here is
 * either an enum, a nullable scalar, an object identifier, or a
 * `array<string, scalar>` the caller assembles from named values it already
 * chose to disclose. That is by construction, not by convention: a future
 * edit cannot silently widen this DTO to carry secret material without
 * changing this file, which is exactly the property Task 35's reflection
 * test asserts.
 *
 * `outcome` stays a plain string (not its own enum) because its vocabulary
 * differs by `type` -- a login failure has several distinguishable reasons
 * (see the `OUTCOME_*` constants below, sourced from `AccountStatusChecker`'s
 * exception classes), while every other event type only ever reports one.
 * The constants exist so call sites never spell the raw string twice, and
 * every value here fits `AuthEvent::$outcome`'s `varchar(16)` column.
 *
 * @param array<string, scalar> $context
 */
final readonly class AuthEventRecord
{
    public const OUTCOME_SUCCESS = 'success';

    /** Correct account, wrong password -- AC-2's "wrong password" cause. */
    public const OUTCOME_BAD_CREDENTIALS = 'bad_credentials';

    /** No account exists for the submitted identifier -- AC-2's "unknown email" cause. */
    public const OUTCOME_UNKNOWN_ACCOUNT = 'unknown_account';

    /** Correct password, but `AccountStatusChecker` threw `AccountDeactivatedException`. */
    public const OUTCOME_ACCOUNT_DEACTIVATED = 'deactivated';

    /** Correct password, but `AccountStatusChecker` threw `EmailNotVerifiedException`. */
    public const OUTCOME_EMAIL_NOT_VERIFIED = 'unverified';

    public function __construct(
        public AuthEventType $type,
        public string $outcome,
        public ?Uuid $userId = null,
        public ?string $identifierAttempted = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public array $context = [],
    ) {
    }
}
