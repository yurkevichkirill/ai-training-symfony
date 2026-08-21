<?php

declare(strict_types=1);

namespace App\Controller\Player;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\User;
use App\Form\AvailabilityWeekFormType;
use App\Repository\UserRepository;
use App\Security\AvailabilityVoter;
use App\Service\AvailabilityService;
use App\Service\PlayerContext;
use App\Service\PlayerContextProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The weekly availability grid, edited by the player themselves or by a
 * parent on a child's behalf (Task 33, AC-19, AC-20, AC-21). The self/child
 * switcher comes entirely from `PlayerContextProvider::contextsFor()` --
 * with exactly one entry (no children, or a signed-in child) no switcher is
 * rendered at all, the "no children" edge case.
 */
#[IsGranted('ROLE_PLAYER')]
final class AvailabilityController extends AbstractController
{
    #[Route('/player/availability', name: 'app_player_availability', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        PlayerContextProvider $contextProvider,
        AvailabilityService $availabilityService,
        UserRepository $userRepository,
    ): Response {
        /** @var User $actor */
        $actor = $this->getUser();

        $contexts = $contextProvider->contextsFor($actor);
        $subjectUser = $this->resolveSubject($contexts, $request->query->get('player'), $userRepository);

        // The voter, not the switcher's own contents, is the real gate here
        // (defence-in-depth): a requested id outside the actor's own
        // contexts is still resolved against the real User table, so it is
        // refused by `AvailabilityVoter::EDIT_AVAILABILITY` rather than
        // silently and invisibly falling back to the actor's own grid.
        $this->denyAccessUnlessGranted(AvailabilityVoter::EDIT_AVAILABILITY, $subjectUser);

        $week = $availabilityService->weekFor($subjectUser);

        $form = $this->createForm(AvailabilityWeekFormType::class, $this->toFormData($week));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<int, array{ranges: list<array{start: int, end: int}>}> $data */
            $data = $form->getData();

            $availabilityService->replaceWeek($subjectUser, $this->fromFormData($data), $actor);

            $this->addFlash('success', 'Availability saved -- your trainers can now see these times.');

            return $this->redirectToRoute('app_player_availability', $this->subjectQueryParam($subjectUser, $contexts));
        }

        return $this->render('player/availability/edit.html.twig', [
            'form' => $form,
            'contexts' => $contexts,
            'subjectUser' => $subjectUser,
            'showSwitcher' => \count($contexts) > 1,
        ]);
    }

    /**
     * @param list<PlayerContext> $contexts
     */
    private function resolveSubject(array $contexts, mixed $requestedPlayerId, UserRepository $userRepository): User
    {
        if (\is_string($requestedPlayerId) && '' !== $requestedPlayerId && Uuid::isValid($requestedPlayerId)) {
            // Prefer the already-loaded context (no extra query) when the
            // requested id is one of the actor's own -- otherwise resolve it
            // against the real User table so the voter below sees the
            // actual requested subject and can refuse it, rather than this
            // method silently substituting the actor's own player.
            foreach ($contexts as $context) {
                if ((string) $context->player->getId() === $requestedPlayerId) {
                    return $context->player;
                }
            }

            $requested = $userRepository->find($requestedPlayerId);

            if ($requested instanceof User) {
                return $requested;
            }
        }

        // Default: the self context, always the first entry
        // (`PlayerContextProvider::contextsFor()`'s own construction order).
        return $contexts[0]->player;
    }

    /**
     * @param list<PlayerContext> $contexts
     *
     * @return array<string, string>
     */
    private function subjectQueryParam(User $subjectUser, array $contexts): array
    {
        if ($contexts[0]->player === $subjectUser) {
            return [];
        }

        return ['player' => (string) $subjectUser->getId()];
    }

    /**
     * @return array<int, array{ranges: list<array{start: int, end: int}>}>
     */
    private function toFormData(WeeklyAvailability $week): array
    {
        $data = [];

        for ($day = WeeklyAvailability::MONDAY; $day <= WeeklyAvailability::SUNDAY; ++$day) {
            $data[$day] = [
                'ranges' => array_map(
                    static fn (TimeRange $range): array => ['start' => $range->startsAtMinute, 'end' => $range->endsAtMinute],
                    $week->rangesForDay($day),
                ),
            ];
        }

        return $data;
    }

    /**
     * @param array<int, array{ranges: list<array{start: int, end: int}>}> $data
     */
    private function fromFormData(array $data): WeeklyAvailability
    {
        $rangesByDay = [];

        foreach ($data as $day => $dayData) {
            $rangesByDay[$day] = array_map(
                static fn (array $range): TimeRange => new TimeRange($range['start'], $range['end']),
                $dayData['ranges'],
            );
        }

        return new WeeklyAvailability($rangesByDay);
    }
}
