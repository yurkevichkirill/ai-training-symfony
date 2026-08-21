<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ChildEmailFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Unit coverage for `ChildEmailFactory` (Task 7, D1c): collision-freedom
 * across distinct ids, the lowercase invariant `User::normalizeEmail()`/S1's
 * `CHECK (email = lower(email))` depend on, and both branches of
 * `isPlaceholder()`.
 */
final class ChildEmailFactoryTest extends TestCase
{
    private ChildEmailFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ChildEmailFactory();
    }

    public function testForChildProducesTheExpectedShape(): void
    {
        $id = new UuidV7();

        $email = $this->factory->forChild($id);

        self::assertSame(\sprintf('child_%s@children.invalid', $id->toRfc4122()), $email);
    }

    public function testForChildProducesAnAlreadyLowercaseEmail(): void
    {
        $id = new UuidV7();

        $email = $this->factory->forChild($id);

        self::assertSame(mb_strtolower($email, 'UTF-8'), $email);
    }

    /**
     * Derived from each account's own immutable id, so two distinct child
     * ids can never produce the same placeholder address.
     */
    public function testForChildProducesDistinctAddressesForDistinctIds(): void
    {
        $addresses = [];
        for ($i = 0; $i < 200; ++$i) {
            $addresses[] = $this->factory->forChild(new UuidV7());
        }

        self::assertCount(200, array_unique($addresses));
    }

    public function testIsPlaceholderIsTrueForAGeneratedAddress(): void
    {
        $email = $this->factory->forChild(new UuidV7());

        self::assertTrue($this->factory->isPlaceholder($email));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realAddressProvider(): iterable
    {
        yield 'ordinary address' => ['parent@example.com'];
        yield 'right local part, wrong domain' => [\sprintf('child_%s@example.com', new UuidV7())];
        yield 'right domain, no uuid' => ['child_not-a-uuid@children.invalid'];
        yield 'right domain, wrong prefix' => [\sprintf('kid_%s@children.invalid', new UuidV7())];
        yield 'empty string' => [''];
    }

    #[DataProvider('realAddressProvider')]
    public function testIsPlaceholderIsFalseForAnythingElse(string $email): void
    {
        self::assertFalse($this->factory->isPlaceholder($email));
    }
}
