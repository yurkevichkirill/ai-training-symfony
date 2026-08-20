<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CoachInvitation;
use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Security\IpTruncator;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Sending a coach invitation (AC-5, AC-19), and the trainer's own player
 * ShareLink page (AC-4) -- the latter untested by any Task 1-24 file despite
 * predating this slice's coach work, per Task 25's own grouping.
 * `Trainer\CoachController::invite()` and `Trainer\ShareLinkController::show()`
 * are exercised end to end through their real routes, the same way
 * `TrainerOnboardingFlowTest` proves its services through HTTP.
 */
final class CoachInvitationSendTest extends WebTestCase
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
     * `TrainerOnboardingFlowTest`/`PlayerShareLinkAssociationTest`:
     * `AccountEventRecorder` (not triggered by these particular tests, but
     * the trainer row itself is created via a real committed `persist()`)
     * writes through its own independent physical connection. `coach_invitation`
     * cascades from `app_user` on delete (both FKs `onDelete: 'CASCADE'`);
     * nothing else needs manual cleanup for this file's tests.
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
     * @see AC-19 email is the only required field -- an empty submission is
     *      refused with a field-level validation error, never reaching the
     *      service, and never creating a row or queuing a mail
     */
    public function testInvitingACoachWithNoEmailIsRefusedWithAValidationErrorAc19(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $crawler = $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Send invitation')->form([
            'coach_invitation_form[email]' => '',
        ]));

        // AbstractController::doRender() sets 422 automatically for a
        // submitted-and-invalid form found anywhere in the render context
        // (Symfony's generic scan, not keyed to the parameter name) --
        // the same mechanism CsrfProtectionTest's docblock documents for
        // the three Symfony-Form-based S1 routes.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.error-summary', 'blank');

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM coach_invitation WHERE trainer_id = :trainer',
            ['trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'A validation-rejected submission must never create an invitation row.');
        self::assertNull($this->mailHandler->last(), 'A validation-rejected submission must never queue an email.');
    }

    /**
     * @see AC-5 the generated invitation carries any supplied personal
     *      message, and the email is queued to the invited address
     */
    public function testInvitingACoachQueuesAnEmailCarryingThePersonalMessageAc5(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $email = UserFactory::email('invited-coach');

        $crawler = $this->client->request('GET', '/trainer/coaches');
        $this->client->submit($crawler->selectButton('Send invitation')->form([
            'coach_invitation_form[email]' => $email,
            'coach_invitation_form[name]' => 'Casey Coach',
            'coach_invitation_form[message]' => 'Looking forward to having you on the team!',
        ]));

        self::assertResponseRedirects('/trainer/coaches');

        $invitation = $this->em->getRepository(CoachInvitation::class)->findOneBy(['invitedEmail' => $email]);
        self::assertInstanceOf(CoachInvitation::class, $invitation);
        self::assertSame($trainer->getId()->toRfc4122(), $invitation->getTrainer()->getId()->toRfc4122());
        self::assertSame('Casey Coach', $invitation->getInvitedName());
        self::assertSame('Looking forward to having you on the team!', $invitation->getMessage());
        self::assertFalse($invitation->isAccepted());

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_COACH_INVITATION, $mail->template);
        self::assertSame($email, $mail->to);
        self::assertSame('Looking forward to having you on the team!', $mail->context['message']);
        self::assertSame($trainer->getDisplayName(), $mail->context['trainerName']);
        self::assertIsString($mail->context['token']);

        // The coaches page now lists the invitation as Pending.
        $this->client->request('GET', '/trainer/coaches');
        self::assertSelectorTextContains('body', $email);
        self::assertSelectorTextContains('body', 'Pending');
    }

    /**
     * @see AC-4 `Trainer\ShareLinkController::show()` is idempotent
     *      get-or-create: repeat visits never mint a second player
     *      ShareLink. Untested by any Task 1-24 file (grouped into this
     *      one per Task 25's own text).
     */
    public function testTrainerShareLinkPageIsIdempotentGetOrCreateAc4(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $this->client->request('GET', '/trainer/share-link');
        self::assertResponseIsSuccessful();

        $first = $this->em->getRepository(PlayerShareLink::class)->findOneBy(['trainer' => $trainer]);
        self::assertInstanceOf(PlayerShareLink::class, $first);
        $firstCode = $first->getCode();
        self::assertSelectorTextContains('body', $firstCode);

        // A second visit must reuse the exact same link, not mint another.
        $this->client->request('GET', '/trainer/share-link');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $firstCode);

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM player_share_link WHERE trainer_id = :trainer',
            ['trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'Repeat visits must never mint a second PlayerShareLink row.');
    }

    /**
     * Task 39 coverage gap: `Trainer\CoachController::invite()`'s
     * `coach_invitation_source` limiter (10/hour, per-IP, Task 34) had no
     * test coverage on its 429 branch. Per this project's established rule
     * (`ResetAndVerificationThrottleTest`'s class docblock, mirrored here for
     * this pair): only the *source* limiter may surface a 429; consumed
     * before the account limiter, so this is provable in isolation with the
     * account limiter still at full budget.
     *
     * Uses `KernelBrowser::loginUser()` rather than a real form-based sign-in
     * so the decisive POST below can be this test's *first* real
     * `$client->request()` call -- see `LoginThrottleTest`'s class docblock
     * for why only the first real request can see rate-limiter state
     * accumulated by anything before it (the test-only array cache pool is
     * reset at the start of every request after the first). A real sign-in
     * would itself already be a first request, leaving the decisive POST as
     * the second and wiping the pre-consumed budget before it ever ran.
     */
    public function testExceedingCoachInvitationSourceLimiterProduces429(): void
    {
        $ip = '198.51.100.95';
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->client->loginUser($trainer);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $remaining = $this->consumeCoachInvitationSource($ip)->getRemainingTokens();
            self::assertSame(10 - $attempt, $remaining);
        }

        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->bareInviteFormPost(UserFactory::email('source-throttled-coach'));

        $response = $this->client->getResponse();
        self::assertSame(
            429,
            $response->getStatusCode(),
            'An exhausted coach_invitation_source limiter must refuse the 11th invitation attempt with a 429.',
        );
        self::assertTrue($response->headers->has('Retry-After'));

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM coach_invitation WHERE trainer_id = :trainer',
            ['trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'A 429-refused submission must never create an invitation row.');
    }

    /**
     * Task 39 coverage gap: `coach_invitation_account` (3/hour, per-trainer,
     * Task 34) had no test coverage on its field-level-error branch. Unlike
     * the source limiter above, an exhausted account limiter must never
     * surface a 429 -- it renders the coaches page again with a field-level
     * form error, exactly `Trainer\CoachController::invite()`'s own docblock
     * documents (the trainer is already a known, authenticated account, so
     * there is no enumeration concern to protect by staying silent). A
     * different, never-otherwise-used source IP keeps `coach_invitation_source`
     * at full budget, isolating this test to the account limiter alone.
     */
    public function testExceedingCoachInvitationAccountLimiterRendersAFieldErrorNeverA429(): void
    {
        $ip = '198.51.100.96';
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->client->loginUser($trainer);

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $remaining = $this->consumeCoachInvitationAccount((string) $trainer->getId())->getRemainingTokens();
            self::assertSame(3 - $attempt, $remaining);
        }

        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->bareInviteFormPost(UserFactory::email('account-throttled-coach'));

        $response = $this->client->getResponse();
        self::assertNotSame(
            429,
            $response->getStatusCode(),
            'An exhausted coach_invitation_account limiter must never produce a 429 -- only a field-level form error.',
        );
        self::assertSelectorTextContains('.error-summary', "You've sent too many invitations recently");

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM coach_invitation WHERE trainer_id = :trainer',
            ['trainer' => (string) $trainer->getId()],
        )->fetchOne();
        self::assertSame(0, (int) $count, 'A field-error-refused submission must never create an invitation row.');
    }

    /**
     * A bare POST directly against `/trainer/coaches/invite`, with no
     * preceding GET -- see `testExceedingCoachInvitationSourceLimiterProduces429()`'s
     * docblock for why skipping the GET (and using `loginUser()` instead of a
     * real sign-in request) is what keeps this the client's first real
     * request. `submit` (this form's CSRF token id, the framework-wide
     * default per `config/packages/csrf.yaml`) is in `stateless_token_ids`,
     * so the literal cookie-name value works the same way
     * `ResetAndVerificationThrottleTest`'s identical trick documents.
     */
    private function bareInviteFormPost(string $email): void
    {
        $this->client->request(
            'POST',
            '/trainer/coaches/invite',
            ['coach_invitation_form' => ['email' => $email, '_token' => 'csrf-token']],
            [],
            ['HTTP_REFERER' => 'http://localhost/'],
        );
    }

    private function consumeCoachInvitationSource(string $ip): RateLimit
    {
        return $this->coachInvitationSourceLimiter()->create(IpTruncator::truncate($ip))->consume();
    }

    private function consumeCoachInvitationAccount(string $trainerId): RateLimit
    {
        return $this->coachInvitationAccountLimiter()->create($trainerId)->consume();
    }

    private function coachInvitationSourceLimiter(): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.coach_invitation_source');
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter;
    }

    private function coachInvitationAccountLimiter(): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.coach_invitation_account');
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter;
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
