<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\CoachRegistrationFormType;
use App\Security\IpTruncator;
use App\Security\ShareLinkVoter;
use App\Service\CoachInvitationService;
use App\Service\CoachRegistrationRequest;
use App\Service\CoachRegistrationService;
use App\Service\Exception\CoachAlreadyActiveElsewhereException;
use App\Service\Exception\CoachInvitationAlreadyAcceptedException;
use App\Service\Exception\CoachInvitationEmailMismatchException;
use App\Service\Exception\CoachInvitationExpiredException;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\InvalidCoachInvitationException;
use App\Service\Exception\ShareLinkUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public `/coach-invitation/{token}` landing page for a trainer's coach
 * invitation (AC-3, AC-14, AC-15, AC-18, AC-21). `^/coach-invitation` is on
 * the `PUBLIC_ACCESS` allow-list in `config/packages/security.yaml`, ahead
 * of the `^/` catch-all.
 *
 * `resolve()`'s typed exceptions are what let this action distinguish
 * AC-18's "already used" from "expired" -- each renders its own
 * `$exception->getMessage()` (already the user-facing text, same convention
 * `ResetPasswordController`/`AccountInvitationController` use) rather than a
 * single generic refusal. `denyAccessUnlessGranted()` with
 * `ShareLinkVoter::ACCEPT_COACH_INVITATION` runs ahead of the signed-in
 * accept call -- the service's own guards (Tasks 13, 14) remain regardless.
 */
final class CoachInvitationController extends AbstractController
{
    #[Route('/coach-invitation/{token}', name: 'app_coach_invitation', methods: ['GET', 'POST'])]
    public function accept(
        Request $request,
        string $token,
        CoachInvitationService $invitationService,
        CoachRegistrationService $registrationService,
        RateLimiterFactory $shareLinkRegistrationSourceLimiter,
    ): Response {
        $user = $this->getUser();

        try {
            $invitation = $invitationService->resolve($token);
        } catch (CoachInvitationAlreadyAcceptedException $exception) {
            // A signed-in visitor re-following their own already-accepted
            // link must still succeed idempotently (the "coach re-follows
            // their own accepted link" edge case) -- resolve() alone cannot
            // tell that apart from "already used by someone else", since it
            // has no signed-in identity to check against. accept()
            // re-derives the invitation from the token itself and settles
            // it: idempotent success for the coach it belongs to, the same
            // refusal otherwise. There is no resolved `CoachInvitation` to
            // vote on here, so accept()'s own AC-21 guard is the sole
            // authority for this one branch, in place of the voter.
            if ($user instanceof User) {
                try {
                    $invitationService->accept($token, $user);
                } catch (InvalidCoachInvitationException|ShareLinkUnavailableException|CoachInvitationExpiredException|CoachInvitationEmailMismatchException|CoachInvitationAlreadyAcceptedException|CoachAlreadyActiveElsewhereException $innerException) {
                    return $this->render('coach_invitation/refused.html.twig', ['reason' => $innerException->getMessage()], new Response(null, Response::HTTP_FORBIDDEN));
                }

                $this->addFlash('success', "You're now connected with this trainer.");

                return $this->redirectToRoute('app_home');
            }

            return $this->render('coach_invitation/refused.html.twig', ['reason' => $exception->getMessage()], new Response(null, Response::HTTP_NOT_FOUND));
        } catch (InvalidCoachInvitationException|ShareLinkUnavailableException|CoachInvitationExpiredException $exception) {
            return $this->render('coach_invitation/refused.html.twig', ['reason' => $exception->getMessage()], new Response(null, Response::HTTP_NOT_FOUND));
        }

        if ($user instanceof User) {
            $this->denyAccessUnlessGranted(ShareLinkVoter::ACCEPT_COACH_INVITATION, $invitation);

            try {
                $invitationService->accept($token, $user);
            } catch (InvalidCoachInvitationException|ShareLinkUnavailableException|CoachInvitationExpiredException|CoachInvitationEmailMismatchException|CoachInvitationAlreadyAcceptedException|CoachAlreadyActiveElsewhereException $exception) {
                return $this->render('coach_invitation/refused.html.twig', ['reason' => $exception->getMessage()], new Response(null, Response::HTTP_FORBIDDEN));
            }

            $this->addFlash('success', "You're now connected with this trainer.");

            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(CoachRegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $limit = $shareLinkRegistrationSourceLimiter
                ->create(IpTruncator::truncate($request->getClientIp() ?? ''))
                ->consume();

            if (!$limit->isAccepted()) {
                return new Response(
                    'Too many registration attempts. Please try again later.',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(0, $limit->getRetryAfter()->getTimestamp() - time())],
                );
            }

            if ($form->isValid()) {
                /** @var array{firstName: ?string, lastName: ?string, plainPassword: string, phone: ?string} $data */
                $data = $form->getData();

                $registrationRequest = new CoachRegistrationRequest(
                    plainPassword: $data['plainPassword'],
                    firstName: $data['firstName'] ?? null,
                    lastName: $data['lastName'] ?? null,
                    phone: $data['phone'] ?? null,
                );

                try {
                    $registrationService->registerAndAccept($registrationRequest, $invitation);
                } catch (EmailAlreadyInUseException) {
                    return $this->render('coach_invitation/refused.html.twig', [
                        'reason' => 'You already have an account with this email address. Sign in, then open this link again.',
                    ], new Response(null, Response::HTTP_CONFLICT));
                } catch (InvalidCoachInvitationException|CoachInvitationExpiredException|CoachInvitationAlreadyAcceptedException|CoachAlreadyActiveElsewhereException $exception) {
                    return $this->render('coach_invitation/refused.html.twig', ['reason' => $exception->getMessage()], new Response(null, Response::HTTP_FORBIDDEN));
                }

                return $this->render('coach_invitation/register_check_email.html.twig');
            }
        }

        return $this->render('coach_invitation/register.html.twig', ['form' => $form, 'invitation' => $invitation]);
    }
}
