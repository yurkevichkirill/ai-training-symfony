<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountInvitation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Service\AccountInvitationService;
use App\Service\CreateTrainerRequest;
use App\Service\TrainerOnboardingService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-01.01, US-01.02's trainer-invitation half (AC-4, AC-5, AC-6, AC-7,
 * AC-8, AC-9). `TrainerOnboardingService` and `AccountInvitationService`'s
 * unit-level correctness is exercised end to end here via the actual HTTP
 * routes, the same way S1's flow tests prove PasswordResetService/
 * EmailVerificationTokenService through their controllers.
 */
final class TrainerOnboardingFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);
    }

    /**
     * Deliberately no wrapping transaction here (unlike most S1 tests): this
     * flow creates a user via a real HTTP request and then, in the *same*
     * request, records an `AccountEvent` referencing it through
     * `AccountEventRecorder`'s own independent physical connection (see that
     * class's docblock). If the user row lived only inside an outer,
     * never-committed test transaction, that second connection could not see
     * it yet and the FK insert would fail -- a real production request has
     * no such outer transaction, so this test must not introduce one either.
     * Every row this test creates is cleaned up by email instead
     * (`profile`/`profile_trainer`/`account_invitation` cascade from
     * `app_user` on delete; only `account_event` needs an explicit delete
     * first, same as S1's `auth_event` cleanup).
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id IN (SELECT id FROM app_user WHERE email = :email) OR actor_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testSuperAdminCreatesTrainerAndTrainerActivatesViaInvitation(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->signIn($admin);

        $email = UserFactory::email('trainer');

        $this->client->request('GET', '/admin/users/create');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Create trainer')->form([
            'create_trainer_form[email]' => $email,
            'create_trainer_form[businessName]' => 'Elite Basketball Academy',
            'create_trainer_form[firstName]' => 'Dana',
            'create_trainer_form[lastName]' => 'Trainer',
        ]));

        self::assertResponseRedirects('/admin/users');
        $this->persistedEmails[] = $email;

        $trainer = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $trainer);
        self::assertSame(UserRole::TRAINER, $trainer->getRole());
        self::assertFalse($trainer->isEmailVerified(), 'Not verified until the invitation is consumed.');

        $invitation = $this->em->getRepository(AccountInvitation::class)->findOneBy(['user' => $trainer]);
        self::assertInstanceOf(AccountInvitation::class, $invitation);

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_TRAINER_INVITATION, $mail->template);
        $token = $mail->context['token'];
        self::assertIsString($token);

        // Consuming the invitation: new session, not the admin's.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitations/'.$token);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Set password')->form([
            'change_password_form[plainPassword][first]' => UserFactory::PASSWORD,
            'change_password_form[plainPassword][second]' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects('/login');

        $this->em->clear();
        $trainer = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $trainer);
        self::assertTrue($trainer->isEmailVerified(), 'Consuming the invitation also verifies the email (AC-5).');

        // Not auto-signed-in: the trainer must sign in through the ordinary
        // audited form_login path.
        $this->signIn($trainer);
    }

    public function testCreatingATrainerWithADuplicateEmailIsRefusedWithAFieldError(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $existing = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $this->client->request('GET', '/admin/users/create');
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Create trainer')->form([
            'create_trainer_form[email]' => $existing->getEmail(),
            'create_trainer_form[businessName]' => 'Duplicate Co',
        ]));

        // 422, not a redirect and not a 500: a duplicate email re-renders
        // the form with a field-level error (modern Symfony Form's default
        // status for an invalid submission).
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.error-summary', 'already exists');
    }

    /**
     * H-code-quality gap #1: `UserAccountService::create()` commits `$user`
     * in its own, separate transaction before `createTrainer()` opens a
     * second one for the `ProfileTrainer`/`AccountInvitation` pair -- a
     * genuine failure in that second transaction must not leave the
     * already-committed `User` row behind as an orphan (unusable
     * placeholder password, no profile, no invitation, no email sent, and
     * unreachable through the UI). `ProfileTrainer::$businessName` is
     * `#[ORM\Column(length: 160)]`; a 161-character value reproduces a real
     * Postgres "value too long for type character varying(160)" failure
     * inside the second transaction. `createTrainer()` is called directly,
     * not through the HTTP form, which would validate the length first and
     * never reach the database.
     */
    public function testAFailureInTheSecondTransactionDoesNotOrphanTheUserRow(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $email = UserFactory::email('trainer-orphan');

        /** @var TrainerOnboardingService $service */
        $service = self::getContainer()->get(TrainerOnboardingService::class);
        $request = new CreateTrainerRequest($email, str_repeat('a', 161));

        try {
            $service->createTrainer($request, $admin);
            self::fail('Expected the oversized businessName to fail the second transaction at the database level.');
        } catch (\Throwable) {
            // Expected: a real Doctrine DBAL exception wrapping Postgres's
            // "value too long for type character varying(160)" error.
        }

        // The catch's compensating cleanup resets the registry's manager
        // (the closed-EntityManager pitfall this class's docblock and
        // UserAccountService's both document) -- $this->em from setUp() is
        // now that closed instance, so a fresh one is needed to query.
        $doctrine = self::getContainer()->get('doctrine');
        $manager = $doctrine->getManagerForClass(User::class);
        if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
            $manager = $doctrine->resetManager();
        }
        \assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;

        $orphan = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNull($orphan, 'The User row committed by the first transaction must not survive a failure in the second.');

        $this->persistedEmails[] = $email;
    }

    public function testOnlyASuperAdminCanReachTrainerCreation(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $this->client->request('GET', '/admin/users/create');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnInvalidInvitationTokenIsRefused(): void
    {
        $this->client->request('GET', '/invitations/not-a-real-token');
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Set password')->form([
            'change_password_form[plainPassword][first]' => UserFactory::PASSWORD,
            'change_password_form[plainPassword][second]' => UserFactory::PASSWORD,
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'invalid');
    }

    public function testAnAlreadyConsumedInvitationCannotBeReplayed(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeUnverified(UserRole::TRAINER));

        /** @var AccountInvitationService $invitationService */
        $invitationService = self::getContainer()->get(AccountInvitationService::class);
        $token = $this->issueInvitation($trainer, $admin);

        $invitationService->consume($token, UserFactory::PASSWORD);

        $this->client->request('GET', '/invitations/'.$token);
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Set password')->form([
            'change_password_form[plainPassword][first]' => 'a-different-password-123',
            'change_password_form[plainPassword][second]' => 'a-different-password-123',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'already been used');
    }

    private function issueInvitation(User $trainer, User $actor): string
    {
        $factory = self::getContainer()->get(\App\Service\SelectorVerifierTokenFactory::class);
        $pair = $factory->generate();

        $invitation = new AccountInvitation(
            $trainer,
            $actor,
            $pair->selector,
            $pair->hashedVerifier,
            new \DateTimeImmutable('+7 days'),
        );

        $this->em->persist($invitation);
        $this->em->flush();

        return $pair->token;
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
        $this->persistedEmails[] = $user->getEmail();

        return $user;
    }
}
