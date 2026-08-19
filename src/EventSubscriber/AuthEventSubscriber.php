<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\AuthEventType;
use App\Security\Exception\AccountDeactivatedException;
use App\Security\Exception\EmailNotVerifiedException;
use App\Service\AuthEventRecord;
use App\Service\AuthEventRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * A thin adapter over Symfony's own security events, translating each into
 * an `AuthEventRecord` and handing it to `AuthEventRecorder` (AC-24). No
 * business logic lives here -- distinguishing *why* a login failed is the
 * only decision this class makes, and even that reads an exception's class
 * identity rather than computing anything.
 *
 * The reset, verification, and Super Admin bootstrap event types are not
 * subscribed here: `PasswordResetService`, `EmailVerificationService`, and
 * (once Task 36 exists) `CreateSuperAdminCommand` call `AuthEventRecorder`
 * directly, because none of those has a corresponding framework event to
 * listen for.
 */
final class AuthEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthEventRecorder $recorder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        $this->recorder->record(new AuthEventRecord(
            type: AuthEventType::LOGIN_SUCCEEDED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user instanceof User ? $user->getId() : null,
            ip: $event->getRequest()->getClientIp(),
            userAgent: $this->userAgent($event->getRequest()),
        ));
    }

    /**
     * `AccountStatusChecker`'s two exception classes (Task 14) distinguish a
     * deactivated account and an unverified one from each other and from the
     * two causes Symfony's own `AuthenticatorManager` already tells apart
     * (`UserNotFoundException` for an unknown address, everything else for a
     * wrong password) -- four distinguishable operator-visible reasons behind
     * `UniformAuthenticationFailureHandler`'s single visitor-visible message.
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        $outcome = $this->outcomeFor($exception);
        $badge = $event->getPassport()?->getBadge(UserBadge::class);

        // `UserBadge::getUserIdentifier()` never invokes the user loader --
        // it only ever returns the raw submitted string -- so this is safe
        // to read even when no account was found for it.
        $identifierAttempted = null !== $badge ? User::normalizeEmail($badge->getUserIdentifier()) : null;

        // `UserBadge::getUser()` is different: unlike getUserIdentifier(),
        // it invokes the user loader if the badge was never resolved, and
        // throws a fresh UserNotFoundException doing so -- exactly the
        // "unknown account" case this branch must not re-trigger. Only call
        // it once we already know the loader succeeded (every other
        // outcome), never for AuthEventRecord::OUTCOME_UNKNOWN_ACCOUNT.
        $userId = null;
        if (AuthEventRecord::OUTCOME_UNKNOWN_ACCOUNT !== $outcome && null !== $badge) {
            $user = $badge->getUser();
            $userId = $user instanceof User ? $user->getId() : null;
        }

        $this->recorder->record(new AuthEventRecord(
            type: AuthEventType::LOGIN_FAILED,
            outcome: $outcome,
            userId: $userId,
            identifierAttempted: $identifierAttempted,
            ip: $event->getRequest()->getClientIp(),
            userAgent: $this->userAgent($event->getRequest()),
        ));
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        $this->recorder->record(new AuthEventRecord(
            type: AuthEventType::LOGGED_OUT,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user instanceof User ? $user->getId() : null,
            ip: $event->getRequest()->getClientIp(),
            userAgent: $this->userAgent($event->getRequest()),
        ));
    }

    private function outcomeFor(AuthenticationException $exception): string
    {
        if ($exception instanceof AccountDeactivatedException) {
            return AuthEventRecord::OUTCOME_ACCOUNT_DEACTIVATED;
        }

        if ($exception instanceof EmailNotVerifiedException) {
            return AuthEventRecord::OUTCOME_EMAIL_NOT_VERIFIED;
        }

        // With the default `expose_security_errors: None`, AuthenticatorManager
        // wraps UserNotFoundException in a BadCredentialsException to keep the
        // message from leaking (see LoginTimingPaddingSubscriber, which faces
        // the identical wrapping and checks it the same way); under
        // `expose_security_errors: All` it is not wrapped and arrives directly.
        if ($exception instanceof UserNotFoundException || $exception->getPrevious() instanceof UserNotFoundException) {
            return AuthEventRecord::OUTCOME_UNKNOWN_ACCOUNT;
        }

        return AuthEventRecord::OUTCOME_BAD_CREDENTIALS;
    }

    private function userAgent(Request $request): ?string
    {
        return $request->headers->get('User-Agent');
    }
}
