<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * S6: the single reader of the `_switch_user` request parameter, mirroring
 * `SwitchUserListener::supports()` **clause for clause** -- query string on
 * any method, request body on non-GET/HEAD, and the `_switch_user` request
 * **header** as the fallback when neither yields a non-empty value.
 *
 * It exists because two places need the exact value the native listener
 * will act on -- `ImpersonationGuardSubscriber` (which binds the CSRF token
 * id to it, AC-11) and `ImpersonationSwitchSubscriber` (which uses it to
 * tell a deliberate exit apart from the listener's implicit
 * exit-then-re-switch, AC-9) -- and because a reader that drifts from the
 * listener's own is a security hole rather than a style problem: the header
 * clause was missing from an earlier copy, and a request carrying only the
 * header sailed past the POST-and-CSRF guard while still driving a real
 * switch. One copy, mirroring one vendor method, is the only way that stays
 * fixed.
 *
 * `parameter: _switch_user` is stated explicitly in `security.yaml` for the
 * same reason -- this class hard-depends on that name.
 */
final class SwitchUserParameter
{
    public const NAME = '_switch_user';
    public const EXIT_VALUE = '_exit';

    private function __construct()
    {
    }

    /**
     * The value the native listener will switch to, or `null` when the
     * request carries none. `'_exit'` is returned as-is -- callers decide
     * what an exit means to them.
     */
    public static function fromRequest(Request $request): ?string
    {
        $target = $request->query->get(self::NAME)
            ?? (!$request->isMethod(Request::METHOD_GET) && !$request->isMethod(Request::METHOD_HEAD)
                ? $request->request->get(self::NAME)
                : null);

        if (null === $target || '' === $target) {
            $target = $request->headers->get(self::NAME);
        }

        if (null === $target || '' === $target) {
            return null;
        }

        return (string) $target;
    }
}
