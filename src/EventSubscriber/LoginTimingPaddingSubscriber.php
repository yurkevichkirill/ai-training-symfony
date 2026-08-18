<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Removes the timing signal that distinguishes an unknown email from a known
 * one (AC-2).
 *
 * A sign-in against an existing account always pays for one password
 * verification, done by CheckCredentialsListener. A sign-in against an address
 * that does not exist never gets that far -- the provider fails first, so the
 * request returns measurably sooner. Identical wording in the response does not
 * help if the clock still answers the question, so this subscriber spends the
 * same verification cost on the unknown-account path and throws the result
 * away.
 *
 * The dummy hash is produced once at construction with the *configured*
 * hasher, so it automatically tracks the real algorithm and cost -- including
 * the reduced test-environment cost, which keeps the suite fast without making
 * the padding a no-op.
 */
final class LoginTimingPaddingSubscriber implements EventSubscriberInterface
{
    /**
     * Not a credential: a hash of a random string nobody knows, existing only
     * to be verified against and discarded.
     */
    private readonly string $dummyHash;

    public function __construct(private readonly PasswordHasherFactoryInterface $hasherFactory)
    {
        $this->dummyHash = $this->hasher()->hash(bin2hex(random_bytes(16)));
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => ['onLoginFailure', 0]];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->wasUnknownAccount($event->getException())) {
            return;
        }

        // Result deliberately discarded -- this call exists for its duration.
        $this->hasher()->verify($this->dummyHash, 'not-the-password');
    }

    /**
     * With the default `expose_security_errors: None`, AuthenticatorManager
     * wraps UserNotFoundException in a BadCredentialsException to keep the
     * message from leaking, so the real cause is the *previous* exception. Under
     * `expose_security_errors: All` it is not wrapped and arrives at the top
     * level. Both shapes are accepted so this keeps working if that setting is
     * ever changed.
     */
    private function wasUnknownAccount(AuthenticationException $exception): bool
    {
        return $exception instanceof UserNotFoundException
            || $exception->getPrevious() instanceof UserNotFoundException;
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hasherFactory->getPasswordHasher(PasswordAuthenticatedUserInterface::class);
    }
}
