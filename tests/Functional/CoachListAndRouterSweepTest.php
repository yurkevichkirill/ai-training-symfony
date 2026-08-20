<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoachInvitation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Service\CoachInvitationRequest;
use App\Service\CoachInvitationService;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 27: the trainer's Coaches list rendering Pending/Accepted/Expired
 * correctly (AC-17); a targeted confirmation that S3's new routes
 * (`/join*`, `/coach-invitation*`, `/trainer/coaches*`) sit correctly on
 * `RouterSweepTest`'s public-vs-protected line (AC-1, AC-3, AC-14) --
 * `RouterSweepTest` itself is deliberately generic (it walks the actual
 * router, not a hand-written list) so it already exercises these routes the
 * moment they exist, with no edit needed there; this file adds a route-
 * specific assertion alongside it, per this task's own text; and CSRF
 * rejection (stripped and altered token) on every state-changing form this
 * slice introduces -- the coach-invite form, the coach registration form,
 * and (parity, not exercised by any Task 22-24 file) the player
 * registration form -- following `CsrfProtectionTest`'s exact pattern and
 * rationale for why a *short* forged token is what actually gets rejected
 * by `SameOriginCsrfTokenManager` (see that class's docblock).
 */
final class CoachListAndRouterSweepTest extends WebTestCase
{
    /**
     * Deliberately short -- see `CsrfProtectionTest`'s class docblock for
     * exactly why a long forged value would not actually be rejected by
     * `SameOriginCsrfTokenManager`. Every form in this file shares the same
     * `submit` stateless token id (`config/packages/csrf.yaml`).
     */
    private const ALTERED_CSRF_TOKEN = 'not-a-real-csrf-token';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;
    private CoachInvitationService $invitationService;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);
        $this->invitationService = self::getContainer()->get(CoachInvitationService::class);
    }

    /**
     * Deliberately no wrapping transaction -- same reason as every other S3
     * flow test in this suite (`AccountEventRecorder`'s own physical
     * connection). `coach_invitation`/`trainer_coach_association`/
     * `player_share_link` all cascade from `app_user` on delete; only
     * `account_event` needs an explicit delete first.
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

    // --- AC-17: the Coaches list -------------------------------------------

    /**
     * @see AC-17 a trainer sees, for every coach invitation they have sent,
     *      whether it is Pending, Accepted, or Expired
     */
    public function testCoachesListShowsPendingAcceptedAndExpiredCorrectlyAc17(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $pendingEmail = UserFactory::email('pending-coach');
        $this->invitationService->invite(new CoachInvitationRequest($pendingEmail), $trainer);

        $acceptedCoach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('accepted-coach')));
        $acceptedToken = $this->inviteAndGetToken($trainer, $acceptedCoach->getEmail());
        $this->invitationService->accept($acceptedToken, $acceptedCoach);

        $expiredEmail = UserFactory::email('expired-coach');
        $expiredToken = $this->inviteAndGetToken($trainer, $expiredEmail);
        $this->em->getConnection()->executeStatement(
            'UPDATE coach_invitation SET expires_at = :expiresAt WHERE selector = :selector',
            ['expiresAt' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:sO'), 'selector' => substr($expiredToken, 0, 12)],
        );
        // Bypasses the ORM -- clear the identity map so the page's own
        // query re-reads the now-expired row instead of the
        // already-loaded, still-unexpired in-memory object.
        $this->em->clear();

        $this->signIn($trainer);
        $crawler = $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table')->eq(1)->filter('tbody tr');
        $statusByEmail = [];

        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            $statusByEmail[$cells->item(0)->textContent] = $cells->item(2)->textContent;
        }

        self::assertSame('Pending', $statusByEmail[$pendingEmail] ?? null);
        self::assertSame('Accepted', $statusByEmail[$acceptedCoach->getEmail()] ?? null);
        self::assertSame('Expired', $statusByEmail[$expiredEmail] ?? null);

        // AC-15/AC-17's accepted coach also shows up on the active roster,
        // separately from the invitation-history table.
        self::assertSelectorTextContains('body', 'Active coaches');
        $activeTable = $crawler->filter('table')->eq(0);
        self::assertStringContainsString($acceptedCoach->getEmail(), $activeTable->text());
    }

    // --- AC-1, AC-3, AC-14: router-sweep confirmation for the new routes ---

    /**
     * `RouterSweepTest` already walks every registered route generically,
     * so it exercises these the moment they exist -- this test names them
     * explicitly, per this task's own text, rather than editing that
     * generic sweep. Confirms both directions: the two public landing pages
     * refuse *for a business reason* (unknown code/token -> 404), never for
     * lacking a session, while the trainer-only routes behind them refuse
     * an anonymous visitor outright.
     */
    public function testJoinAndCoachInvitationRoutesArePublicWhileTrainerCoachRoutesAreNot(): void
    {
        $this->client->request('GET', '/join/does-not-exist-0123456789');
        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
            'AC-1: /join/{code} is on the PUBLIC_ACCESS allow-list -- an unknown code is refused for being unknown, never for lacking a session.',
        );

        $this->client->request('GET', '/coach-invitation/does-not-exist-0123456789token');
        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
            'AC-3, AC-14: /coach-invitation/{token} is on the PUBLIC_ACCESS allow-list -- an unknown token is refused for being unknown, never for lacking a session.',
        );

        $this->client->request('GET', '/trainer/coaches');
        self::assertResponseRedirects('/login', null, 'app_trainer_coaches is not on the allow-list -- an anonymous visitor must be sent to sign in.');

        $this->client->request('POST', '/trainer/coaches/invite');
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN],
            'app_trainer_coach_invite is not on the allow-list either.',
        );
    }

    // --- CSRF: the coach-invite form ----------------------------------------

    public function testCoachInviteFormWithCsrfTokenStrippedIsRefusedAndSendsNoInvitation(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $email = UserFactory::email('csrf-stripped-invite');
        $crawler = $this->client->request('GET', '/trainer/coaches');
        $form = $crawler->selectButton('Send invitation')->form([
            'coach_invitation_form[email]' => $email,
        ]);
        $form->remove('coach_invitation_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoInvitationWasCreatedFor($email);
    }

    public function testCoachInviteFormWithCsrfTokenAlteredIsRefusedAndSendsNoInvitation(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $email = UserFactory::email('csrf-altered-invite');
        $crawler = $this->client->request('GET', '/trainer/coaches');
        $form = $crawler->selectButton('Send invitation')->form([
            'coach_invitation_form[email]' => $email,
        ]);
        $form['coach_invitation_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoInvitationWasCreatedFor($email);
    }

    // --- CSRF: the coach registration form ----------------------------------

    public function testCoachRegistrationFormWithCsrfTokenStrippedIsRefusedAndCreatesNoAccount(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $email = UserFactory::email('csrf-stripped-coach-register');
        $token = $this->inviteAndGetToken($trainer, $email);

        $crawler = $this->client->request('GET', '/coach-invitation/'.$token);
        $form = $crawler->selectButton('Create account')->form([
            'coach_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'coach_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
        ]);
        $form->remove('coach_registration_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoUserExistsFor($email);
        $this->assertInvitationNotAccepted($token);
    }

    public function testCoachRegistrationFormWithCsrfTokenAlteredIsRefusedAndCreatesNoAccount(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $email = UserFactory::email('csrf-altered-coach-register');
        $token = $this->inviteAndGetToken($trainer, $email);

        $crawler = $this->client->request('GET', '/coach-invitation/'.$token);
        $form = $crawler->selectButton('Create account')->form([
            'coach_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'coach_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
        ]);
        $form['coach_registration_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoUserExistsFor($email);
        $this->assertInvitationNotAccepted($token);
    }

    // --- CSRF: the player registration form (parity) ------------------------

    /**
     * Parity with the coach registration form above -- not exercised by any
     * Task 22-24 file (`PlayerShareLinkRegistrationTest` only exercises the
     * valid-submission and duplicate-email paths).
     */
    public function testPlayerRegistrationFormWithCsrfTokenStrippedIsRefusedAndCreatesNoAccount(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $link = self::getContainer()->get(PlayerShareLinkService::class)->getOrCreateFor($trainer);

        $email = UserFactory::email('csrf-stripped-player-register');
        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        $form = $crawler->selectButton('Create account')->form([
            'player_share_link_registration_form[email]' => $email,
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]);
        $form->remove('player_share_link_registration_form[_token]');

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoUserExistsFor($email);
    }

    public function testPlayerRegistrationFormWithCsrfTokenAlteredIsRefusedAndCreatesNoAccount(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $link = self::getContainer()->get(PlayerShareLinkService::class)->getOrCreateFor($trainer);

        $email = UserFactory::email('csrf-altered-player-register');
        $crawler = $this->client->request('GET', '/join/'.$link->getCode().'/register');
        $form = $crawler->selectButton('Create account')->form([
            'player_share_link_registration_form[email]' => $email,
            'player_share_link_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'player_share_link_registration_form[playerName]' => 'Junior Player',
            'player_share_link_registration_form[playerAge]' => '9',
            'player_share_link_registration_form[playerGender]' => 'MALE',
        ]);
        $form['player_share_link_registration_form[_token]'] = self::ALTERED_CSRF_TOKEN;

        $this->client->submit($form);

        $this->assertFormRejectedForInvalidCsrf();
        $this->assertNoUserExistsFor($email);
    }

    // --- shared helpers ------------------------------------------------------

    /**
     * Same shape every Symfony-Form-based CSRF rejection in this project
     * takes (`CsrfProtectionTest`'s own helper): 422, and the shared
     * `_error_summary.html.twig` rendering of the form-level CSRF error.
     */
    private function assertFormRejectedForInvalidCsrf(): void
    {
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The CSRF token is invalid', (string) $response->getContent());
    }

    private function assertNoInvitationWasCreatedFor(string $email): void
    {
        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM coach_invitation WHERE invited_email = :email',
            ['email' => $email],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'A CSRF-rejected invitation submission must never create a row.');
        self::assertNull($this->mailHandler->last(), 'A CSRF-rejected invitation submission must never queue a mail.');
    }

    private function assertNoUserExistsFor(string $email): void
    {
        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM app_user WHERE email = :email',
            ['email' => $email],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'A CSRF-rejected registration must never create a User row.');
    }

    private function assertInvitationNotAccepted(string $token): void
    {
        $this->em->clear();
        $invitation = $this->em->getRepository(CoachInvitation::class)->findOneBy(['selector' => substr($token, 0, 12)]);
        self::assertInstanceOf(CoachInvitation::class, $invitation);
        self::assertFalse($invitation->isAccepted(), 'A CSRF-rejected registration must never mark the invitation accepted.');
    }

    private function inviteAndGetToken(User $trainer, string $email): string
    {
        $this->invitationService->invite(new CoachInvitationRequest($email), $trainer);

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail, 'Precondition failed: invite() did not queue a mail.');
        $token = $mail->context['token'];
        self::assertIsString($token);

        return $token;
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
