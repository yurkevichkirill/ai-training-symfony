<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\PlayerGender;
use App\Form\PlayerShareLinkRegistrationFormType;
use App\Message\SendEmailMessage;
use App\Repository\UserRepository;
use App\Security\IpTruncator;
use App\Security\ShareLinkVoter;
use App\Service\ChildAccountResolver;
use App\Service\ChildTrainerService;
use App\Service\Exception\AccountNotEligibleException;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\RoleNotEligibleForShareLinkException;
use App\Service\Exception\ShareLinkUnavailableException;
use App\Service\PlayerRegistrationRequest;
use App\Service\PlayerRegistrationService;
use App\Service\PlayerShareLinkResolver;
use App\Service\PlayerShareLinkService;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public `/join/{code}` landing page for a trainer's player ShareLink
 * (AC-1, AC-4, AC-7, AC-8, AC-9, AC-11, AC-20). `^/join` is on the
 * `PUBLIC_ACCESS` allow-list in `config/packages/security.yaml`, positioned
 * ahead of the `^/` catch-all, exactly like `AccountInvitationController`'s
 * `^/invitations`.
 *
 * Both actions only translate `PlayerShareLinkResolver`/
 * `PlayerShareLinkService`/`PlayerRegistrationService`'s outcomes to HTTP:
 * no eligibility or persistence decision is made here. `denyAccessUnlessGranted()`
 * with `ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK` runs ahead of the signed-in
 * association call -- the service's own guards (Task 10) remain regardless,
 * per the architecture's defence-in-depth decision.
 */
final class PlayerShareLinkController extends AbstractController
{
    /**
     * AC-1, AC-11, AC-20: resolves the code, then branches. Signed in: the
     * association happens immediately, with no form and no separate
     * confirmation step (AC-11) -- an ineligible role/status is refused by
     * the voter before `PlayerShareLinkService::associate()` ever runs.
     * Anonymous: redirected straight to the registration form (AC-7),
     * carrying the same code.
     */
    #[Route('/join/{code}', name: 'app_share_link_follow', methods: ['GET'])]
    public function follow(
        string $code,
        PlayerShareLinkResolver $resolver,
        PlayerShareLinkService $shareLinkService,
        ChildAccountResolver $childAccountResolver,
        ChildTrainerService $childTrainerService,
        TrainerBrandingResolver $brandingResolver,
    ): Response {
        try {
            $link = $resolver->resolve($code);
        } catch (ShareLinkUnavailableException) {
            return $this->render('share_link/unavailable.html.twig', [], new Response(null, Response::HTTP_NOT_FOUND));
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_share_link_register', ['code' => $code]);
        }

        // Task 34, D3: a signed-in child following ANY trainer's ShareLink
        // is unconditionally blocked -- before the voter, before any
        // association check, and regardless of whether an active
        // association with this exact trainer already exists (the
        // repeat-click edge case gets the identical block+notify, never a
        // carve-out). recordBlockedClick() is the only call this branch
        // ever reaches; PlayerShareLinkService::associate() is never
        // invoked here.
        $childAccount = $childAccountResolver->childAccountOf($user);

        if (null !== $childAccount) {
            $childTrainerService->recordBlockedClick($childAccount, $link);

            return $this->render('share_link/child_blocked.html.twig', [
                'trainerName' => $link->getTrainer()->getDisplayName(),
                'branding' => $brandingResolver->forTrainer($link->getTrainer()),
            ]);
        }

        $this->denyAccessUnlessGranted(ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK, $link);

        try {
            $shareLinkService->associate($user, $link);
        } catch (RoleNotEligibleForShareLinkException|AccountNotEligibleException|ShareLinkUnavailableException $exception) {
            return $this->render('share_link/refused.html.twig', [
                'reason' => $exception->getMessage(),
                'branding' => $brandingResolver->forTrainer($link->getTrainer()),
            ], new Response(null, Response::HTTP_FORBIDDEN));
        }

        $this->addFlash('success', "You're now connected with this trainer.");

        return $this->redirectToRoute('app_home');
    }

