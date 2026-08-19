<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * AC-18: an unauthenticated request to any non-public route is refused.
 *
 * This walks the *actual* router rather than a hand-written list of paths, so
 * a controller added by a future task is covered the moment its route is
 * registered -- per the architecture's note that this test, not
 * `access_control` by itself, is what holds AC-18 over time. A route added
 * without a matching `access_control` entry still fails closed (the `^/`
 * catch-all requires ROLE_USER), so the only way this test goes red is a
 * route that is neither on the public allow-list nor actually protected --
 * which is exactly the gap it exists to catch.
 */
final class RouterSweepTest extends WebTestCase
{
    /**
     * Generic placeholder values tried in order for a `{parameter}` route
     * segment. Most placeholders (tokens, slugs, ids) accept a plain string;
     * a route with a numeric `requirement` (e.g. `\d+`) needs a numeric one
     * instead, so '1' is tried when the string candidate does not satisfy
     * the route's own requirement regex. This app has no parameterized
     * routes yet (Task 21 lands ahead of Tasks 26-31's `{token}` routes), so
     * this path is exercised by future routes, not today's -- the strategy
     * is deliberately generic rather than special-cased per route name.
     */
    private const PLACEHOLDER_CANDIDATES = ['placeholder-value', '1'];

    public function testEveryRegisteredRouteIsPublicOrRefusesAnonymousAccess(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get(RouterInterface::class);

        [$publicPatterns, $devFirewallPattern] = $this->securityPatternsFromConfig();

        $checkedNonPublicRoutes = [];
        $skippedPublicRoutes = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if ($this->isFrameworkInternal($name, $route, $devFirewallPattern)) {
                continue;
            }

            $path = $this->concretePath($route);

            if ($this->matchesAnyPattern($path, $publicPatterns)) {
                // On the Task 12 allow-list by design -- publicly reachable
                // is the intended, audited behavior here, not a gap for this
                // test to flag. (AC-18 only requires *non*-public routes to
                // refuse anonymous access.)
                $skippedPublicRoutes[] = $name;

                continue;
            }

            $method = $this->anonymousRequestMethod($route);

            $client->request($method, $path);
            $status = $client->getResponse()->getStatusCode();

            self::assertContains(
                $status,
                [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN],
                \sprintf(
                    "Route '%s' (%s %s) is not on the public access_control allow-list, so an anonymous request must be refused with 302 (redirect to login) or 403 -- got %d. Either the route is missing a public access_control entry it needs, or default-deny has a real gap.",
                    $name,
                    $method,
                    $path,
                    $status,
                ),
            );

            $checkedNonPublicRoutes[] = $name;
        }

        // A sweep that silently walked zero non-public routes would pass for
        // the wrong reason (nothing was actually checked) -- prove the loop
        // did real work, on both sides of the allow-list.
        self::assertNotEmpty(
            $checkedNonPublicRoutes,
            'Router sweep found no non-public routes to check -- investigate before trusting a green run.',
        );
        self::assertNotEmpty(
            $skippedPublicRoutes,
            'Router sweep found no public routes -- the allow-list parsing likely broke silently.',
        );
    }

    private function isFrameworkInternal(string $name, Route $route, string $devFirewallPattern): bool
    {
        // Symfony/bundle-internal route names are conventionally prefixed
        // with an underscore (_profiler, _wdt, _preview_error, ...). This
        // project has no web_profiler recipe installed, so none of these
        // are registered today, but the check stays in place because the
        // task's own instruction names them explicitly and a future `composer
        // require --dev symfony/web-profiler-bundle` must not make this test
        // start asserting against tooling routes.
        if (str_starts_with($name, '_')) {
            return true;
        }

        // Belt-and-braces: also exclude anything whose path falls under the
        // `dev` firewall's own pattern (`security: false` in security.yaml),
        // in case a future route is registered there under a name that does
        // not start with an underscore.
        return '' !== $devFirewallPattern && 1 === preg_match('{'.$devFirewallPattern.'}', $route->getPath());
    }

    /**
     * Builds a concrete, requestable path from a route's pattern by
     * substituting each `{parameter}` with the first placeholder candidate
     * that satisfies the route's own `requirement` regex for that
     * parameter, if one is declared. This is the "sensible strategy" the
     * task calls for: no parameterized route is hand-listed, and a route
     * with a numeric-only requirement still gets a value that can match it.
     */
    private function concretePath(Route $route): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $matches) use ($route): string {
                $requirement = $route->getRequirement($matches[1]);

                if (null === $requirement) {
                    return self::PLACEHOLDER_CANDIDATES[0];
                }

                foreach (self::PLACEHOLDER_CANDIDATES as $candidate) {
                    if (1 === preg_match('{^'.$requirement.'$}', $candidate)) {
                        return $candidate;
                    }
                }

                // None of the generic candidates satisfy this parameter's
                // requirement -- fall back to the last candidate rather than
                // silently emitting a value guaranteed not to route.
                return self::PLACEHOLDER_CANDIDATES[array_key_last(self::PLACEHOLDER_CANDIDATES)];
            },
            $route->getPath(),
        );
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAnyPattern(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match('{'.$pattern.'}', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Picks the method an anonymous sweep request should use: GET when the
     * route allows it (true for every current non-public route), otherwise
     * the route's own first declared method (e.g. a future POST-only,
     * non-public route). An empty method list means "any method", for which
     * GET is the sensible default.
     */
    private function anonymousRequestMethod(Route $route): string
    {
        $methods = $route->getMethods();

        if ([] === $methods || \in_array('GET', $methods, true)) {
            return 'GET';
        }

        return $methods[0];
    }

    /**
     * Reads the public allow-list and the `dev` firewall pattern straight
     * out of `config/packages/security.yaml`, rather than hand-copying
     * Task 12's rules into this test -- so this test reconciles against
     * whatever that file actually says, and stays correct if a later task
     * edits the allow-list.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function securityPatternsFromConfig(): array
    {
        $configPath = \dirname(__DIR__, 2).'/config/packages/security.yaml';
        $config = Yaml::parseFile($configPath);

        $publicPatterns = [];

        foreach ($config['security']['access_control'] ?? [] as $rule) {
            $roles = (array) ($rule['roles'] ?? []);

            if (\in_array('PUBLIC_ACCESS', $roles, true)) {
                $publicPatterns[] = (string) $rule['path'];
            }
        }

        $devFirewallPattern = (string) ($config['security']['firewalls']['dev']['pattern'] ?? '');

        return [$publicPatterns, $devFirewallPattern];
    }
}
