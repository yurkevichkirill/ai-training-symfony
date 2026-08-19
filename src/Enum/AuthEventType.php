<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Every authentication-relevant thing S1 records (AC-24).
 *
 * The backing values are exactly what `AuthEvent::$type` (`varchar(64)`)
 * stores, so `AuthEventRecorder` never needs a separate mapping table.
 * `SUPER_ADMIN_BOOTSTRAPPED` is defined here even though nothing constructs
 * it yet -- `CreateSuperAdminCommand` (Task 36) is the only intended writer,
 * and the type belongs on this enum now so that command's wiring is a single
 * `AuthEventRecorder::record()` call rather than a schema change too.
 */
enum AuthEventType: string
{
    case LOGIN_SUCCEEDED = 'LOGIN_SUCCEEDED';
    case LOGIN_FAILED = 'LOGIN_FAILED';
    case LOGGED_OUT = 'LOGGED_OUT';
    case PASSWORD_RESET_REQUESTED = 'PASSWORD_RESET_REQUESTED';
    case PASSWORD_RESET_COMPLETED = 'PASSWORD_RESET_COMPLETED';
    case EMAIL_VERIFIED = 'EMAIL_VERIFIED';
    case SUPER_ADMIN_BOOTSTRAPPED = 'SUPER_ADMIN_BOOTSTRAPPED';
}