    /**
     * AC-7, AC-8, AC-9, AC-10: the registration form a player reaches by
     * following the link while signed out. A signed-in visitor who
     * navigates here directly is sent back to `follow()`, which is the
     * only path that ever calls `PlayerShareLinkService::associate()` --
     * this action only ever calls `PlayerRegistrationService::registerViaShareLink()`.
     *
     * The `share_link_registration_source` limiter (Task 20) is consumed
     * once per actual submission attempt (not on the initial `GET`), so a
     * scraped link cannot be used to brute-force registrations beyond S1's
     * platform-wide protections (the spec's scrape edge case).
     *
     * **AC-10 amended (Task 35, enumeration resistance).** A duplicate
     * email used to earn a field-level form error naming the address --
     * on this public, permanently-broadcastable endpoint (AC-2: `/join/{code}`
     * never expires) that was a definitive oracle for probing whether any
     * given address already has an account, the same class of leak S1's
     * `AccountStatusChecker`/`UniformAuthenticationFailureHandler`
     * deliberately avoid elsewhere. `EmailAlreadyInUseException` now renders
     * the exact same "check your email" response a genuine new registration
     * gets -- the prober learns nothing -- and the *existing* account (found
     * by the submitted, normalized email) is emailed a notice instead. No
     * `User`/`ProfilePlayer`/association is created or touched for this
     * case; `PlayerRegistrationService::registerViaShareLink()`'s own
     * behavior on this exception is unchanged.
     */
    #[Route('/join/{code}/register', name: 'app_share_link_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        string $code,
        PlayerShareLinkResolver $resolver,
        PlayerRegistrationService $registrationService,
        RateLimiterFactory $shareLinkRegistrationSourceLimiter,
        UserRepository $userRepository,
        MessageBusInterface $messageBus,
        TrainerBrandingResolver $brandingResolver,
    ): Response {
        try {
            $link = $resolver->resolve($code);
        } catch (ShareLinkUnavailableException) {
            return $this->render('share_link/unavailable.html.twig', [], new Response(null, Response::HTTP_NOT_FOUND));
        }

        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_share_link_follow', ['code' => $code]);
        }

        $form = $this->createForm(PlayerShareLinkRegistrationFormType::class);
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
                /** @var array{
                 *     firstName: ?string,
                 *     lastName: ?string,
                 *     email: string,
                 *     plainPassword: string,
                 *     phone: ?string,
                 *     playerName: string,
                 *     playerAge: int,
                 *     playerGender: PlayerGender,
                 * } $data
                 */
                $data = $form->getData();

                $registrationRequest = new PlayerRegistrationRequest(
                    email: $data['email'],
                    plainPassword: $data['plainPassword'],
                    firstName: $data['firstName'] ?? null,
                    lastName: $data['lastName'] ?? null,
                    phone: $data['phone'] ?? null,
                    playerName: $data['playerName'],
                    playerAge: $data['playerAge'],
                    playerGender: $data['playerGender'],
                );

                try {
                    $registrationService->registerViaShareLink($registrationRequest, $link);
                } catch (EmailAlreadyInUseException) {
                    // AC-10 amendment (Task 35): fall through to the exact
                    // same "check your email" response a genuine
                    // registration gets below -- never a field-level error
                    // naming the address -- and notify the existing account
                    // instead of the prober. No write happens here:
                    // `registerViaShareLink()` already guaranteed no
                    // partial `User`/`ProfilePlayer`/association was
                    // created before it threw.
                    $existingUser = $userRepository->findOneBy(['email' => User::normalizeEmail($data['email'])]);

                    if (null !== $existingUser) {
                        $messageBus->dispatch(new SendEmailMessage(
                            to: $existingUser->getEmail(),
                            template: SendEmailMessage::TEMPLATE_DUPLICATE_REGISTRATION_ATTEMPT,
                            context: [
                                'trainerName' => $link->getTrainer()->getDisplayName(),
                            ],
                        ));
                    }
                }

                return $this->render('share_link/register_check_email.html.twig', [
                    'branding' => $brandingResolver->forTrainer($link->getTrainer()),
                ]);
            }
        }

        return $this->render('share_link/register.html.twig', [
            'form' => $form,
            'branding' => $brandingResolver->forTrainer($link->getTrainer()),
        ]);
    }
}
