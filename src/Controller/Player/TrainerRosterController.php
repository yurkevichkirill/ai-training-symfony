<?php

declare(strict_types=1);

namespace App\Controller\Player;

use App\Entity\User;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Repository\UserRepository;
use App\Service\Exception\NoActiveTrainerAssociationException;
use App\Service\PlayerShareLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * A player's own trainer roster (Task 36, AC-11 amendment): every trainer
 * the signed-in player is currently associated with, each with a "Leave"
 * action. `#[IsGranted]` at the class level is S1's belt-and-braces rule
 * every role-scoped controller follows.
 *
 * Both actions only ever operate on `$this->getUser()` as the player --
 * never a player id taken from the request -- the same "operate on the
 * authenticated user only" rule `ProfileController` established. `leave()`
 * takes a trainer id from the route (which trainer to leave is exactly what
 * the request must say), but `PlayerShareLinkService::leave()` only ever
 * ends the association between *that* trainer and the signed-in player --
 * there is no way for this action to touch any other player's row.
 */
#[IsGranted('ROLE_PLAYER')]
final class TrainerRosterController extends AbstractController
{
    #[Route('/player/trainers', name: 'app_player_trainers', methods: ['GET'])]
    public function index(TrainerPlayerAssociationRepository $associationRepository): Response
    {
        /** @var User $player */
        $player = $this->getUser();

        return $this->render('player/trainer_roster/index.html.twig', [
            'roster' => $associationRepository->findActiveForPlayer($player),
        ]);
    }

    #[Route('/player/trainers/{id}/leave', name: 'app_player_trainers_leave', methods: ['POST'])]
    public function leave(
        Request $request,
        string $id,
        UserRepository $userRepository,
        PlayerShareLinkService $shareLinkService,
    ): Response {
        $trainer = $this->findTrainerOrFail($userRepository, $id);
        $this->assertCsrf($request, 'player_leave_trainer_'.$id);

        /** @var User $player */
        $player = $this->getUser();

        try {
            $shareLinkService->leave($player, $trainer);
            $this->addFlash('success', 'You left this trainer.');
        } catch (NoActiveTrainerAssociationException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_player_trainers');
    }

    private function findTrainerOrFail(UserRepository $userRepository, string $id): User
    {
        $trainer = $userRepository->find($this->parseUuid($id));

        if (!$trainer instanceof User) {
            throw $this->createNotFoundException();
        }

        return $trainer;
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
