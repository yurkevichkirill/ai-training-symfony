<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ResendVerificationFormType;
use App\Service\EmailVerificationService;
use App\Service\Exception\InvalidVerificationTokenException;
use App\Service\Exception\VerificationTokenAlreadyConsumedException;
use App\Service\Exception\VerificationTokenExpiredException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public surface for email verification (AC-13, AC-14, AC-20): both
 * routes are on the `^/verify-email` `PUBLIC_ACCESS` allow-list in
 * `config/packages/security.yaml` (Task 12), because an unverified visitor is
 * -- by definition -- not signed in yet and cannot reach either action from
 * behind the firewall.
 *
 * Both actions only translate `EmailVerificationService`'s outcomes to HTTP:
 * no rate-limiting, token, or non-enumeration decision is made here.
 */
final class EmailVerificationController extends AbstractController
{
    /**
     * `{token}`'s requirement excludes short strings like "resend" so this
     * route can never shadow `app_verify_email_resend` -- see that route's
     * `priority`, which is the primary guard; this requirement is
     * belt-and-braces and also rejects an obviously-malformed token with a
     * plain 404 before it ever reaches the service.
     *
     * Every rejection `EmailVerificationService::consume()` can raise is
     * caught here and mapped to its own template state -- none of the three
     * typed exceptions, nor the idempotent-already-verified success case, may
     * escape as an uncaught 500 (this is what Task 21's RouterSweepTest and
     * this task's own verify commands are guarding).
     */
    #[Route(
        '/verify-email/{token}',
        name: 'app_verify_email',
        requirements: ['token' => '[A-Za-z0-9_-]{20,}'],
        methods: ['GET'],
    )]
    public function verify(string $token, EmailVerificationService $emailVerificationService): Response
    {
        try {
            $emailVerificationService->consume($token);

            $state = 'success';
        } catch (InvalidVerificationTokenException) {
            $state = 'invalid';
        } catch (VerificationTokenAlreadyConsumedException) {
            $state = 'already_consumed';
        } catch (VerificationTokenExpiredException) {
            $state = 'expired';
        }

        return $this->render('verify_email/result.html.twig', [
            'state' => $state,
        ]);
    }

    /**
     * `priority: 1` makes this route win over `app_verify_email`'s
     * `/verify-email/{token}` for the literal path `/verify-email/resend`
     * regardless of declaration order -- both routes share the
     * `/verify-email` prefix, and without a requirement on `{token}` the
     * string "resend" would otherwise satisfy it.
     *
     * `EmailVerificationService::resend()` never reveals whether the address
     * was found, already verified, or rate-limited (AC-11-shaped
     * non-enumeration, by analogy) -- so a valid submission always renders
     * the same confirmation, mirroring the reset-password flow's
     * `check-email` page. There is deliberately no separate "check email"
     * template: the same `resend.html.twig` shows either the form or the
     * confirmation, per this task's template list.
     */
    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['GET', 'POST'], priority: 1)]
    public function resend(Request $request, EmailVerificationService $emailVerificationService): Response
    {
        $form = $this->createForm(ResendVerificationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            $emailVerificationService->resend($email);

            return $this->render('verify_email/resend.html.twig', [
                'form' => null,
                'submitted' => true,
            ]);
        }

        return $this->render('verify_email/resend.html.twig', [
            'form' => $form,
            'submitted' => false,
        ]);
    }
}
