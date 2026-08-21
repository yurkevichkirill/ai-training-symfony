<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlayerAvailabilitySlot;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Task 35 (AC-22, AC-23, AC-24): `Trainer\PlayerRosterController::index()`'s
 * "Best Times" summary on every card, and the optional day/time filter that
 * narrows the roster to only players available at that moment.
 */
final class TrainerPlayerRosterAvailabilityTest extends WebTestCase
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

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * @see AC-22 a "Best Times" summary appears on the roster card
     */
    public function testUnfilteredRosterShowsAvailabilitySummaryAc22(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('avail-player')));
        $this->connect($trainer, $player);

        $this->persistSlot($player, 1, 17 * 60, 20 * 60); // Mon 5-8pm

        $this->client->loginUser($trainer);
        $this->client->request('GET', '/trainer/players');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mon 5-8pm');
    }

    /**
     * @see AC-24 no slot at all means "Not available"
     */
    public function testAPlayerWithNoSlotsShowsNotAvailable(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('no-avail')));
        $this->connect($trainer, $player);

        $this->client->loginUser($trainer);
        $this->client->request('GET', '/trainer/players');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Not available');
    }

    /**
     * @see AC-23 the filter narrows the roster to only players available at
     *      the chosen day/time
     */
    public function testFilteringByDayAndTimeNarrowsTheRosterAc23(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $availablePlayer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('filter-avail')));
        $unavailablePlayer = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('filter-unavail')));
        $this->connect($trainer, $availablePlayer);
        $this->connect($trainer, $unavailablePlayer);

        $this->persistSlot($availablePlayer, 1, 17 * 60, 20 * 60); // Mon 5-8pm

        $this->client->loginUser($trainer);
        $this->client->request('GET', '/trainer/players', [
            'dayOfWeek' => '1',
            'time' => '18:00',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $availablePlayer->getEmail());
        self::assertSelectorTextNotContains('body', $unavailablePlayer->getEmail());
    }

    private function connect(User $trainer, User $player): void
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);
        $service->associateWithTrainer($player, $trainer, null);
    }

    private function persistSlot(User $player, int $dayOfWeek, int $startsAtMinute, int $endsAtMinute): void
    {
        $this->em->persist(new PlayerAvailabilitySlot($player, $dayOfWeek, $startsAtMinute, $endsAtMinute));
        $this->em->flush();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
