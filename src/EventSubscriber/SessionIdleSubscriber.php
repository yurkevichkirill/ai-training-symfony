<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deterministic 8-hour inactivity expiry (AC-7), on top of
 * `gc_maxlifetime`'s probabilistic backstop (Task 13's framework.yaml).
 *
 * On every main request it compares "now" against a `_last_activity` value
 * stamped into the session, and invalidates the session outright once more
 * than `%app.session_idle_seconds%` has elapsed since the last one -- rather
 * than merely letting the *next* request find a stale session, which would
 * let one more authenticated request through on borrowed time.
 *
 * That "this very request" guarantee is why the listener priority matters.
 * `Firewall::onKernelRequest` -- which runs `ContextListener` (restores the
 * token from the session) and `AccessListener` (makes the access-control
 * decision) back to back, synchronously, inside one `kernel.request` call --
 * is itself registered at priority 8. This subscriber is registered *above*
 * that, so it always runs first and can invalidate the session before
 * `ContextListener` ever reads a token out of it. An expired session is
 * therefore empty by the time the firewall looks at it: `ContextListener`
 * finds no token, `AccessListener` sees an unauthenticated request, and the
 * catch-all `access_control` entry (Task 12) sends it through the normal
 * entry point -- exactly as if the cookie had never carried a session at
 * all. Registering at the default priority (i.e. after the firewall) cannot
 * produce that: the access decision for the current request would already be
 * made, using the token that was still valid when the firewall read it, and
 * only the *next* request would see the invalidation.
 *
 * "Authenticated session" is read straight out of the session bag rather
 * than `TokenStorageInterface`, precisely because at this priority the
 * firewall has not populated the token storage yet for this request --
 * that population is the thing being pre-empted. `ContextListener` stores
 * the serialized token under `_security_<contextKey>`, and `contextKey`
 * defaults to the firewall name when `security.yaml` sets no explicit
 * `context:` (it does not, for the `main` firewall) -- see
 * `SecurityExtension::buildFirewall()`. A request with no such key is either
 * unauthenticated or on the stateless `dev` firewall, and this subscriber
 * leaves both alone.
 */
final class SessionIdleSubscriber implements EventSubscriberInterface
{
    private const LAST_ACTIVITY_KEY = '_last_activity';

    /**
     * The session key `ContextListener` uses for the `main` firewall
     * (`security.yaml` sets no `context:`, so the context key is the
     * firewall name). See the class docblock.
     */
    private const SECURITY_TOKEN_SESSION_KEY = '_security_main';

    public function __construct(
        #[Autowire('%app.session_idle_seconds%')]
        private readonly int $idleSeconds,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must run before Firewall::onKernelRequest (priority 8, see the
            // class docblock) so an idle session dies before *this* request
            // can be authenticated by it, not only before the next one.
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasPreviousSession()) {
            // No session cookie came in at all -- nothing to police, and
            // reading the session would start one for an anonymous visitor.
            return;
        }

        $session = $request->getSession();

        if (null === $session->get(self::SECURITY_TOKEN_SESSION_KEY)) {
            // A session exists but is not authenticated (e.g. flash messages
            // survive from an earlier request). Idle expiry is a session
            // property that only matters once a session is authenticated.
            return;
        }

        $now = time();
        $lastActivity = $session->get(self::LAST_ACTIVITY_KEY);

        if (null !== $lastActivity && $now - (int) $lastActivity > $this->idleSeconds) {
            $session->invalidate();

            return;
        }

        $session->set(self::LAST_ACTIVITY_KEY, $now);
    }
}
