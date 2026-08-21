<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChildAccount;
use App\Enum\UserRole;
use App\Repository\ChildAccountRepository;
use App\Service\ChildAccountResolver;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `ChildAccountResolver` (Task 9): both methods reduce to
 * the single `ChildAccountRepository::findOneByChildUser()` call, backed by
 * `UNIQUE (child_user_id)`.
 */
final class ChildAccountResolverTest extends TestCase
{
    public function testChildAccountOfReturnsNullWhenNoRowMatches(): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('non-child'));
        $repository = $this->createStub(ChildAccountRepository::class);
        $repository->method('findOneByChildUser')->willReturn(null);

        $resolver = new ChildAccountResolver($repository);

        self::assertNull($resolver->childAccountOf($user));
    }

    public function testChildAccountOfReturnsTheMatchingRow(): void
    {
        $parent = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent'));
        $child = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child'));
        $childAccount = new ChildAccount($child, $parent);

        $repository = $this->createStub(ChildAccountRepository::class);
        $repository->method('findOneByChildUser')->willReturn($childAccount);

        $resolver = new ChildAccountResolver($repository);

        self::assertSame($childAccount, $resolver->childAccountOf($child));
    }

    public function testIsChildIsFalseWhenNoRowMatches(): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('non-child-2'));
        $repository = $this->createStub(ChildAccountRepository::class);
        $repository->method('findOneByChildUser')->willReturn(null);

        $resolver = new ChildAccountResolver($repository);

        self::assertFalse($resolver->isChild($user));
    }

    public function testIsChildIsTrueWhenARowMatches(): void
    {
        $parent = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-2'));
        $child = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-2'));
        $childAccount = new ChildAccount($child, $parent);

        $repository = $this->createStub(ChildAccountRepository::class);
        $repository->method('findOneByChildUser')->willReturn($childAccount);

        $resolver = new ChildAccountResolver($repository);

        self::assertTrue($resolver->isChild($child));
    }
}
