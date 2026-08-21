<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\SwitchUserParameter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * S6 (AC-3, AC-11, D5b): closes the CSRF hole `SwitchUserListener` opens by
 * design -- it reads `_switch_user` from the query string on any method,
 * from the body on non-GET/HEAD, and runs at `kernel.request` priority 8,
 * *before* routing, so no controller of ours is ever reached on a
 * successful switch (verified against `SwitchUserListener::supports()`/
 * `authenticate()`). Only a subscriber running *above* priority 8 can stand
 * in front of it -- hence priority 32, the same priority
 * `SessionIdleSubscriber` uses, and for the same "must beat the firewall's
 * 8" reason.
 *
 * Fires only when `_switch_user` is present and its value is not `_exit`
 * (exit only ever *reduces* privilege, so it is exempt from all three
 * checks below -- requiring a CSRF token to stop impersonating would leave
 * a stuck session as the failure mode). When it fires, it refuses with
 * `AccessDeniedHttpException` unless **all** of:
 *
 * - the request method is POST (so a link, an image tag, or a cross-site
 *   GET form cannot trigger the switch);
 * - the request body carries a CSRF token valid for id
 *   `impersonate_<target email>` (matching `ImpersonationController::confirm()`'s
 *   form). The token id is derived from **the same `_switch_user` value that
 *   actually drives the switch** -- deliberately not from a separate hidden
 *   id field. An earlier version carried the target's id in its own
 *   `_impersonate_target_id` field and keyed the CSRF id off that instead;
 *   because nothing tied that field to `_switch_user`, a request could carry
 *   a CSRF token that was validly minted for one target (e.g. replayed from
 *   an earlier confirmation page still open in a tab) while `_switch_user`
 *   named a completely different one -- the proof would pass without
 *   actually being *about* the target being switched to. Keying the token
 *   id directly off `_switch_user` closes that: whatever email the switch
 *   will act on is exactly the email the CSRF token must have been minted
 *   for, so the proof cannot be re-pointed at a different target than the
 *   one it was issued for. No database lookup is needed either way, since
 *   `_switch_user` is already the value read here and by the native
 *   listener;
 * **Nesting is deliberately NOT checked here.** At priority 32 the firewall
 * has not run yet, so `ContextListener` has not restored the token and
 * `TokenStorageInterface::getToken()` is still `null` on every request --
 * an `instanceof SwitchUserToken` test in this class can never be true, and
 * an earlier version of it carried exactly that dead check while its
 * docblock claimed nesting was guarded here. The rule lives where the token
 * actually exists: `ImpersonationSwitchSubscriber` refuses the listener's
 * implicit exit-then-re-switch (see that class -- `ImpersonationVoter`'s own
 * `!$token instanceof SwitchUserToken` clause cannot catch it, because the
 * listener exits *before* asking the voter and so presents it the restored
 * original token), with `ImpersonationService::start()`'s open-row check and
 * the `uniq_impersonation_active_actor` index behind it.
 *
 * Reads `_switch_user` through `SwitchUserParameter`, which mirrors
 * `SwitchUserListener::supports()` clause for clause -- **including its
 * `_switch_user` request-header fallback**, the clause an earlier copy of
 * this guard missed, which let a header-carried switch past the POST and
 * CSRF requirements entirely. Reads only the request -- no token storage,
 * no database -- and does not duplicate the who-may-impersonate rule, which
 * belongs to `ImpersonationVoter` (the listener consults it regardless of
 * this guard).
 */
final class ImpersonationGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Above the firewall's priority 8 -- see the class docblock.
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // The exact value the native listener will switch to (an email,
        // per its provider lookup) -- also the value the CSRF token id is
        // bound to below, so the proof cannot be re-pointed at a different
        // target than the one actually being switched to.
        $switchUserTarget = SwitchUserParameter::fromRequest($request);

        if (null === $switchUserTarget || SwitchUserParameter::EXIT_VALUE === $switchUserTarget) {
            return;
        }

        if (!$request->isMethod(Request::METHOD_POST)) {
            throw new AccessDeniedHttpException('Impersonation must be triggered by a POST request.');
        }

        $csrfToken = (string) $request->request->get('_token', '');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(\sprintf('impersonate_%s', $switchUserTarget), $csrfToken))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
