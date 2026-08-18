<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The app is always fronted by the nginx service of docker-compose.yml, so
 * every request arrives from a container address on the project's bridge
 * network. Unless that proxy is trusted, Request::getClientIp() returns the
 * proxy rather than the real client, which would silently turn the per-IP
 * login throttle (AC-19) into a single global bucket, and would make
 * getSchemeAndHttpHost() -- which the stateless CSRF origin check compares
 * against -- resolve to the proxy's scheme and host (AC-7, AC-21).
 */
final class TrustedProxyTest extends WebTestCase
{
    /**
     * A genuinely public address. Note that the documentation ranges normally
     * used in tests (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24) are all
     * members of IpUtils::PRIVATE_SUBNETS, so 'private_ranges' trusts them --
     * they cannot stand in for an untrusted peer here.
     */
    private const CLIENT_IP = '93.184.216.34';

    /** An address on the project's Docker bridge network (172.22.0.0/16). */
    private const PROXY_IP = '172.22.0.9';

    /** Public, and therefore outside every entry of IpUtils::PRIVATE_SUBNETS. */
    private const UNTRUSTED_IP = '8.8.8.8';

    public function testForwardedForFromATrustedProxyResolvesToTheRealClientIp(): void
    {
        $client = static::createClient([], [
            'REMOTE_ADDR' => self::PROXY_IP,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT_IP,
        ]);
        $client->catchExceptions(true);
        $client->request('GET', '/');

        self::assertSame(
            self::CLIENT_IP,
            $client->getRequest()->getClientIp(),
            'A trusted proxy must not mask the real client IP.',
        );
    }

    public function testForwardedProtoFromATrustedProxyResolvesTheRequestAsSecure(): void
    {
        $client = static::createClient([], [
            'REMOTE_ADDR' => self::PROXY_IP,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'training.example.com',
        ]);
        $client->catchExceptions(true);
        $client->request('GET', '/');

        $request = $client->getRequest();

        self::assertTrue($request->isSecure(), 'X-Forwarded-Proto must be honoured behind the proxy.');
        self::assertSame(
            'https://training.example.com',
            $request->getSchemeAndHttpHost(),
            'The origin the stateless CSRF check compares against must be the public one.',
        );
    }

    public function testForwardedForFromAnUntrustedSourceIsIgnored(): void
    {
        $client = static::createClient([], [
            'REMOTE_ADDR' => self::UNTRUSTED_IP,
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);
        $client->catchExceptions(true);
        $client->request('GET', '/');

        self::assertSame(
            self::UNTRUSTED_IP,
            $client->getRequest()->getClientIp(),
            'A spoofed X-Forwarded-For from outside the trusted ranges must be ignored.',
        );
    }
}
