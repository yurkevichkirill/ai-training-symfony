<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\ImpersonationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * S6 (AC-8, AC-9, D4, D7): `KernelEvents::REQUEST`, priority **7** --
 * immediately *after* the firewall's 8. This is the mirror image of
 * `SessionIdleSubscriber`'s choice of 32 (see that class's docblock for the
 * same constraint from the other side):
 *
 * The firewall runs `ContextListener` (restores the token from the
 * session) and `AccessListener` (makes the access decision) back to back
 * inside its single priority-8 `kernel.request` call, so **no priority can
 * interpose between them.** Above 8 there is no token yet to inspect;
 * below 8 the access decision is already made. This subscriber sits just
 * below 8 and does not try to fix that decision -- it **short-circuits the
 * request with a `RedirectResponse`**, so an expired impersonated token
 * never reaches a controller and no impersonated action is ever executed
 * on borrowed time. The already-made access decision was, at worst, a
 * catch-all `ROLE_USER` grant for a request that now returns a 302.
 *
 * On a main request whose token is a `SwitchUserToken`, asks
 * `ImpersonationService::activeFor($actor)` (one indexed lookup, so an
 * ordinary non-impersonated request costs nothing -- this subscriber only
 * ever queries once it has already confirmed the token is a
 * `SwitchUserToken`):
 *
 * - open row, `expiresAt` in the future -> no-op;
 * - open row, `expiresAt` passed -> `expire()` the row as `TIMEOUT` and
 *   force-exit;
 * - no open row at all -> force-exit (the branch through which the sweep
 *   command and D7's forced ends -- `AccountLifecycleService::deactivate()`/
 *   `delete()` -- take effect in the browser).
 *
 * "Force-exit" restores `SwitchUserToken::getOriginalToken()` into token
 * storage and redirects to `app_home` -- the exact same restoration object
 * the native exit uses, so there is no second implementation of it.
 */
final class ImpersonationExpirySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ImpersonationService $impersonationService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Just below the firewall's priority 8 -- see the class docblock.
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$event->getRequest()->hasPreviousSession()) {
            // No session cookie came in, so this request cannot be carrying
            // a `SwitchUserToken` -- and asking token storage anyway is not
            // free. On a `lazy` firewall the first `getToken()` call is what
            // *triggers* the deferred authentication, which sets
            // `_security_firewall_run` and so makes
            // `ContextListener::onKernelResponse()` persist a token into the
            // session on requests that would otherwise never have touched
            // it. That side effect already broke one invariant elsewhere
            // (`ResetPasswordController::reset()`, whose
            // `$session->invalidate()` was silently undone by the token
            // being re-persisted after it), so this subscriber keeps its
            // reach as narrow as the question it asks. Same guard, same
            // reasoning as `SessionIdleSubscriber`'s "reading the session
            // would start one for an anonymous visitor".
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (!$token instanceof SwitchUserToken) {
            return;
        }

        $actor = $token->getOriginalToken()->getUser();

        if (!$actor instanceof User) {
            return;
        }

        $session = $this->impersonationService->activeFor($actor);

        if (null !== $session && !$session->hasExpired(new \DateTimeImmutable())) {
            return;
        }

        if (null !== $session) {
            $this->impersonationService->expire($session);
        }

        $this->tokenStorage->setToken($token->getOriginalToken());

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_home')));
    }
}
