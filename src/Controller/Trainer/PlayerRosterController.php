<?php

declare(strict_types=1);

namespace App\Controller\Trainer;

use App\Entity\User;
use App\Repository\ProfileRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A trainer's player roster (AC-8): every player currently associated with
 * the signed-in trainer, however that association was created. `#[IsGranted]`
 * at the class level is S1's belt-and-braces rule every `Trainer\*Controller`
 * follows.
 */
#[IsGranted('ROLE_TRAINER')]
final class PlayerRosterController extends AbstractController
{
    #[Route('/trainer/players', name: 'app_trainer_players', methods: ['GET'])]
    public function index(
        TrainerPlayerAssociationRepository $associationRepository,
        ProfileRepository $profileRepository,
    ): Response {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $roster = $associationRepository->findRosterFor($trainer);
        $playerProfiles = $profileRepository->findPlayerProfilesFor(array_map(
            static fn ($association) => $association->getPlayer(),
            $roster,
        ));

        return $this->render('trainer/player_roster/index.html.twig', [
            'roster' => $roster,
            'playerProfiles' => $playerProfiles,
        ]);
    }
}
