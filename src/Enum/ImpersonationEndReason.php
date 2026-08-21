<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How an `ImpersonationSession` (S6) was closed. Exactly three cases,
 * backed `varchar(24)` on `impersonation_session.end_reason` -- this is
 * what makes AC-9's "ended exactly once, by exactly one of two reasons [or
 * a forced third]" auditable rather than inferred.
 */
enum ImpersonationEndReason: string
{
    /** AC-6: the "Exit Impersonation" banner link (native `_exit`). */
    case EXPLICIT_EXIT = 'EXPLICIT_EXIT';

    /** AC-8: the 1-hour deadline, closed by the expiry subscriber or the sweep command. */
    case TIMEOUT = 'TIMEOUT';

    /** D7: either party (actor or subject) was deactivated or deleted mid-session. */
    case ACCOUNT_STATE_CHANGE = 'ACCOUNT_STATE_CHANGE';
}
