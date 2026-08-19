<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\IpTruncator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Standalone coverage for the one part of LoginRateLimiter's `login_source`
 * key that is easy to get wrong: the /24 (IPv4) and /64 (IPv6) bitmask. Each
 * vector below is a concrete address/result pair, not just "looks right".
 */
final class IpTruncationTest extends TestCase
{
    #[DataProvider('ipv4Vectors')]
    public function testIpv4IsTruncatedToSlash24(string $input, string $expected): void
    {
        self::assertSame($expected, IpTruncator::truncate($input));
    }

    #[DataProvider('ipv6Vectors')]
    public function testIpv6IsTruncatedToSlash64(string $input, string $expected): void
    {
        self::assertSame($expected, IpTruncator::truncate($input));
    }

    public function testTwoIpv4AddressesInTheSameSlash24TruncateToTheSameKey(): void
    {
        self::assertSame(
            IpTruncator::truncate('198.51.100.1'),
            IpTruncator::truncate('198.51.100.254'),
        );
    }

    public function testTwoIpv4AddressesInDifferentSlash24sTruncateDifferently(): void
    {
        self::assertNotSame(
            IpTruncator::truncate('198.51.100.1'),
            IpTruncator::truncate('198.51.101.1'),
        );
    }

    public function testTwoIpv6AddressesInTheSameSlash64TruncateToTheSameKey(): void
    {
        self::assertSame(
            IpTruncator::truncate('2001:db8:85a3:8d3:1319:8a2e:370:7348'),
            IpTruncator::truncate('2001:db8:85a3:8d3:ffff:ffff:ffff:ffff'),
        );
    }

    public function testTwoIpv6AddressesInDifferentSlash64sTruncateDifferently(): void
    {
        self::assertNotSame(
            IpTruncator::truncate('2001:db8:85a3:8d3:1319:8a2e:370:7348'),
            IpTruncator::truncate('2001:db8:85a3:8d4:1319:8a2e:370:7348'),
        );
    }

    /**
     * An address `inet_pton()` cannot parse must not crash the login
     * endpoint -- it degrades to keying on the raw value unchanged.
     */
    public function testUnparsableInputIsReturnedUnchanged(): void
    {
        self::assertSame('not-an-ip', IpTruncator::truncate('not-an-ip'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv4Vectors(): iterable
    {
        yield 'ordinary address' => ['203.0.113.55', '203.0.113.0'];
        yield 'last octet already zero' => ['10.20.30.0', '10.20.30.0'];
        yield 'last octet at the top of the range' => ['198.51.100.255', '198.51.100.0'];
        yield 'private/dynamic docker-style address' => ['172.22.0.42', '172.22.0.0'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv6Vectors(): iterable
    {
        yield 'ordinary address, non-zero interface identifier' => [
            '2001:db8:85a3:8d3:1319:8a2e:370:7348',
            '2001:db8:85a3:8d3::',
        ];
        yield 'loopback' => ['::1', '::'];
        yield 'all-zero network, non-zero identifier' => ['::abcd', '::'];
        yield 'network already at a /64 boundary' => ['2001:db8::', '2001:db8::'];
    }
}
