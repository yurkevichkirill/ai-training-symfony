<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Service\Exception\SourceRateLimitExceededException;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;

/**
 * The public surface for password reset (AC-9, AC-10, AC-11, AC-12): both
 * routes are on the `^/reset-password` `PUBLIC_ACCESS` allow-list in
 * `config/packages/security.yaml` (Task 12), which already covers both paths
 * below it -- confirmed via `debug:router`, no security.yaml change needed
 * for this task.
 *
 * Both actions only translate `PasswordResetService`'s outcomes to HTTP: no
 * rate-limiting, token, or non-enumeration decision is made here.
 */
final class ResetPasswordController extends AbstractController
{
    /**
     * Mirrors `EmailVerificationController::resend()`'s non-enumeration
     * shape exactly: a valid submission always renders the same `check-email`
     * confirmation whether or not the address is registered (AC-11) -- the
     * only branch is the *source* rate limiter, which is independent of any
     * one account and therefore safe to surface as a distinct 429 (AC-19-
     * shaped) instead of the generic page.
     */
    #[Route('/reset-password', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, PasswordResetService $passwordResetService): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            try {
                $passwordResetService->request($email);
            } catch (SourceRateLimitExceededException $exception) {
                return new Response(
                    'Too many password reset requests. Please try again later.',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(0, $exception->getRetryAfter()->getTimestamp() - time())],
                );
            }

            return $this->render('reset_password/check_email.html.twig');
        }

        return $this->render('reset_password/request.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * `{token}`'s requirement excludes the literal path segment this route's
     * sibling starts with (`reset`), and the two routes diverge before any
     * shared prefix that could create the `{token}`-vs-static ambiguity
     * Task 27 hit for `/verify-email` -- `/reset-password` and
     * `/reset-password/reset/{token}` are never candidates for the same
     * concrete path, confirmed via `debug:router`.
     *
     * Every rejection `PasswordResetService::complete()` can raise
     * (`ResetPasswordExceptionInterface`'s invalid/expired token cases) is
     * caught here and mapped to a `refused` template state -- neither may
     * escape as an uncaught 500.
     *
     * On success, the current session is discarded -- `$tokenStorage->setToken(null)`
     * *and* `$session->invalidate()`, see the inline comment for why either
     * one alone is not enough -- which both ends any pre-existing session
     * for whoever is currently viewing the link and satisfies AC-8's
     * regeneration-on-password-change requirement, per the architecture's
     * explicit reasoning. The reset itself is always applied to the token's
     * subject, never to whoever happened to be signed in on this browser.
     */
    #[Route('/reset-password/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token, PasswordResetService $passwordResetService, TokenStorageInterface $tokenStorage): Response
    {
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            try {
                $passwordResetService->complete($token, $plainPassword);
            } catch (ResetPasswordExceptionInterface $exception) {
                return $this->render('reset_password/reset.html.twig', [
                    'form' => null,
                    'state' => 'refused',
                    'reason' => $exception->getReason(),
                ]);
            }

            // Both halves are required, and the order does not matter.
            //
            // `invalidate()` destroys the session data and regenerates the
            // id. On its own that is NOT enough to end the session: if
            // anything on this request has already caused the firewall to
            // restore the token into token storage, `ContextListener::onKernelResponse()`
            // serializes that still-present token straight back into the
            // freshly regenerated session, and the browser walks away
            // authenticated with a brand-new cookie. Whether that happens
            // used to depend on nothing here at all -- `^/reset-password`
            // is PUBLIC_ACCESS and the firewall is `lazy`, so on this route
            // the token was historically never restored, and `invalidate()`
            // alone happened to be sufficient. Any listener or service that
            // reads the token on an ordinary request (S6's
            // `ImpersonationExpirySubscriber` does, at `kernel.request`
            // priority 7) silently removes that accident and re-authenticates
            // the very session this line is trying to discard.
            //
            // Clearing token storage makes AC-8/AC-12 hold on this path by
            // construction instead of by side effect: with no token to
            // persist, `ContextListener` removes the session key rather
            // than rewriting it.
            $tokenStorage->setToken(null);
            $request->getSession()->invalidate();

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'form' => $form,
            'state' => 'form',
            'reason' => null,
        ]);
    }
}
