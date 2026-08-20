<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Task 39 coverage gap: Task 36's "leave this trainer" action
 * (`Player\TrainerRosterController::leave()`, `PlayerShareLinkService::leave()`)
 * and the leave/rejoin cycle it enables had no test coverage at all before
 * this file -- neither the route, nor the AC-11-amendment guarantee that a
 * rejoin creates a genuinely new `TrainerPlayerAssociation` row rather than
 * resurrecting the ended one, nor that AC-12 (a player's other, still-active
 * associations are never touched by leaving one trainer) still holds once
 * leaving is possible.
 *
 * Deliberately no wrapping transaction -- same reason as
 * `PlayerShareLinkAssociationTest`: `AccountEventRecorder` records through
 * its own independent physical connection, which must see already-committed
 * rows.
 */
final class PlayerTrainerRosterLeaveTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * The full leave/rejoin cycle: leaving ends the association and hides
     * both sides' rosters immediately; rejoining the exact same trainer's
     * link creates a genuinely *new* row (different id, a fresh
     * `endedAt = NULL`) rather than resurrecting the ended one, tallies
     * `usageCount` again, restores both rosters, and sends the "you've been
     * connected" notification again -- exactly as a first-time join would,
     * because `associate()`'s idempotency pre-check (Task 36) now matches
     * only currently-active rows.
     */
    public function testLeavingATrainerThenRejoiningCreatesANewAssociationNotAResurrectedOne(): void
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

        // Roster shows the trainer right after joining.
        $rosterCrawler = $this->client->request('GET', '/player/trainers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $trainer->getDisplayName());

        // Leave: the roster's own "Leave" button, CSRF token included by the
        // real rendered form.
        $this->client->submit($rosterCrawler->selectButton('Leave')->form());
        self::assertResponseRedirects('/player/trainers');

        // Immediately after leaving: the player's own roster no longer
        // lists this trainer.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $trainer->getDisplayName());

        self::assertNull(
            $associationRepository->findOneFor($trainer, $player),
            'findOneFor() (active-only, Task 36) must no longer see the ended association.',
        );

        // Immediately after leaving: the trainer's own roster no longer
        // lists this player either.
        $this->client->getCookieJar()->clear();
        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/players');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $player->getEmail());

        // Rejoin: the player follows the exact same link again.
        $this->client->getCookieJar()->clear();
        $this->signIn($player);
        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseRedirects('/');

        $secondAssociation = $associationRepository->findOneFor($trainer, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $secondAssociation);
        self::assertNotSame(
            $firstAssociationId->toRfc4122(),
            $secondAssociation->getId()->toRfc4122(),
            'A rejoin must create a genuinely new association row, not resurrect the ended one.',
        );

        $this->em->clear();
        $reloadedFirst = $this->em->getRepository(TrainerPlayerAssociation::class)->find($firstAssociationId);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $reloadedFirst);
        self::assertNotNull($reloadedFirst->getEndedAt(), 'The first, left association must carry a non-null endedAt.');
        self::assertNull($secondAssociation->getEndedAt(), 'The new association must be currently active (endedAt IS NULL).');

        $totalCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $player->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $totalCount, 'Both the ended row and the new row must exist -- the ended one is history, not deleted.');

        // usageCount is tallied again on the genuinely new association.
        // Read via raw SQL, deliberately not `$em->getRepository(PlayerShareLink::class)->find()`:
        // `$reloadedFirst` above already populated the identity map with an
        // uninitialized `PlayerShareLink` proxy for this exact id (via
        // `TrainerPlayerAssociation::$shareLink`'s lazy to-one association),
        // and a second, real hydration of the same id collides with that
        // proxy's already-set readonly `$id` -- the same general "lazily-
        // loaded proxy of a readonly-id entity crashes on real hydration"
        // defect this project's own test-generator validation map already
        // documented for `User` proxies (not a Tasks 32-38 regression; see
        // this delegation's final report).
        $usageCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT usage_count FROM player_share_link WHERE id = :id',
            ['id' => $link->getId()->toRfc4122()],
        );
        self::assertSame(2, $usageCount, 'usageCount must increment again on a rejoin, exactly like a first-time join.');

        // Both rosters show the pair again.
        $this->client->request('GET', '/player/trainers');
        self::assertSelectorTextContains('body', $trainer->getDisplayName());

        $this->client->getCookieJar()->clear();
        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/players');
        self::assertSelectorTextContains('body', $player->getEmail());

        // The rejoin sends the "you've been connected" notification again --
        // this is a genuinely new association, not an idempotent no-op.
        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_PLAYER_TRAINER_CONNECTED, $mail->template);
        self::assertSame($player->getEmail(), $mail->to);
    }

    /**
     * AC-12, reconfirmed after Task 36's change: leaving Trainer A must never
     * touch the player's still-active association with Trainer B -- same
     * row id, same (unended) state, before and after.
     */
    public function testLeavingOneTrainerNeverTouchesAStillActiveAssociationWithAnotherTrainer(): void
    {
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b')));
        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $linkA = $this->createLinkFor($trainerA);
        $linkB = $this->createLinkFor($trainerB);

        $this->signIn($player);
        $this->client->request('GET', '/join/'.$linkA->getCode());
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/join/'.$linkB->getCode());
        self::assertResponseRedirects('/');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        $associationB = $associationRepository->findOneFor($trainerB, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $associationB);
        $associationBId = $associationB->getId();

        // Leave Trainer A only -- the roster lists a separate "Leave" form
        // per trainer, so the form for Trainer A specifically is picked out
        // by its action URL (which embeds the trainer's id) rather than by
        // button text, which would match both forms ambiguously.
        $rosterCrawler = $this->client->request('GET', '/player/trainers');
        $formForA = $rosterCrawler->filter('form[action*="'.$trainerA->getId()->toRfc4122().'"]');
        self::assertGreaterThan(0, $formForA->count(), 'Precondition failed: no "leave" form found for Trainer A on the roster page.');

        $action = (string) $formForA->attr('action');
        $token = (string) $formForA->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', $action, ['_token' => $token]);
        self::assertResponseRedirects('/player/trainers');

        // Trainer B's association must be completely untouched.
        $this->em->clear();
        $reloadedB = $this->em->getRepository(TrainerPlayerAssociation::class)->find($associationBId);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $reloadedB);
        self::assertNull($reloadedB->getEndedAt(), "Leaving Trainer A must never end the player's still-active association with Trainer B.");

        $stillActiveWithB = $associationRepository->findOneFor($trainerB, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $stillActiveWithB);
        self::assertSame($associationBId->toRfc4122(), $stillActiveWithB->getId()->toRfc4122());

        self::assertNull($associationRepository->findOneFor($trainerA, $player), 'Trainer A\'s association must now be ended.');

        // The roster now shows only Trainer B.
        $this->client->request('GET', '/player/trainers');
        self::assertSelectorTextContains('body', $trainerB->getDisplayName());
        self::assertSelectorTextNotContains('body', $trainerA->getDisplayName());
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
