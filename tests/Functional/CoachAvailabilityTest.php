<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoachAvailabilitySlot;
use App\Entity\PlayerAvailabilitySlot;
use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 27 (AC-1, AC-2, AC-3, AC-4, AC-15) and Task 28 (AC-5): a coach's own
 * weekly availability grid, driven through the real HTTP form layer
 * (`Coach\AvailabilityController`), and the trainer roster's read side
 * (`Trainer\CoachController::index()`).
 */
final class CoachAvailabilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM coach_availability_slot');
        $connection->executeStatement('DELETE FROM player_availability_slot');
        $connection->executeStatement('DELETE FROM trainer_coach_association');

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * AC-1: two ranges on one day and ranges on two other days, saved and
     * read back; a day left empty stores zero rows and renders as not
     * available.
     */
    public function testACoachSetsRangesOnThreeDaysAndReadsThemBackAndAnEmptyDayStoresZeroRowsAc1(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-avail-set')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/coach/availability');
        self::assertResponseIsSuccessful();

        // Two ranges on Monday, one each on Wednesday and Friday.
        $form = $crawler->filter('form')->form();
        $values = $form->getPhpValues();
        $values['availability_week_form'][1]['ranges'][0] = ['start' => '16:00', 'end' => '18:00'];
        $values['availability_week_form'][1]['ranges'][1] = ['start' => '19:00', 'end' => '21:00'];
        $values['availability_week_form'][3]['ranges'][0] = ['start' => '09:00', 'end' => '12:00'];
        $values['availability_week_form'][5]['ranges'][0] = ['start' => '10:00', 'end' => '11:00'];

        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/coach/availability');

        $slots = $this->em->getRepository(CoachAvailabilitySlot::class)->findBy(['coach' => $coach]);
        self::assertCount(4, $slots, 'AC-1: exactly the four submitted ranges are stored.');

        $mondaySlots = array_values(array_filter($slots, static fn (CoachAvailabilitySlot $s): bool => 1 === $s->getDayOfWeek()));
        self::assertCount(2, $mondaySlots, 'AC-1: Monday carries both submitted ranges.');

        // A day left empty (e.g. Tuesday) stores zero rows.
        $tuesdayCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM coach_availability_slot WHERE coach_id = :coach AND day_of_week = 2',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $tuesdayCount);
    }

    /**
     * AC-2: saving twice with different ranges leaves exactly the second
     * set, with no duplicate rows for any day.
     */
    public function testSavingTwiceReplacesRatherThanAppendsAc2(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-avail-replace')));
        $this->signIn($coach);

        $firstCrawler = $this->client->request('GET', '/coach/availability');
        $this->submitAvailabilityForm($firstCrawler, [1 => ['start' => '09:00', 'end' => '10:00']]);
        self::assertResponseRedirects('/coach/availability');

        $secondCrawler = $this->client->request('GET', '/coach/availability');
        $this->submitAvailabilityForm($secondCrawler, [1 => ['start' => '14:00', 'end' => '15:00'], 2 => ['start' => '08:00', 'end' => '09:00']]);
        self::assertResponseRedirects('/coach/availability');

        $slots = $this->em->getRepository(CoachAvailabilitySlot::class)->findBy(['coach' => $coach]);
        self::assertCount(2, $slots, 'AC-2: only the second submission survives, no duplicates.');

        $mondaySlots = array_values(array_filter($slots, static fn (CoachAvailabilitySlot $s): bool => 1 === $s->getDayOfWeek()));
        self::assertCount(1, $mondaySlots);
        self::assertSame(14 * 60, $mondaySlots[0]->getStartsAtMinute());
    }

    /**
     * AC-3: a coach's save leaves another coach's rows and every
     * player_availability_slot row untouched.
     */
    public function testACoachsSaveLeavesAnotherCoachsAndAllPlayerRowsUntouchedAc3(): void
    {
        $coachA = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-avail-a')));
        $coachB = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-avail-b')));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('coach-avail-player')));

        $this->em->persist(new CoachAvailabilitySlot($coachB, 4, 8 * 60, 9 * 60));
        $this->em->persist(new PlayerAvailabilitySlot($player, 4, 8 * 60, 9 * 60));
        $this->em->flush();

        $this->signIn($coachA);
        $crawler = $this->client->request('GET', '/coach/availability');
        $this->submitAvailabilityForm($crawler, [1 => ['start' => '09:00', 'end' => '10:00']]);
        self::assertResponseRedirects('/coach/availability');

        $coachBSlots = $this->em->getRepository(CoachAvailabilitySlot::class)->findBy(['coach' => $coachB]);
        self::assertCount(1, $coachBSlots, 'AC-3: coach B\'s row is untouched.');
        self::assertSame(4, $coachBSlots[0]->getDayOfWeek());

        $playerSlotCount = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM player_availability_slot')->fetchOne();
        self::assertSame(1, (int) $playerSlotCount, 'AC-3: every player_availability_slot row is untouched.');
    }

    /**
     * AC-4: the post-save flash names that the trainer(s) they work with
     * can see this schedule.
     */
    public function testPostSaveConfirmationNamesTrainersAc4(): void
    {
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-avail-confirm')));
        $this->signIn($coach);

        $crawler = $this->client->request('GET', '/coach/availability');
        $this->submitAvailabilityForm($crawler, [2 => ['start' => '09:00', 'end' => '10:00']]);
        self::assertResponseRedirects('/coach/availability');

        $session = $this->client->getRequest()->getSession();
        $flashes = $session->getFlashBag()->peek('success');
        self::assertNotEmpty($flashes, 'AC-4: a success flash must be queued after saving.');
        self::assertStringContainsString('trainer', $flashes[0]);
    }

    /**
     * AC-15, forged-request edge case: a trainer, a player, and a Super
     * Admin each get a 403 -- not a redirect -- on both GET and a forged
     * POST to /coach/availability.
     */
    public function testNonCoachRolesGet403OnGetAndForgedPostAc15(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-avail-trainer')));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('coach-avail-player-role')));
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN, UserFactory::email('coach-avail-admin')));

        foreach ([$trainer, $player, $admin] as $user) {
            self::ensureKernelShutdown();
            $this->client = self::createClient();
            $this->client->disableReboot();

            $this->signIn($user);

            $this->client->request('GET', '/coach/availability');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, \sprintf('%s must get 403 on GET.', $user->getRole()->value));

            $this->client->request('POST', '/coach/availability', [
                'availability_week_form' => [1 => ['ranges' => [0 => ['start' => '09:00', 'end' => '10:00']]]],
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, \sprintf('%s must get 403 on forged POST.', $user->getRole()->value));
        }
    }

    /**
     * AC-5: the trainer's /trainer/coaches page shows the summary string
     * for an actively associated coach.
     */
    public function testTrainerRosterShowsSummaryForAnActivelyAssociatedCoachAc5(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-roster-trainer')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-roster-coach')));
        $this->associate($trainer, $coach);

        $this->em->persist(new CoachAvailabilitySlot($coach, 1, 17 * 60, 20 * 60));
        $this->em->flush();

        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mon 5-8pm');
    }

    /**
     * Edge case 2: a coach who never saved availability renders the
     * explicit "no availability set" state, never a blank cell.
     */
    public function testTrainerRosterShowsNoAvailabilitySetStateForACoachWhoNeverSavedEdgeCase2(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-roster-trainer-empty')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-roster-coach-empty')));
        $this->associate($trainer, $coach);

        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Not available');
    }

    /**
     * Task 37 regression: AC-5's summary must survive the invite form's
     * error re-render, not only the plain GET.
     *
     * `Trainer\CoachController::invite()`'s two error paths used to render
     * `trainer/coach/index.html.twig` from a hand-built variable array that
     * omitted `availabilitySummaries`, and the template's defensive
     * `|default({})` turned that omission into a silent "Not available" for
     * every coach on the roster -- reporting a coach with a saved schedule
     * as having none. Both error paths now build their payload through the
     * same `coachesPageData()` helper as `index()`. The invalid-form path
     * (blank email) is the one exercised here because it needs no rate
     * limiter state to reach.
     */
    public function testTrainerRosterKeepsRealAvailabilitySummaryWhenTheInviteFormRerendersWithAnErrorAc5(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-roster-invite-error-trainer')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-roster-invite-error-coach')));
        $this->associate($trainer, $coach);

        $this->em->persist(new CoachAvailabilitySlot($coach, 1, 17 * 60, 20 * 60));
        $this->em->flush();

        $this->signIn($trainer);
        $crawler = $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mon 5-8pm');

        // Submit the invite form with a blank email -- refused by its own
        // NotBlank constraint, re-rendering this same page.
        $this->client->submit($crawler->selectButton('Send invitation')->form(['coach_invitation_form[email]' => '']));

        // A refused form re-render is 422, not 200.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.field-errors', 'This value should not be blank.');
        self::assertSelectorTextContains(
            'body',
            'Mon 5-8pm',
            "The coach's saved availability must still render on the invite form's error re-render, not collapse to \"Not available\".",
        );
    }

    /**
     * AC-5's negative half / edge case 3: once the coach's association
     * ends, the former trainer no longer sees the summary or the "no
     * availability set" state -- the coach's row is absent entirely.
     */
    public function testFormerTrainerLosesReadAccessAfterAssociationEndsAc5EdgeCase3(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('coach-roster-former-trainer')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('coach-roster-former-coach')));
        $association = $this->associate($trainer, $coach);

        $this->em->persist(new CoachAvailabilitySlot($coach, 1, 17 * 60, 20 * 60));
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE trainer_coach_association SET ended_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'), 'id' => (string) $association->getId()],
        );

        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Mon 5-8pm');
        self::assertSelectorTextNotContains('body', $coach->getEmail());
    }

    /**
     * @param array<int, array{start: string, end: string}> $rangesByDay
     */
    private function submitAvailabilityForm(Crawler $crawler, array $rangesByDay): void
    {
        $form = $crawler->filter('form')->form();
        $values = $form->getPhpValues();

        foreach ($rangesByDay as $day => $range) {
            $values['availability_week_form'][$day]['ranges'][0] = $range;
        }

        $this->client->request('POST', $form->getUri(), $values);
    }

    private function associate(User $trainer, User $coach): TrainerCoachAssociation
    {
        $association = new TrainerCoachAssociation($trainer, $coach, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
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
}
