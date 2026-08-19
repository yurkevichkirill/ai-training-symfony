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
     * On success, `$session->invalidate()` both discards any pre-existing
     * session for whoever is currently viewing the link and satisfies AC-8's
     * regeneration-on-password-change requirement, per the architecture's
     * explicit reasoning -- the reset is always applied to the token's
     * subject, never to whoever happened to be signed in on this browser.
     */
    #[Route('/reset-password/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token, PasswordResetService $passwordResetService): Response
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
