<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CoachAssignmentOverride;
use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\AvailabilityCoverage;
use App\Enum\UserRole;
use App\Repository\CoachAssignmentOverrideRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\AccountEventRecorder;
use App\Service\CoachAssignmentOverrideRequest;
use App\Service\CoachAssignmentOverrideService;
use App\Service\CoachAvailabilityService;
use App\Service\Exception\CoachActionNotPermittedException;
use App\Service\Exception\MissingOverrideReasonException;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Task 33: `CoachAssignmentOverrideService::record()` against the real
 * database (AC-7, AC-8, edge case 4).
 */
final class CoachAssignmentOverrideServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoachAssignmentOverrideService $service;
    private CoachAvailabilityService $availabilityService;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->availabilityService = $container->get(CoachAvailabilityService::class);

        // CoachAssignmentOverrideService has no production caller in this
        // slice (D3c) and is inlined out of the compiled test container, so
        // it is constructed directly here from its own public collaborators.
        $this->service = new CoachAssignmentOverrideService(
            $container->get(ManagerRegistry::class),
            $container->get(CoachAssignmentOverrideRepository::class),
            $container->get(TrainerCoachAssociationRepository::class),
            $this->availabilityService,
            $container->get(AccountEventRecorder::class),
        );
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM coach_assignment_override');
        $connection->executeStatement('DELETE FROM trainer_coach_association');

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testRecordRefusesAnEmptyReasonAndInsertsNothingAc7(): void
    {
        [$coach, $trainer] = $this->makeAssociatedPair('empty-reason');

        try {
            $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, ''), $coach, $trainer);
            self::fail('Expected MissingOverrideReasonException.');
        } catch (MissingOverrideReasonException) {
        }

        self::assertCount(0, $this->service->findForCoach($coach));
    }

    public function testRecordRefusesAWhitespaceOnlyReasonAndInsertsNothingAc7(): void
    {
        [$coach, $trainer] = $this->makeAssociatedPair('whitespace-reason');

        try {
            $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, "   \t\n"), $coach, $trainer);
            self::fail('Expected MissingOverrideReasonException.');
        } catch (MissingOverrideReasonException) {
        }

        self::assertCount(0, $this->service->findForCoach($coach));
    }

    /**
     * Edge case 4: two rapid record() calls for the same coach/trainer pair
     * produce two rows, not one -- this is an audit log, no dedup.
     */
    public function testTwoRapidRecordCallsForTheSamePairProduceTwoRowsEdgeCase4(): void
    {
        [$coach, $trainer] = $this->makeAssociatedPair('rapid-pair');

        $first = $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, 'Filling in for injured coach.'), $coach, $trainer);
        $second = $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, 'Filling in for injured coach.'), $coach, $trainer);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());

        $rows = $this->service->findForCoach($coach);
        self::assertCount(2, $rows);
    }

    /**
     * AC-8: findForCoach()/findForTrainer() return newest-first with
     * reason, coverage, and candidate time intact.
     */
    public function testFindForCoachAndFindForTrainerReturnNewestFirstWithFieldsIntactAc8(): void
    {
        [$coach, $trainer] = $this->makeAssociatedPair('newest-first');

        $older = $this->service->record(new CoachAssignmentOverrideRequest(2, 8 * 60, 9 * 60, 'First override.'), $coach, $trainer);

        // created_at has second precision (TIMESTAMP(0)) -- back-date the
        // first row by a full second rather than relying on a real-time
        // sleep, so newest-first ordering is deterministic regardless of
        // how fast the two record() calls actually execute.
        $this->em->getConnection()->executeStatement(
            'UPDATE coach_assignment_override SET created_at = created_at - INTERVAL \'1 second\' WHERE id = :id',
            ['id' => (string) $older->getId()],
        );

        $newer = $this->service->record(new CoachAssignmentOverrideRequest(3, 14 * 60, 15 * 60, 'Second override.'), $coach, $trainer);

        $forCoach = $this->service->findForCoach($coach);
        self::assertCount(2, $forCoach);
        self::assertSame($newer->getId()->toRfc4122(), $forCoach[0]->getId()->toRfc4122());
        self::assertSame($older->getId()->toRfc4122(), $forCoach[1]->getId()->toRfc4122());
        self::assertSame('Second override.', $forCoach[0]->getReason());
        self::assertSame(3, $forCoach[0]->getDayOfWeek());
        self::assertSame(14 * 60, $forCoach[0]->getStartsAtMinute());
        self::assertSame(15 * 60, $forCoach[0]->getEndsAtMinute());

        $forTrainer = $this->service->findForTrainer($trainer);
        self::assertCount(2, $forTrainer);
        self::assertSame($newer->getId()->toRfc4122(), $forTrainer[0]->getId()->toRfc4122());
    }

    /**
     * record() stores the coverage it itself evaluated, not a value the
     * caller passed (there is no caller-supplied coverage parameter at
     * all -- the request DTO carries only day/range/reason).
     */
    public function testRecordStoresTheCoverageItItselfEvaluated(): void
    {
        [$coach, $trainer] = $this->makeAssociatedPair('coverage-evaluated');

        // Coach has no saved availability at all -> UNAVAILABLE.
        $override = $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, 'No availability saved.'), $coach, $trainer);

        self::assertSame(AvailabilityCoverage::UNAVAILABLE, $override->getCoverage());

        $evaluated = $this->availabilityService->evaluate($coach, 1, new \App\Availability\TimeRange(9 * 60, 10 * 60));
        self::assertSame($evaluated, $override->getCoverage());
    }

    public function testRecordRefusesWhenTrainerHasNoActiveAssociationToCoach(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('unassociated-coach')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('unassociated-trainer')));

        try {
            $this->service->record(new CoachAssignmentOverrideRequest(1, 9 * 60, 10 * 60, 'No association exists.'), $coach, $trainer);
            self::fail('Expected CoachActionNotPermittedException.');
        } catch (CoachActionNotPermittedException) {
        }

        self::assertCount(0, $this->service->findForCoach($coach));
    }

    /**
     * @return array{0: User, 1: User} coach, trainer
     */
    private function makeAssociatedPair(string $prefix): array
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email($prefix.'-coach')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email($prefix.'-trainer')));

        $association = new TrainerCoachAssociation($trainer, $coach, null);
        $this->em->persist($association);
        $this->em->flush();

        return [$coach, $trainer];
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
