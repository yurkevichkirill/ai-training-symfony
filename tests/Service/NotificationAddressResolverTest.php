<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ChildAccount;
use App\Enum\UserRole;
use App\Repository\ChildAccountRepository;
use App\Service\ChildAccountResolver;
use App\Service\NotificationAddressResolver;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for `NotificationAddressResolver` (Task 8, D3c): an adult
 * player's own address, a child's mail always routed to the parent
 * regardless of the child's placeholder-vs-real email state. Backed by a
 * stubbed `ChildAccountRepository` rather than a database -- the resolver's
 * only collaborator is `ChildAccountResolver`, which is itself a thin
 * wrapper over one repository call (Task 9).
 */
final class NotificationAddressResolverTest extends TestCase
{
    public function testForPlayerReturnsTheAdultPlayersOwnAddress(): void
    {
        $player = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('adult-player'));

        $resolver = $this->resolverFindingChildAccount(null);

        self::assertSame($player->getEmail(), $resolver->forPlayer($player));
    }

    /**
     * The common case: a freshly-created child whose own address is still
     * the `.invalid` placeholder from `ChildEmailFactory` (Task 7) --
     * undeliverable, so the parent's address is the only one that can ever
     * receive this mail.
     */
    public function testForPlayerReturnsTheParentsAddressForAChildWithAPlaceholderEmail(): void
    {
        $parent = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-of-placeholder-child'));
        $child = UserFactory::activeVerified(UserRole::PLAYER, 'child_00000000-0000-7000-8000-000000000001@children.invalid');
        $childAccount = new ChildAccount($child, $parent);

        $resolver = $this->resolverFindingChildAccount($childAccount);

        self::assertSame($parent->getEmail(), $resolver->forPlayer($child));
    }

    /**
     * D3c is unconditional: even once the parent has enabled sign-in and the
     * child has a real, deliverable address of its own, mail about the child
     * still routes to the parent (BR-011 -- the parent owns the family's
     * contact information). `enableSignIn()` is stubbed here rather than
     * exercised through `ChildAccountService`, which is a later batch.
     */
    public function testForPlayerReturnsTheParentsAddressEvenAfterSignInIsEnabled(): void
    {
        $parent = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-of-signed-in-child'));
        $child = UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('signed-in-child'));
        $childAccount = new ChildAccount($child, $parent);
        $childAccount->enableSignIn(new \DateTimeImmutable());

        self::assertTrue($childAccount->isSignInEnabled(), 'Precondition failed: sign-in was not actually enabled.');

        $resolver = $this->resolverFindingChildAccount($childAccount);

        self::assertSame($parent->getEmail(), $resolver->forPlayer($child));
    }

    private function resolverFindingChildAccount(?ChildAccount $childAccount): NotificationAddressResolver
    {
        $repository = $this->createStub(ChildAccountRepository::class);
        $repository->method('findOneByChildUser')->willReturn($childAccount);

        return new NotificationAddressResolver(new ChildAccountResolver($repository));
    }
}
