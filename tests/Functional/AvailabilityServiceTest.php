<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\AccountEvent;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Repository\PlayerAvailabilitySlotRepository;
use App\Service\AccountEventRecorder;
use App\Service\AvailabilityService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Service-level coverage for `AvailabilityService` (Task 19, S4): the
 * replace-then-read round trip, normalization on save (D5c), per-player
 * isolation (AC-20), an empty day reading back as "Not Available" (AC-24),
 * and the post-commit `PLAYER_AVAILABILITY_UPDATED` event (AC-19, AC-21).
 *
 * `KernelTestCase` (direct service call), same "not yet wired into any
 * controller" rationale `ChildTrainerServiceTest` documents: no controller
 * exists yet for `AvailabilityService`, so it is instantiated directly
 * rather than fetched from the container, using real collaborators (all of
 * which already have a live consumer elsewhere in the container).
 */
final class AvailabilityServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AvailabilityService $service;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->service = new AvailabilityService(
            $container->get(ManagerRegistry::class),
            $container->get(PlayerAvailabilitySlotRepository::class),
            $container->get(AccountEventRecorder::class),
        );
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testWeekForOnAFreshPlayerIsEntirelyEmptyAc24(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('fresh-player')));

        $week = $this->service->weekFor($player);

        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            self::assertSame([], $week->rangesForDay($day), \sprintf('Day %d must be Not Available with no rows.', $day));
        }
    }

    public function testReplaceWeekPersistsNormalizedRangesAndWeekForReadsThemBackAc19Ac24(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('replace-week-player')));

        // Submitted out of order, with a touching pair on Monday that must
        // merge (D5c) -- 5-6pm and 6-7pm collapse into one 5-7pm row.
        $submitted = new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [
                new TimeRange(18 * 60, 19 * 60),
                new TimeRange(17 * 60, 18 * 60),
            ],
            WeeklyAvailability::WEDNESDAY => [
                new TimeRange(18 * 60, 21 * 60),
            ],
        ]);

        $this->service->replaceWeek($player, $submitted, $player);

        $reloaded = $this->service->weekFor($player);

        $mondayRanges = $reloaded->rangesForDay(WeeklyAvailability::MONDAY);
        self::assertCount(1, $mondayRanges, 'The touching 5-6pm/6-7pm pair must merge into a single row.');
        self::assertSame(17 * 60, $mondayRanges[0]->startsAtMinute);
        self::assertSame(19 * 60, $mondayRanges[0]->endsAtMinute);

        $wednesdayRanges = $reloaded->rangesForDay(WeeklyAvailability::WEDNESDAY);
        self::assertCount(1, $wednesdayRanges);
        self::assertSame(18 * 60, $wednesdayRanges[0]->startsAtMinute);
        self::assertSame(21 * 60, $wednesdayRanges[0]->endsAtMinute);

        // Every unsubmitted day, including a day never mentioned at all, is
        // "Not Available" -- zero rows, never a placeholder (AC-24).
        foreach ([2, 4, 5, 6, 7] as $day) {
            self::assertSame([], $reloaded->rangesForDay($day));
        }
    }

    public function testReplaceWeekIsIsolatedBetweenTwoPlayersAc20(): void
    {
        $playerA = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('isolation-player-a')));
        $playerB = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('isolation-player-b')));

        $this->service->replaceWeek($playerA, new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(9 * 60, 10 * 60)],
        ]), $playerA);
        $this->service->replaceWeek($playerB, new WeeklyAvailability([
            WeeklyAvailability::TUESDAY => [new TimeRange(11 * 60, 12 * 60)],
        ]), $playerB);

        // Saving player B's week must not touch player A's rows, and vice
        // versa -- AC-20's isolation is the WHERE clause on player_id.
        $weekA = $this->service->weekFor($playerA);
        self::assertCount(1, $weekA->rangesForDay(WeeklyAvailability::MONDAY));
        self::assertSame([], $weekA->rangesForDay(WeeklyAvailability::TUESDAY));

        $weekB = $this->service->weekFor($playerB);
        self::assertCount(1, $weekB->rangesForDay(WeeklyAvailability::TUESDAY));
        self::assertSame([], $weekB->rangesForDay(WeeklyAvailability::MONDAY));

        // Re-saving player A's week again must never affect player B's rows.
        $this->service->replaceWeek($playerA, new WeeklyAvailability([
            WeeklyAvailability::FRIDAY => [new TimeRange(13 * 60, 14 * 60)],
        ]), $playerA);

        $weekBAfter = $this->service->weekFor($playerB);
        self::assertCount(1, $weekBAfter->rangesForDay(WeeklyAvailability::TUESDAY), 'A save for player A must never affect player B\'s rows.');
    }

    public function testReplaceWeekOverwritesThePreviousSaveEntirelyAc19(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('overwrite-player')));

        $this->service->replaceWeek($player, new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(9 * 60, 10 * 60)],
        ]), $player);

        // A second save that sets Monday to "Not Available" (no ranges at
        // all) must clear the previous rows, not merge with them.
        $this->service->replaceWeek($player, new WeeklyAvailability([
            WeeklyAvailability::TUESDAY => [new TimeRange(11 * 60, 12 * 60)],
        ]), $player);

        $week = $this->service->weekFor($player);
        self::assertSame([], $week->rangesForDay(WeeklyAvailability::MONDAY), 'The old Monday rows must be gone after a save that omits Monday.');
        self::assertCount(1, $week->rangesForDay(WeeklyAvailability::TUESDAY));
    }

    public function testReplaceWeekRecordsPlayerAvailabilityUpdatedWithParentAsActorAc19Ac21(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-parent')));
        $child = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-child')));

        $this->service->replaceWeek($child, new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(9 * 60, 10 * 60)],
        ]), $parent);

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $child,
            'type' => AccountEventType::PLAYER_AVAILABILITY_UPDATED->value,
        ]);

        self::assertCount(1, $events);
        self::assertSame($parent->getId()->toRfc4122(), $events[0]->getActorUser()?->getId()->toRfc4122(), 'The parent acting for the child is the actor.');
        self::assertSame($child->getId()->toRfc4122(), $events[0]->getSubjectUser()->getId()->toRfc4122(), 'The child is always the subject.');
    }

    public function testReplaceWeekRecordsPlayerAvailabilityUpdatedWithThePlayerThemselvesAsActor(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-self')));

        $this->service->replaceWeek($player, new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(9 * 60, 10 * 60)],
        ]), $player);

        $events = $this->em->getRepository(AccountEvent::class)->findBy([
            'subjectUser' => $player,
            'type' => AccountEventType::PLAYER_AVAILABILITY_UPDATED->value,
        ]);

        self::assertCount(1, $events);
        self::assertSame($player->getId()->toRfc4122(), $events[0]->getActorUser()?->getId()->toRfc4122());
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
