<?php

declare(strict_types=1);

namespace App\Controller\Trainer;

use App\Entity\User;
use App\Form\CoachInvitationFormType;
use App\Repository\CoachInvitationRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Security\IpTruncator;
use App\Service\CoachInvitationRequest;
use App\Service\CoachInvitationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A trainer's coaches: the currently-active roster plus every invitation
 * ever sent, each showing its derived Pending/Accepted/Expired state
 * (AC-17), and the form that sends a new one (AC-5, AC-19). `#[IsGranted]`
 * at the class level is S1's belt-and-braces rule every `Trainer\*Controller`
 * follows.
 */
#[IsGranted('ROLE_TRAINER')]
final class CoachController extends AbstractController
{
    /**
     * AC-17, AC-18's re-invite affordance: the active-coach roster and the
     * full invitation history are two independent reads, rendered
     * together -- an invitation's derived `status()` is what the template
     * uses to offer "invite again" for anything no longer Pending.
     */
    #[Route('/trainer/coaches', name: 'app_trainer_coaches', methods: ['GET'])]
    public function index(
        Request $request,
        TrainerCoachAssociationRepository $associationRepository,
        CoachInvitationRepository $invitationRepository,
    ): Response {
        /** @var User $trainer */
        $trainer = $this->getUser();

        // AC-18's re-invite affordance: a link on a no-longer-Pending
        // invitation carries its email/name as query parameters, which
        // pre-fill (never auto-submit) a fresh invite form -- the trainer
        // still reviews and submits it, through the same CSRF-protected
        // POST as any other invitation.
        $reinviteData = [
            'email' => $request->query->get('reinvite_email'),
            'name' => $request->query->get('reinvite_name'),
        ];

        return $this->render('trainer/coach/index.html.twig', [
            'coaches' => $associationRepository->findActiveFor($trainer),
            'invitations' => $invitationRepository->findForTrainer($trainer),
            'invitationForm' => $this->createForm(CoachInvitationFormType::class, $reinviteData, [
                'action' => $this->generateUrl('app_trainer_coach_invite'),
                'method' => 'POST',
            ]),
            'now' => new \DateTimeImmutable(),
        ]);
    }

    /**
     * AC-5, AC-19: `email` is the only required field -- an empty one is
     * refused by the form's own `NotBlank` constraint, re-rendering the
     * coaches page with the field-level error rather than a separate page.
     *
     * **Task 34 hardening fix.** Both `coach_invitation_*` limiters
     * (config/packages/rate_limiter.yaml) are consumed here, immediately
     * before `CoachInvitationService::invite()` is ever called, once the
     * submitted form is otherwise valid. Per this project's established
     * rule (S1's `password_reset_account`/`password_reset_source` pair):
     * only the *source* limiter surfaces a 429 -- it is independent of any
     * one account, so a burst from one source is safe to refuse outright.
     * The *account* limiter (keyed on the trainer's own user id, not the
     * invited address) instead renders a field-level form error: unlike
     * password reset, the trainer here is already a known, authenticated
     * account, so surfacing "too many invitations sent recently" discloses
     * nothing an attacker could use to enumerate anything, and gives the
     * trainer an actionable message instead of a silent no-op.
     */
    #[Route('/trainer/coaches/invite', name: 'app_trainer_coach_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        CoachInvitationService $invitationService,
        TrainerCoachAssociationRepository $associationRepository,
        CoachInvitationRepository $invitationRepository,
        RateLimiterFactory $coachInvitationAccountLimiter,
        RateLimiterFactory $coachInvitationSourceLimiter,
    ): Response {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $form = $this->createForm(CoachInvitationFormType::class, options: [
            'action' => $this->generateUrl('app_trainer_coach_invite'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sourceLimit = $coachInvitationSourceLimiter
                ->create(IpTruncator::truncate($request->getClientIp() ?? ''))
                ->consume();

            if (!$sourceLimit->isAccepted()) {
                return new Response(
                    'Too many invitations sent from this source. Please try again later.',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) max(0, $sourceLimit->getRetryAfter()->getTimestamp() - time())],
                );
            }

            $accountLimit = $coachInvitationAccountLimiter->create((string) $trainer->getId())->consume();

            if (!$accountLimit->isAccepted()) {
                $form->get('email')->addError(new FormError("You've sent too many invitations recently. Please try again later."));

                return $this->render('trainer/coach/index.html.twig', [
                    'coaches' => $associationRepository->findActiveFor($trainer),
                    'invitations' => $invitationRepository->findForTrainer($trainer),
                    'invitationForm' => $form,
                    'now' => new \DateTimeImmutable(),
                ]);
            }

            /** @var array{email: string, name: ?string, message: ?string} $data */
            $data = $form->getData();

            $invitationService->invite(
                new CoachInvitationRequest($data['email'], $data['name'] ?? null, $data['message'] ?? null),
                $trainer,
            );

            $this->addFlash('success', 'Invitation sent.');

            return $this->redirectToRoute('app_trainer_coaches');
        }

        return $this->render('trainer/coach/index.html.twig', [
            'coaches' => $associationRepository->findActiveFor($trainer),
            'invitations' => $invitationRepository->findForTrainer($trainer),
            'invitationForm' => $form,
            'now' => new \DateTimeImmutable(),
        ]);
    }
}
