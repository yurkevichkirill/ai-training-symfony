<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\LoginTimingPaddingSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginTimingPaddingSubscriberTest extends TestCase
{
    /**
     * The shape AuthenticatorManager actually produces under the default
     * expose_security_errors: None -- UserNotFoundException wrapped in a
     * BadCredentialsException
     * (vendor/symfony/security-http/Authentication/AuthenticatorManager.php:252).
     */
    public function testItPaysTheHashingCostForAWrappedUnknownAccount(): void
    {
        $hasher = $this->hasherExpecting(self::once());

        $this->subscriberFor($hasher)->onLoginFailure($this->failureEvent(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
        ));
    }

    /**
     * Under expose_security_errors: All the exception is not wrapped. Accepting
     * both shapes means this protection does not silently disappear if that
     * setting is ever changed.
     */
    public function testItPaysTheHashingCostForAnUnwrappedUnknownAccount(): void
    {
        $hasher = $this->hasherExpecting(self::once());

        $this->subscriberFor($hasher)->onLoginFailure($this->failureEvent(new UserNotFoundException()));
    }

    /**
     * A real account with a wrong password already paid for one verification in
     * CheckCredentialsListener. Padding it again would make the *known*-account
     * path the slow one and reintroduce the very signal this removes.
     */
    public function testItDoesNotPayAgainForAKnownAccount(): void
    {
        $hasher = $this->hasherExpecting(self::never());

        $this->subscriberFor($hasher)->onLoginFailure($this->failureEvent(
            new BadCredentialsException('Bad credentials.'),
        ));
    }

    public function testItSubscribesToLoginFailure(): void
    {
        self::assertArrayHasKey(LoginFailureEvent::class, LoginTimingPaddingSubscriber::getSubscribedEvents());
    }

    /**
     * The dummy hash must be built once, at construction, not per failure --
     * hashing on every failed login would make the padded path far slower than
     * the real one, which is just the old signal with the sign flipped.
     */
    public function testTheDummyHashIsComputedOnceAtConstruction(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->willReturn('$argon2id$dummy');
        $hasher->method('verify')->willReturn(false);

        $subscriber = $this->subscriberFor($hasher);
        $subscriber->onLoginFailure($this->failureEvent(new UserNotFoundException()));
        $subscriber->onLoginFailure($this->failureEvent(new UserNotFoundException()));
    }

    private function hasherExpecting(object $expectation): PasswordHasherInterface&MockObject
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturn('$argon2id$dummy');
        $hasher->expects($expectation)->method('verify')->willReturn(false);

        return $hasher;
    }

    private function subscriberFor(PasswordHasherInterface $hasher): LoginTimingPaddingSubscriber
    {
        $factory = $this->createStub(PasswordHasherFactoryInterface::class);
        $factory->method('getPasswordHasher')->willReturn($hasher);

        return new LoginTimingPaddingSubscriber($factory);
    }

    private function failureEvent(AuthenticationException $exception): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            $this->createStub(AuthenticatorInterface::class),
            new Request(),
            null,
            'main',
        );
    }
}
