<?php

declare(strict_types=1);

namespace App\Controller\Trainer;

use App\Entity\User;
use App\Form\AvailabilityFilterFormType;
use App\Repository\PlayerAvailabilitySlotRepository;
use App\Repository\ProfileRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\AvailabilitySummaryFormatter;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A trainer's player roster (AC-8): every player currently associated with
 * the signed-in trainer, however that association was created. `#[IsGranted]`
 * at the class level is S1's belt-and-braces rule every `Trainer\*Controller`
 * follows.
 *
 * Task 35 (AC-22, AC-23, AC-24): an optional `AvailabilityFilterFormType`
 * (`GET`, no CSRF) narrows the roster to only the players available at a
 * chosen day/time via `findRosterAvailableAt()`; unfiltered, the existing
 * `findRosterFor()` call is unchanged. Either way, every card gets a
 * "Best Times" summary from one extra query
 * (`PlayerAvailabilitySlotRepository::findForPlayers()`) against the whole
 * roster's player ids -- never one query per card.
 */
#[IsGranted('ROLE_TRAINER')]
final class PlayerRosterController extends AbstractController
{
    #[Route('/trainer/players', name: 'app_trainer_players', methods: ['GET'])]
    public function index(
        Request $request,
        TrainerPlayerAssociationRepository $associationRepository,
        ProfileRepository $profileRepository,
        PlayerAvailabilitySlotRepository $availabilitySlotRepository,
        AvailabilitySummaryFormatter $availabilitySummaryFormatter,
        TrainerBrandingResolver $brandingResolver,
    ): Response {
        /** @var User $trainer */
        $trainer = $this->getUser();

        $filterForm = $this->createForm(AvailabilityFilterFormType::class);
        $filterForm->handleRequest($request);

        $dayOfWeek = null;
        $minute = null;

        if ($filterForm->isSubmitted() && $filterForm->isValid()) {
            /** @var array{dayOfWeek: ?int, time: ?\DateTimeImmutable} $filterData */
            $filterData = $filterForm->getData();
            $dayOfWeek = $filterData['dayOfWeek'] ?? null;
            $time = $filterData['time'] ?? null;
            $minute = null !== $time ? ((int) $time->format('G') * 60 + (int) $time->format('i')) : null;
        }

        $roster = (null !== $dayOfWeek && null !== $minute)
            ? $associationRepository->findRosterAvailableAt($trainer, $dayOfWeek, $minute)
            : $associationRepository->findRosterFor($trainer);

        $players = array_map(
            static fn ($association) => $association->getPlayer(),
            $roster,
        );

        $playerProfiles = $profileRepository->findPlayerProfilesFor($players);

        $slotsByPlayer = [];
        foreach ($availabilitySlotRepository->findForPlayers($players) as $slot) {
            $slotsByPlayer[$slot->getPlayer()->getId()->toRfc4122()][] = $slot;
        }

        $availabilitySummaries = [];
        foreach ($players as $player) {
            $playerId = $player->getId()->toRfc4122();
            $availabilitySummaries[$playerId] = $availabilitySummaryFormatter->summarize($slotsByPlayer[$playerId] ?? []);
        }

        return $this->render('trainer/player_roster/index.html.twig', [
            'roster' => $roster,
            'playerProfiles' => $playerProfiles,
            'availabilitySummaries' => $availabilitySummaries,
            'filterForm' => $filterForm,
            'branding' => $brandingResolver->forViewerChrome($trainer),
        ]);
    }
}
