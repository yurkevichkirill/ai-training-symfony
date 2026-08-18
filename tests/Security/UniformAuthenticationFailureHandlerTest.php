<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Exception\AccountDeactivatedException;
use App\Security\Exception\EmailNotVerifiedException;
use App\Security\UniformAuthenticationFailureHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The point of this handler is that the four failure causes are
 * indistinguishable in the response, so the test is written to compare them
 * against each other rather than each against a hardcoded expectation -- a
 * change that made one cause differ would have to be made in four places to
 * escape notice.
 */
final class UniformAuthenticationFailureHandlerTest extends TestCase
{
    #[DataProvider('failureCauses')]
    public function testEveryCauseProducesTheSameResponse(AuthenticationException $exception): void
    {
        [$response, $flashes] = $this->handle($exception);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/login', $response->getTargetUrl());
        self::assertSame(['error' => [UniformAuthenticationFailureHandler::FAILURE_MESSAGE]], $flashes);
    }

    /**
     * Compares the four causes directly: status code, redirect target and flash
     * text must be byte-identical across all of them.
     */
    public function testTheFourCausesAreIndistinguishableFromEachOther(): void
    {
        $signatures = [];

        foreach (self::failureCauses() as $label => [$exception]) {
            [$response, $flashes] = $this->handle($exception);
            $signatures[$label] = [
                'status' => $response->getStatusCode(),
                'target' => $response->getTargetUrl(),
                'flashes' => $flashes,
            ];
        }

        self::assertCount(4, $signatures);
        self::assertCount(
            1,
            array_unique(array_map(serialize(...), $signatures)),
            'The failure causes are distinguishable from each other: '.print_r($signatures, true),
        );
    }

    /**
     * The message must not name the address, the account state, or which half
     * of the credential pair was wrong.
     */
    public function testTheMessageLeaksNothing(): void
    {
        $message = UniformAuthenticationFailureHandler::FAILURE_MESSAGE;

        foreach (['deactivated', 'verif', 'not found', 'unknown', 'no such'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $message);
        }
    }

    /**
     * @return array{RedirectResponse, array<string, list<string>>}
     */
    private function handle(AuthenticationException $exception): array
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/login');

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $handler = new UniformAuthenticationFailureHandler($urlGenerator);
        $response = $handler->onAuthenticationFailure($request, $exception);

        return [$response, $session->getFlashBag()->all()];
    }

    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function failureCauses(): iterable
    {
        yield 'wrong password' => [new BadCredentialsException('Bad credentials.')];
        // Wrapped, which is what AuthenticatorManager actually hands over under
        // the default expose_security_errors: None.
        yield 'unknown email' => [new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException())];
        yield 'deactivated account' => [new AccountDeactivatedException()];
        yield 'unverified account' => [new EmailNotVerifiedException()];
    }
}
