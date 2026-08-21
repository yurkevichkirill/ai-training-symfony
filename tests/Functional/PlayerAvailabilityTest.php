<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerAvailabilitySlot;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 43 (AC-19...AC-24): the weekly availability grid and the trainer
 * roster's read side, driven through the real HTTP form layer --
 * `Player\AvailabilityController`/`Trainer\PlayerRosterController` -- rather
 * than `AvailabilityServiceTest`'s direct service calls.
 */
final class PlayerAvailabilityTest extends WebTestCase
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

        $connection->executeStatement('DELETE FROM player_availability_slot');
        $connection->executeStatement('DELETE FROM trainer_player_association');
        $connection->executeStatement('DELETE FROM child_account');

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * AC-19: setting a specific range on one day and "Not Available" (no
     * range at all) on another, then reading them back through the grid.
     */
    public function testSettingRangesAndNotAvailableDaysAndReadingThemBackAc19(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-http-player')));
        $this->signIn($player);

        $crawler = $this->client->request('GET', '/player/availability');
        self::assertResponseIsSuccessful();

        $this->submitAvailabilityForm($crawler, [1 => ['start' => '17:00', 'end' => '20:00']]);
        self::assertResponseRedirects('/player/availability');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $slots = $this->em->getRepository(PlayerAvailabilitySlot::class)->findBy(['player' => $player]);
        self::assertCount(1, $slots, 'AC-19: exactly the one submitted range is stored.');
        self::assertSame(1, $slots[0]->getDayOfWeek());
        self::assertSame(17 * 60, $slots[0]->getStartsAtMinute());
        self::assertSame(20 * 60, $slots[0]->getEndsAtMinute());

        // AC-24: every other day, never submitted, is "Not Available" -- zero
        // rows.
        foreach ([2, 3, 4, 5, 6, 7] as $day) {
            $rowsForDay = $this->em->getConnection()->executeQuery(
                'SELECT COUNT(*) FROM player_availability_slot WHERE player_id = :player AND day_of_week = :day',
                ['player' => (string) $player->getId(), 'day' => $day],
            )->fetchOne();
            self::assertSame(0, (int) $rowsForDay);
        }
    }

    /**
     * AC-21: after saving, the confirmation names that trainers can see
     * these preferences.
     */
    public function testPostSaveConfirmationNamesTrainersAc21(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-confirm-player')));
        $this->signIn($player);

        $crawler = $this->client->request('GET', '/player/availability');
        $this->submitAvailabilityForm($crawler, [2 => ['start' => '09:00', 'end' => '10:00']]);
        self::assertResponseRedirects('/player/availability');

        $session = $this->client->getRequest()->getSession();
        $flashes = $session->getFlashBag()->peek('success');
        self::assertNotEmpty($flashes, 'AC-21: a success flash must be queued after saving.');
        self::assertStringContainsString('trainers', $flashes[0]);
    }

    /**
     * AC-20: a parent switches between self and two children; each save
     * leaves the other two grids completely untouched.
     */
    public function testParentSwitchingBetweenSelfAndTwoChildrenEachSaveLeavesTheOthersUntouchedAc20(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-parent-switch')));
        $childA = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-child-a')));
        $childB = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-child-b')));
        $this->persistChildAccount($childA, $parent);
        $this->persistChildAccount($childB, $parent);

        $this->signIn($parent);

        $selfCrawler = $this->client->request('GET', '/player/availability');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav[aria-label="Switch player"]');

        $this->submitAvailabilityForm($selfCrawler, [1 => ['start' => '08:00', 'end' => '09:00']]);
        self::assertResponseRedirects('/player/availability');

        $childACrawler = $this->client->request('GET', '/player/availability?player='.$childA->getId());
        self::assertResponseIsSuccessful();
        $this->submitAvailabilityForm($childACrawler, [3 => ['start' => '14:00', 'end' => '15:00']]);
        self::assertResponseRedirects('/player/availability?player='.$childA->getId());

        $childBCrawler = $this->client->request('GET', '/player/availability?player='.$childB->getId());
        self::assertResponseIsSuccessful();
        $this->submitAvailabilityForm($childBCrawler, [5 => ['start' => '16:00', 'end' => '17:00']]);
        self::assertResponseRedirects('/player/availability?player='.$childB->getId());

        // Each save must have touched only its own subject's rows.
        $parentSlots = $this->em->getRepository(PlayerAvailabilitySlot::class)->findBy(['player' => $parent]);
        self::assertCount(1, $parentSlots);
        self::assertSame(1, $parentSlots[0]->getDayOfWeek());

        $childASlots = $this->em->getRepository(PlayerAvailabilitySlot::class)->findBy(['player' => $childA]);
        self::assertCount(1, $childASlots);
        self::assertSame(3, $childASlots[0]->getDayOfWeek());

        $childBSlots = $this->em->getRepository(PlayerAvailabilitySlot::class)->findBy(['player' => $childB]);
        self::assertCount(1, $childBSlots);
        self::assertSame(5, $childBSlots[0]->getDayOfWeek());
    }

    /**
     * Edge case: a parent with no children sees no switcher control at all.
     */
    public function testAParentWithNoChildrenSeesNoSwitcher(): void
    {
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-no-children')));
        $this->signIn($player);

        $this->client->request('GET', '/player/availability');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('nav[aria-label="Switch player"]');
    }

    /**
     * AC-18/AC-20 defence-in-depth: a parent cannot edit another family's
     * child's availability by guessing the id in the query string.
     */
    public function testAParentCannotEditAnotherFamilysChildAvailability(): void
    {
        $owner = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-owner')));
        $intruder = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-intruder')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-owned-child')));
        $this->persistChildAccount($childUser, $owner);

        $this->signIn($intruder);
        $this->client->request('GET', '/player/availability?player='.$childUser->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * AC-22: the trainer roster card shows the "Best Times" summary string.
     * AC-23: the day/time filter matches an adult and a child player the
     * same way. AC-24: a player with no rows for the filtered day never
     * matches.
     */
    public function testTrainerRosterShowsSummaryAndFilterMatchesAdultAndChildTheSameWayAc22Ac23Ac24(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('avail-trainer')));
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-roster-parent')));
        $adultPlayer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-roster-adult')));
        $childPlayer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-roster-child')));
        $unavailablePlayer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-roster-unavailable')));
        $this->persistChildAccount($childPlayer, $parent);

        $this->connectPlayer($trainer, $adultPlayer);
        $this->connectPlayer($trainer, $childPlayer);
        $this->connectPlayer($trainer, $unavailablePlayer);

        $this->persistSlot($adultPlayer, 1, 17 * 60, 20 * 60); // Mon 5-8pm
        $this->persistSlot($childPlayer, 1, 18 * 60, 19 * 60); // Mon 6-7pm

        $this->signIn($trainer);

        $unfiltered = $this->client->request('GET', '/trainer/players');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mon 5-8pm');

        $filtered = $this->client->request('GET', '/trainer/players', ['dayOfWeek' => '1', 'time' => '18:30']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $adultPlayer->getEmail());
        self::assertSelectorTextContains('body', $childPlayer->getDisplayName());
        self::assertSelectorTextNotContains('body', $unavailablePlayer->getEmail());
    }

    /**
     * `AvailabilityWeekFormType` renders zero range rows per day on a fresh
     * grid (canonical "Not Available" is zero rows, D5) -- `allow_add`
     * range entries have no rendered field for the crawler's `Form` object
     * to bind to, so this submits the form's own current field values
     * (which already include the CSRF token) merged with the one range per
     * day this test wants to add, exactly as the progressive-enhancement
     * "add a range" JS would produce.
     *
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

    private function persistSlot(User $player, int $dayOfWeek, int $startsAtMinute, int $endsAtMinute): void
    {
        $this->em->persist(new PlayerAvailabilitySlot($player, $dayOfWeek, $startsAtMinute, $endsAtMinute));
        $this->em->flush();
    }

    private function connectPlayer(User $trainer, User $player): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
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
