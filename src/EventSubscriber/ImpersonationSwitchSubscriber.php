<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\ImpersonationEndReason;
use App\Security\IpTruncator;
use App\Security\SwitchUserParameter;
use App\Service\ImpersonationService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * S6 (AC-6, AC-7, AC-10, AC-11, D2c): the only place `impersonation_session`
 * rows are written from an actual switch. One branch, using no state of
 * its own -- `SwitchUserEvent` is dispatched on both switch and exit, and
 * the two are distinguishable by the event's own token: on a switch
 * `getToken()` is a `SwitchUserToken`; on an exit it is the restored
 * original token and `getTargetUser()` is the admin.
 *
 * This is what makes AC-11 hold *by construction*: `SwitchUserListener`
 * cannot mint a `SwitchUserToken` without dispatching this event, so there
 * is no path to an impersonated session that skips producing its audit
 * record.
 *
 * **This is also where nesting is refused (AC-9).** Neither of the two
 * places one would look for that rule can enforce it:
 *
 * - `ImpersonationGuardSubscriber` runs at `kernel.request` priority 32, so
 *   token storage is still empty there -- it cannot know a switch is live.
 * - `ImpersonationVoter`'s `!$token instanceof SwitchUserToken` clause is
 *   never reached on a second switch, because `attemptSwitchUser()` sees the
 *   existing `SwitchUserToken` and **exits first** ("User already switched,
 *   exit before seamlessly switching to another user"), then asks the voter
 *   with the *restored original* admin token -- which is a perfectly
 *   ordinary, grantable pairing.
 *
 * So a second confirmation form, legitimately obtained in another tab before
 * the first switch and submitted after it, used to sail through: one click
 * silently closed session A as `EXPLICIT_EXIT` and opened session B, with
 * nothing in the timeline saying the admin never asked to exit. The exit
 * branch below therefore refuses any exit whose request is *not* an explicit
 * `_switch_user=_exit` -- i.e. the listener's implicit exit -- by throwing
 * `AccessDeniedException` **before** closing the row, so the live session is
 * left untouched and the request 403s. A deliberate exit is unaffected, and
 * the forced ends (D7, expiry) never come through this event at all.
 */
final class ImpersonationSwitchSubscriber
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: SwitchUserEvent::class)]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $token = $event->getToken();
        $targetUser = $event->getTargetUser();

        if (!$targetUser instanceof User) {
            return;
        }

        if ($token instanceof SwitchUserToken) {
            $originalUser = $token->getOriginalToken()->getUser();

            if (!$originalUser instanceof User) {
                return;
            }

            $this->impersonationService->start($originalUser, $targetUser, $this->clientIp());

            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $requestedTarget = null !== $request ? SwitchUserParameter::fromRequest($request) : null;

        if (null !== $requestedTarget && SwitchUserParameter::EXIT_VALUE !== $requestedTarget) {
            // The listener's implicit exit-before-re-switch, not an exit the
            // admin asked for -- refuse before anything is closed.
            throw new AccessDeniedException('Nested impersonation is not permitted: exit the current session first.');
        }

        $this->impersonationService->end($targetUser, ImpersonationEndReason::EXPLICIT_EXIT);
    }

    private function clientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        $ip = $request->getClientIp();

        return null !== $ip ? IpTruncator::truncate($ip) : null;
    }
}
