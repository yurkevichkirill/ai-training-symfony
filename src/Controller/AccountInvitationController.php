<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Service\AccountInvitationService;
use App\Service\Exception\AccountInvitationAlreadyConsumedException;
use App\Service\Exception\AccountInvitationExpiredException;
use App\Service\Exception\InvalidAccountInvitationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public "set your password" flow a trainer invitation link leads to
 * (AC-5, AC-6). `^/invitations` is on the `PUBLIC_ACCESS` allow-list in
 * `config/packages/security.yaml`.
 *
 * Deliberately does not sign the user in on success: the very first
 * authenticated action on a new account goes through the same audited
 * `form_login` path as everyone else, exactly like `ResetPasswordController`
 * does for a completed reset.
 */
final class AccountInvitationController extends AbstractController
{
    #[Route('/invitations/{token}', name: 'app_account_invitation', methods: ['GET', 'POST'])]
    public function consume(Request $request, string $token, AccountInvitationService $invitationService): Response
    {
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            try {
                $invitationService->consume($token, $plainPassword);
            } catch (InvalidAccountInvitationException|AccountInvitationAlreadyConsumedException|AccountInvitationExpiredException $exception) {
                return $this->render('account_invitation/consume.html.twig', [
                    'form' => null,
                    'state' => 'refused',
                    'reason' => $exception->getMessage(),
                ]);
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('account_invitation/consume.html.twig', [
            'form' => $form,
            'state' => 'form',
            'reason' => null,
        ]);
    }
}
