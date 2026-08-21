<?php

declare(strict_types=1);

namespace App\Controller\Coach;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\User;
use App\Form\AvailabilityWeekFormType;
use App\Security\CoachVoter;
use App\Service\CoachAvailabilityService;
use App\Service\TrainerBrandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A coach's own weekly availability grid (Task 21; AC-1, AC-2, AC-3, AC-4).
 * Reuses S4's `AvailabilityWeekFormType` and the same two form-data
 * conversion helpers `Player\AvailabilityController` uses -- verified
 * player-coupling-free by Task 20.
 */
#[IsGranted('ROLE_COACH')]
final class AvailabilityController extends AbstractController
{
    #[Route('/coach/availability', name: 'app_coach_availability', methods: ['GET', 'POST'])]
    public function edit(Request $request, CoachAvailabilityService $availabilityService, TrainerBrandingResolver $brandingResolver): Response
    {
        /** @var User $coach */
        $coach = $this->getUser();

        $this->denyAccessUnlessGranted(CoachVoter::EDIT_COACH_AVAILABILITY, $coach);

        $week = $availabilityService->weekFor($coach);

        $form = $this->createForm(AvailabilityWeekFormType::class, $this->toFormData($week));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<int, array{ranges: list<array{start: int, end: int}>}> $data */
            $data = $form->getData();

            $availabilityService->replaceWeek($coach, $this->fromFormData($data), $coach);

            $this->addFlash('success', 'Availability saved -- your trainer(s) can now see this schedule.');

            return $this->redirectToRoute('app_coach_availability');
        }

        return $this->render('coach/availability/edit.html.twig', [
            'form' => $form,
            'branding' => $brandingResolver->forViewerChrome($coach),
        ]);
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
