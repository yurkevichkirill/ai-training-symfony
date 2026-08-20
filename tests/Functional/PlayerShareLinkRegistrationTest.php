<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountEvent;
use App\Entity\PlayerShareLink;
use App\Entity\ProfilePlayer;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
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
 * Player self-registration via a ShareLink, reached anonymously
 * (AC-6, AC-7, AC-8, AC-9, AC-10). `PlayerShareLinkController::register()`
 * and `PlayerRegistrationService::registerViaShareLink()`'s correctness is
 * exercised end to end through the real `/join/{code}/register` route, the
 * same way `TrainerOnboardingFlowTest` proves its services through HTTP.
 */
final class PlayerShareLinkRegistrationTest extends WebTestCase
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

    /**
     * Deliberately no wrapping transaction -- same reason as
     * `TrainerOnboardingFlowTest`: this flow creates a `User` via a real
     * HTTP request and `AccountEventRecorder` records against it through its
     * own independent physical connection, which must see the committed row.
     * `profile`/`profile_player`/`player_share_link`/
     * `trainer_player_association` all cascade from `app_user` on delete
     * (migration `Version20260820095413`); only `account_event` needs an
     * explicit delete first, same as every other S1/S2/S3 flow test.
     */
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
     * @see AC-6 usage is attributed to the specific link and its tally
     * @see AC-7 the anonymous landing redirects straight to the form
     * @see AC-8 exactly one account, one association naming the right
     *      trainer, and the trainer's roster shows the player
     * @see AC-9 exactly one confirmation email is queued
     */
    public function testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $link = $this->createLinkFor($trainer);

        // AC-7: anonymous GET /join/{code} redirects straight to the
        // registration form, never the association path.
        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseRedirects('/join/'.$link->getCode().'/register');

        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        self::assertResponseIsSuccessful();

        $email = UserFactory::email('share-link-player');
        $this->client->submit($crawler->selectButton('Create account')->form([
            'player_share_link_registration_form[firstName]' => 'Alex',
            'player_share_link_registration_form[lastName]' => 'Player',
            'player_share_link_registration_form[email]' => $email,
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[phone]' => '+1 555-000-2222',
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]));

        // Looked up and queued for cleanup *before* any assertion below --
        // Task 18's registration write commits the User row in its own
        // transaction ahead of the confirmation-email dispatch (see this
        // service's own docblock), so a later assertion failure here must
        // still not leave the row behind for a subsequent run to collide
        // with (UserFactory::email()'s counter is deterministic per
        // process).
        $player = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $player) {
            $this->persistedIds[] = (string) $player->getId();
        }

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Check your email');

        self::assertInstanceOf(User::class, $player, 'Exactly one User row must exist for the submitted email.');
        self::assertSame(UserRole::PLAYER, $player->getRole());

        $profile = $this->em->getRepository(ProfilePlayer::class)->findOneBy(['user' => $player]);
        self::assertInstanceOf(ProfilePlayer::class, $profile, 'Exactly one ProfilePlayer must be created.');
        self::assertSame('Junior Player', $profile->getPlayerName());

        // AC-8: the association names the trainer who owns the link the
        // player actually followed -- attributable, not ambiguous.
        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        $association = $associationRepository->findOneFor($trainer, $player);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $association);
        self::assertSame($link->getId()->toRfc4122(), $association->getShareLink()?->getId()->toRfc4122());

        // AC-6: usage tally moves with the new association.
        $this->em->clear();
        $reloadedLink = $this->em->getRepository(PlayerShareLink::class)->find($link->getId());
        self::assertInstanceOf(PlayerShareLink::class, $reloadedLink);
        self::assertSame(1, $reloadedLink->getUsageCount());

        // AC-8: the trainer can now see the player in their roster.
        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/players');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Junior Player');
        self::assertSelectorTextContains('body', $email);

        // AC-9: exactly one confirmation email, sent to the address supplied.
        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_PLAYER_WELCOME, $mail->template);
        self::assertSame($email, $mail->to);

        // Task 39 coverage gap: PLAYER_REGISTERED_VIA_SHARE_LINK was never
        // asserted anywhere in this slice's tests. PlayerRegistrationService::
        // registerViaShareLink() records it post-commit, actor = subject =
        // the new player -- see AccountEventType's own docblock.
        $accountEvents = $this->em->getRepository(AccountEvent::class)->findBy(['subjectUser' => $player]);
        self::assertCount(1, $accountEvents, 'Exactly one AccountEvent must be recorded for the new player.');
        $accountEvent = $accountEvents[0];
        self::assertSame(AccountEventType::PLAYER_REGISTERED_VIA_SHARE_LINK->value, $accountEvent->getType());
        self::assertSame($player->getId()->toRfc4122(), $accountEvent->getActorUser()?->getId()->toRfc4122());
        self::assertSame($player->getId()->toRfc4122(), $accountEvent->getSubjectUser()->getId()->toRfc4122());

        // Sign-in stays refused until the address is verified.
        $this->client->getCookieJar()->clear();
        $this->assertSignInFails($player);
    }

    /**
     * @see AC-10 amended (Task 35, enumeration resistance): a duplicate
     *      email gets the exact same "check your email" success response a
     *      genuine registration gets -- never a field-level error naming
     *      the address -- never a duplicate account, never an unhandled
     *      failure, and the existing account is notified instead of the
     *      prober.
     */
    public function testRegisteringWithAnEmailAlreadyInUseGetsTheSameSuccessResponseAndNotifiesTheExistingAccountAc10(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $existing = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');

        $this->client->submit($crawler->selectButton('Create account')->form([
            'player_share_link_registration_form[email]' => $existing->getEmail(),
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]));

        // Byte-identical to the genuine-registration success response --
        // the whole point is that a prober cannot tell the two apart.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Check your email');
        self::assertSelectorNotExists('.error-summary');

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM app_user WHERE email = :email',
            ['email' => $existing->getEmail()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'A duplicate-email submission must never leave an orphan User row behind.');

        // No association or usage-count change either -- the whole write
        // must be all-or-nothing.
        $this->em->clear();
        $reloadedLink = $this->em->getRepository(PlayerShareLink::class)->find($link->getId());
        self::assertInstanceOf(PlayerShareLink::class, $reloadedLink);
        self::assertSame(0, $reloadedLink->getUsageCount());

        // The existing account, not the prober, learns about the attempt.
        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_DUPLICATE_REGISTRATION_ATTEMPT, $mail->template);
        self::assertSame($existing->getEmail(), $mail->to);
    }

    /**
     * Task 39 coverage gap, completing Task 35's own partial coverage above
     * (same status code and body text, checked field by field): a prober
     * comparing the two responses byte for byte must find no difference at
     * all -- not even in headers a naive comparison might miss.
     * `share_link/register_check_email.html.twig` renders no per-request
     * dynamic content (no CSRF field, no flash message, no echoed input), so
     * a genuine new registration and a duplicate-email attempt render the
     * exact same static markup through `base.html.twig`.
     */
    public function testDuplicateAndNovelEmailRegistrationResponsesAreByteIdenticalAc10(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $existing = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $link = $this->createLinkFor($trainer);

        $duplicateCrawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        $this->client->submit($duplicateCrawler->selectButton('Create account')->form([
            'player_share_link_registration_form[email]' => $existing->getEmail(),
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]));
        $duplicateResponse = $this->client->getResponse();
        self::assertStringContainsString('Check your email', (string) $duplicateResponse->getContent(), 'Precondition failed: the duplicate-email submission did not reach the success page.');
        $duplicateBody = (string) $duplicateResponse->getContent();
        $duplicateStatus = $duplicateResponse->getStatusCode();

        $novelEmail = UserFactory::email('share-link-novel');
        $novelCrawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        $this->client->submit($novelCrawler->selectButton('Create account')->form([
            'player_share_link_registration_form[email]' => $novelEmail,
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]));
        $novelResponse = $this->client->getResponse();
        self::assertStringContainsString('Check your email', (string) $novelResponse->getContent(), 'Precondition failed: the novel-email submission did not reach the success page.');
        $novelBody = (string) $novelResponse->getContent();
        $novelStatus = $novelResponse->getStatusCode();

        $novelPlayer = $this->em->getRepository(User::class)->findOneBy(['email' => $novelEmail]);
        if (null !== $novelPlayer) {
            $this->persistedIds[] = (string) $novelPlayer->getId();
        }

        self::assertSame($novelStatus, $duplicateStatus, 'A prober must not be able to distinguish the two cases by status code.');
        self::assertSame($novelBody, $duplicateBody, 'A prober must not be able to distinguish the two cases by response body -- they must be byte-identical.');
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->getOrCreateFor($trainer);
    }

    private function assertSignInFails(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects('/login');
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
