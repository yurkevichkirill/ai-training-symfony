<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Security\ImpersonationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * S6 (AC-7, D6b): `impersonatorUserId()` returns `null` for a plain token
 * (including no token at all) and the original user's id for a
 * `SwitchUserToken`.
 */
final class ImpersonationContextTest extends TestCase
{
    public function testReturnsNullWhenThereIsNoToken(): void
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $context = new ImpersonationContext($tokenStorage);

        self::assertNull($context->impersonatorUserId());
    }

    public function testReturnsNullForAPlainToken(): void
    {
        $user = new User('trainer@example.test', 'hash', UserRole::TRAINER);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $context = new ImpersonationContext($tokenStorage);

        self::assertNull($context->impersonatorUserId());
    }

    public function testReturnsTheOriginalUsersIdForASwitchUserToken(): void
    {
        $admin = new User('admin@example.test', 'hash', UserRole::SUPER_ADMIN);
        $target = new User('target@example.test', 'hash', UserRole::TRAINER);

        $originalToken = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $switchUserToken = new SwitchUserToken($target, 'main', $target->getRoles(), $originalToken, '/');

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($switchUserToken);

        $context = new ImpersonationContext($tokenStorage);

        self::assertTrue($admin->getId()->equals($context->impersonatorUserId()));
    }

    public function testReturnsNullWhenTheOriginalTokensUserIsNotAnAppUser(): void
    {
        $target = new User('target@example.test', 'hash', UserRole::TRAINER);

        $originalToken = $this->createStub(TokenInterface::class);
        $originalToken->method('getUser')->willReturn(null);

        $switchUserToken = new SwitchUserToken($target, 'main', $target->getRoles(), $originalToken, '/');

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($switchUserToken);

        $context = new ImpersonationContext($tokenStorage);

        self::assertNull($context->impersonatorUserId());
    }
}
