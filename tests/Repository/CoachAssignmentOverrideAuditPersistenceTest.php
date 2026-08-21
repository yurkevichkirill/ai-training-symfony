<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\CoachAssignmentOverrideRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\AccountEventRecorder;
use App\Service\CoachAssignmentOverrideRequest;
use App\Service\CoachAssignmentOverrideService;
use App\Service\CoachAvailabilityService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Task 36: the override-log-outlives-association risk named in the
 * architecture's Risks section. A `CoachAssignmentOverride` row written
 * while a `TrainerCoachAssociation` is active must remain fully readable
 * (coach, trainer, reason, coverage, time) after that association ends --
 * the trainer identity is stored directly on the override row, not derived
 * through the association, so ending it must not orphan or obscure the
 * audit record.
 */
final class CoachAssignmentOverrideAuditPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoachAssignmentOverrideService $service;
    private CoachAssignmentOverrideRepository $overrideRepository;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->overrideRepository = $container->get(CoachAssignmentOverrideRepository::class);

        // CoachAssignmentOverrideService has no production caller in this
        // slice (D3c) and is inlined out of the compiled test container, so
        // it is constructed directly here from its own public collaborators.
        $this->service = new CoachAssignmentOverrideService(
            $container->get(ManagerRegistry::class),
            $this->overrideRepository,
            $container->get(TrainerCoachAssociationRepository::class),
            $container->get(CoachAvailabilityService::class),
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

    public function testAnOverrideRemainsFullyReadableAfterTheAssociationEnds(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('outlives-coach')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('outlives-trainer')));

        $association = new TrainerCoachAssociation($trainer, $coach, null);
        $this->em->persist($association);
        $this->em->flush();

        $written = $this->service->record(
            new CoachAssignmentOverrideRequest(3, 10 * 60, 11 * 60, 'Trainer overrode while coach changed trainers later.'),
            $coach,
            $trainer,
        );
        $overrideId = (string) $written->getId();

        // End the association -- a coach changing trainers between the
        // override and any future audit read is the exact scenario the
        // architecture's risk names.
        $this->em->getConnection()->executeStatement(
            'UPDATE trainer_coach_association SET ended_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'), 'id' => (string) $association->getId()],
        );

        $this->em->clear();

        $coachReloaded = $this->em->getRepository(User::class)->find($coach->getId());
        self::assertInstanceOf(User::class, $coachReloaded);

        $rows = $this->overrideRepository->findForCoach($coachReloaded);
        self::assertCount(1, $rows, 'The override row must still exist after the association ends.');

        $reloaded = $rows[0];
        self::assertSame($overrideId, (string) $reloaded->getId());
        self::assertSame((string) $coach->getId(), (string) $reloaded->getCoach()->getId());
        self::assertSame((string) $trainer->getId(), (string) $reloaded->getOverriddenByUser()->getId());
        self::assertSame('Trainer overrode while coach changed trainers later.', $reloaded->getReason());
        self::assertSame($written->getCoverage(), $reloaded->getCoverage());
        self::assertSame(3, $reloaded->getDayOfWeek());
        self::assertSame(10 * 60, $reloaded->getStartsAtMinute());
        self::assertSame(11 * 60, $reloaded->getEndsAtMinute());
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
