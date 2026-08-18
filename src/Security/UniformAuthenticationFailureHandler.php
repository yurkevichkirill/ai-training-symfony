<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * One message and one response shape for every way a sign-in can fail (AC-2).
 *
 * Wrong password, unknown address, deactivated account and unverified account
 * all produce the same flash text, the same status code and the same redirect
 * target. The exception's concrete type is never consulted -- not narrowed,
 * not mapped, not logged here -- because any branch on it is a channel through
 * which a visitor can learn which accounts exist and what state they are in.
 *
 * That distinction is not lost: AccountStatusChecker throws distinct exception
 * classes and AuthEventSubscriber records which one fired, so operators keep
 * the detail while visitors get none of it.
 *
 * Symfony's default handler stores the exception in the session and the login
 * template renders its message, which would defeat all of this; this handler
 * deliberately does not do that.
 */
final class UniformAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    /**
     * Names neither the address nor the reason. "Invalid email or password"
     * stays true for all four causes, which is what makes it uniform.
     */
    public const FAILURE_MESSAGE = 'Invalid email or password.';

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->getFlashBag()->add('error', self::FAILURE_MESSAGE);

        return new RedirectResponse(
            $this->urlGenerator->generate('app_login'),
            Response::HTTP_SEE_OTHER,
        );
    }
}
