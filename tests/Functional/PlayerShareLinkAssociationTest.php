<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountEvent;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\AccountLifecycleService;
use App\Service\Exception\AccountNotEligibleException;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in Player following a player ShareLink (AC-6, AC-11, AC-12,
 * AC-13 -- Task 23), and the role/status refusal matrix around it (AC-20,
 * edge cases -- Task 24). `PlayerShareLinkController::follow()` and
 * `PlayerShareLinkService::associate()`'s correctness is exercised end to
 * end through the real `/join/{code}` route, the same way
 * `AccountLifecycleFlowTest` proves its service through HTTP plus direct
 * service calls for paths HTTP cannot reach (a DEACTIVATED/DELETED account
 * cannot even hold a session past its next request -- S1's
 * `EquatableInterface` mechanism -- so those two edge cases are exercised
 * directly against `PlayerShareLinkService::associate()`, matching
 * `AccountLifecycleFlowTest`'s own direct-service-call convention for
 * guards that HTTP cannot reach any other way).
 */
final class PlayerShareLinkAssociationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Deliberately no wrapping transaction -- same reason as
     * `TrainerOnboardingFlowTest`/`PlayerShareLinkRegistrationTest`:
     * `AccountEventRecorder` records through its own independent physical
     * connection, which must see already-committed rows. Every other row
     * this test creates cascades from `app_user` on delete (migration
     * `Version20260820095413`); `account_event` and `account_deletion_log`
     * need an explicit delete first (`RESTRICT`), same as
     * `AccountLifecycleFlowTest`'s teardown.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_deletion_log WHERE subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * @see AC-6  usage is attributed to the specific link and its tally
     * @see AC-11 instant association, no form, no separate confirmation step
     */
    public function testSignedInPlayerFollowingALinkCreatesAnInstantAssociationWithNoFormAc6Ac11(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        $this->signIn($player);

        $this->client->request('GET', '/join/'.$link->getCode());

        // AC-11: redirected straight to app_home -- never to the
        // registration form, and no intermediate confirmation step.
        self::assertResponseRedirects('/');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        $association = $associationRepository->findOneFor($trainer, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $association);
        self::assertSame($link->getId()->toRfc4122(), $association->getShareLink()?->getId()->toRfc4122());

        $this->em->clear();
        $reloadedLink = $this->em->getRepository(PlayerShareLink::class)->find($link->getId());
        self::assertInstanceOf(PlayerShareLink::class, $reloadedLink);
        self::assertSame(1, $reloadedLink->getUsageCount(), 'AC-6: usage is tallied on the genuinely new association.');

        // Task 39 coverage gap: PLAYER_TRAINER_ASSOCIATED was never asserted
        // anywhere in this slice's tests. PlayerShareLinkService::associate()
        // records it post-commit, actor = subject = the player, only on the
        // genuinely-new-row branch (see AccountEventType's own docblock).
        $accountEvents = $this->em->getRepository(AccountEvent::class)->findBy(['subjectUser' => $player]);
        self::assertCount(1, $accountEvents, 'Exactly one AccountEvent must be recorded for the genuinely new association.');
        $accountEvent = $accountEvents[0];
        self::assertSame(AccountEventType::PLAYER_TRAINER_ASSOCIATED->value, $accountEvent->getType());
        self::assertSame($player->getId()->toRfc4122(), $accountEvent->getActorUser()?->getId()->toRfc4122());
        self::assertSame($player->getId()->toRfc4122(), $accountEvent->getSubjectUser()->getId()->toRfc4122());
    }

    /**
     * @see AC-12 a second trainer's link adds exactly one new association;
     *      the first is never altered, removed, or duplicated, and no
     *      second account is ever created
     */
    public function testFollowingASecondTrainersLinkAddsANewAssociationWithoutTouchingTheFirstAc12(): void
    {
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b')));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $linkA = $this->createLinkFor($trainerA);
        $linkB = $this->createLinkFor($trainerB);

        $this->signIn($player);
        $this->client->request('GET', '/join/'.$linkA->getCode());
        self::assertResponseRedirects('/');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        $firstAssociation = $associationRepository->findOneFor($trainerA, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $firstAssociation);
        $firstAssociationId = $firstAssociation->getId();
        // Formatted to whole seconds: `created_at` is TIMESTAMP(0), so a
        // round-trip through the DB truncates the microseconds this
        // in-memory value still carries -- comparing the two directly would
        // fail on precision alone, not on the row actually changing.
        $firstCreatedAt = $firstAssociation->getCreatedAt()->format('Y-m-d H:i:sP');

        $this->client->request('GET', '/join/'.$linkB->getCode());
        self::assertResponseRedirects('/');

        $this->em->clear();

        $reloadedFirst = $this->em->getRepository(TrainerPlayerAssociation::class)->find($firstAssociationId);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $reloadedFirst);
        self::assertSame($firstCreatedAt, $reloadedFirst->getCreatedAt()->format('Y-m-d H:i:sP'), 'The first association must be untouched.');

        $secondAssociation = $associationRepository->findOneFor($trainerB, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $secondAssociation);
        self::assertNotSame($firstAssociationId, $secondAssociation->getId());

        $totalCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE player_id = :player',
            ['player' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $totalCount, 'Exactly two rows -- the first untouched, the second newly added.');

        $userCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM app_user WHERE id = :id',
            ['id' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $userCount, 'Still one User row -- never a second account.');
    }

    /**
     * @see AC-6  usage count unchanged on a re-follow
     * @see AC-13 idempotent: no duplicate association is created
     */
    public function testFollowingTheSameLinkTwiceIsIdempotentAc6Ac13(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        $this->signIn($player);
        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseRedirects('/');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        $firstAssociation = $associationRepository->findOneFor($trainer, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $firstAssociation);
        $firstAssociationId = $firstAssociation->getId();

        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseRedirects('/');

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'AC-13: still exactly one row after following the same link twice.');

        $this->em->clear();
        $reloadedLink = $this->em->getRepository(PlayerShareLink::class)->find($link->getId());
        self::assertInstanceOf(PlayerShareLink::class, $reloadedLink);
        self::assertSame(1, $reloadedLink->getUsageCount(), 'AC-6: the idempotent re-follow must not increment usageCount again.');

        $reloadedAssociation = $this->em->getRepository(TrainerPlayerAssociation::class)->find($firstAssociationId);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $reloadedAssociation);
    }

    /**
     * @see AC-20 a player ShareLink only ever creates or extends a
     *      Player-role association; a signed-in Coach is refused outright
     */
    public function testSignedInCoachFollowingAPlayerLinkIsRefusedAc20(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $link = $this->createLinkFor($trainer);

        $this->signIn($coach);
        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        self::assertNull($associationRepository->findOneFor($trainer, $coach));
    }

    /**
     * @see AC-20 a signed-in Trainer following a player link is refused
     */
    public function testSignedInTrainerFollowingAPlayerLinkIsRefusedAc20(): void
    {
        $linkOwner = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('link-owner')));
        $otherTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('other-trainer')));
        $link = $this->createLinkFor($linkOwner);

        $this->signIn($otherTrainer);
        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @see AC-20 a signed-in Super Admin following a player link is refused
     */
    public function testSignedInSuperAdminFollowingAPlayerLinkIsRefusedAc20(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $link = $this->createLinkFor($trainer);

        $this->signIn($admin);
        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Edge case: a DEACTIVATED player following a player ShareLink is
     * refused. Exercised directly against `PlayerShareLinkService::associate()`
     * rather than through HTTP: a DEACTIVATED account's session stops
     * working at its very next request (S1's `EquatableInterface`
     * mechanism, confirmed by `AccountLifecycleFlowTest`), so there is no
     * way to reach `PlayerShareLinkController::follow()` as a *signed-in*
     * deactivated player through a real request -- the only way to
     * exercise this guard at all is the direct service call, same
     * convention as `AccountLifecycleFlowTest::testReactivatingAnAlreadyActiveAccountIsRefused()`.
     */
    public function testADeactivatedPlayerCannotAssociateViaAShareLink(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::deactivated(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        $this->expectException(AccountNotEligibleException::class);
        $service->associate($player, $link);
    }

    /**
     * Edge case: a DELETED (GDPR-anonymized) player following a player
     * ShareLink is refused. Same direct-service-call rationale as the
     * DEACTIVATED case above.
     */
    public function testADeletedPlayerCannotAssociateViaAShareLink(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        self::getContainer()->get(AccountLifecycleService::class)->delete($player, $admin, null);

        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        $this->expectException(AccountNotEligibleException::class);
        $service->associate($player, $link);
    }

    /**
     * Edge case: the trainer who owns a ShareLink is themselves
     * DEACTIVATED. `PlayerShareLinkResolver::resolve()` filters
     * `trainer.status = ACTIVE` in its one query, so an unknown code and a
     * deactivated trainer's code are indistinguishable -- both render the
     * same "no longer available" outcome. Reached anonymously: this
     * resolution happens before any sign-in check, so the visitor's own
     * state is irrelevant to it.
     */
    public function testFollowingALinkWhoseTrainerIsDeactivatedIsRefusedAsNoLongerAvailable(): void
    {
        $trainer = $this->persist(UserFactory::deactivated(UserRole::TRAINER));
        $link = $this->createLinkFor($trainer);

        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('.alert-message', 'no longer available');
    }

    /**
     * Edge case: the trainer who owns a ShareLink has been GDPR-deleted --
     * the same "no longer available" outcome as the deactivated case and an
     * unknown code, never a distinguishing message (non-enumerating).
     */
    public function testFollowingALinkWhoseTrainerIsDeletedIsRefusedAsNoLongerAvailable(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $link = $this->createLinkFor($trainer);

        self::getContainer()->get(AccountLifecycleService::class)->delete($trainer, $admin, null);

        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('.alert-message', 'no longer available');
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->getOrCreateFor($trainer);
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
