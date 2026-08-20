<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\IpTruncator;
use App\Service\CoachInvitationRequest;
use App\Service\CoachInvitationService;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Task 39 coverage gap: `share_link_registration_source` (20/hour, source-
 * only, `config/packages/rate_limiter.yaml`) is consumed by two independent
 * controller actions -- `PlayerShareLinkController::register()`'s POST
 * branch and `CoachInvitationController::accept()`'s anonymous-registration
 * POST branch -- and neither's 429 branch had any test coverage before this
 * file. Follows `LoginThrottleTest`/`ResetAndVerificationThrottleTest`'s
 * established technique for exhausting a limiter deterministically: every
 * "prior attempt" is a direct, in-process `RateLimiterFactory::create($key)
 * ->consume()` call against the real, production `limiter.
 * share_link_registration_source` service (never touching `Kernel::handle()`),
 * and each test's *one* real HTTP request is a bare POST with no preceding
 * GET, so it is unconditionally the client's first request and therefore
 * never itself reset by the test-only array cache pool's own
 * `kernel.reset` behaviour (see `LoginThrottleTest`'s class docblock for the
 * full citation trail -- confirmed there, not re-derived here, for the
 * identical `cache.rate_limiter` mechanism this limiter also uses).
 */
final class ShareLinkRegistrationSourceThrottleTest extends WebTestCase
{
    /**
     * The literal value a CSRF field must carry for a stateless-CSRF request
     * with no cookie/session to pass `SameOriginCsrfTokenManager` -- see
     * `ResetAndVerificationThrottleTest`'s class docblock for the full
     * citation. `submit` (every plain Symfony Form's default token id in
     * this project, `config/packages/csrf.yaml`) is in `stateless_token_ids`,
     * so this applies to both forms exercised here.
     */
    private const CSRF_TOKEN_VALUE = 'csrf-token';

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
     * `PlayerShareLinkRegistrationTest`/`CoachInvitationAcceptTest`: nothing
     * here relies on it, but the fixture rows must be durably committed for
     * consistency with those files' own convention, and cleanup mirrors
     * theirs.
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
     * `PlayerShareLinkController::register()`'s POST branch: once 20
     * submission attempts from one source exhaust `share_link_registration_source`,
     * the 21st is refused with a real 429 and a `Retry-After` header --
     * before the form is ever validated or `PlayerRegistrationService`
     * is ever called.
     */
    public function testExceedingShareLinkRegistrationSourceLimiterOnPlayerRegistrationProduces429(): void
    {
        $ip = '198.51.100.90';

        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $link = $this->createLinkFor($trainer);

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $remaining = $this->consumeSourceLimiter($ip)->getRemainingTokens();
            self::assertSame(
                20 - $attempt,
                $remaining,
                \sprintf('share_link_registration_source should have exactly %d of 20 tokens left after simulated attempt %d.', 20 - $attempt, $attempt),
            );
        }

        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->bareFormPost('/join/'.$link->getCode().'/register', 'player_share_link_registration_form', []);

        $response = $this->client->getResponse();
        self::assertSame(
            429,
            $response->getStatusCode(),
            'An exhausted share_link_registration_source limiter must refuse the 21st registration attempt with a 429.',
        );
        self::assertTrue($response->headers->has('Retry-After'));

        // The refused attempt must never have created a row -- the limiter
        // check runs before the form is ever validated or the registration
        // service is ever called. Only the trainer fixture row exists.
        $count = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM app_user')->fetchOne();
        self::assertSame(1, (int) $count, 'A 429-refused submission must never create a User row.');
    }

    /**
     * `CoachInvitationController::accept()`'s anonymous-registration POST
     * branch shares the exact same `share_link_registration_source` limiter
     * (both controllers inject the same `RateLimiterFactory
     * $shareLinkRegistrationSourceLimiter` parameter, confirmed by reading
     * both files directly) -- proven independently here rather than assumed
     * from the player-side test above.
     */
    public function testExceedingShareLinkRegistrationSourceLimiterOnCoachInvitationRegistrationProduces429(): void
    {
        $ip = '198.51.100.91';

        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $token = $this->inviteCoach($trainer, UserFactory::email('coach-source-throttle'));

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $remaining = $this->consumeSourceLimiter($ip)->getRemainingTokens();
            self::assertSame(20 - $attempt, $remaining);
        }

        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->bareFormPost('/coach-invitation/'.$token, 'coach_registration_form', []);

        $response = $this->client->getResponse();
        self::assertSame(
            429,
            $response->getStatusCode(),
            'An exhausted share_link_registration_source limiter must refuse the 21st coach-invitation registration attempt with a 429.',
        );
        self::assertTrue($response->headers->has('Retry-After'));

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM app_user WHERE email = :email',
            ['email' => $trainer->getEmail()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'Precondition sanity: only the trainer fixture row exists.');
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['role' => UserRole::COACH]),
            'A 429-refused submission must never create a coach User row.',
        );
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->getOrCreateFor($trainer);
    }

    private function inviteCoach(User $trainer, string $email): string
    {
        /** @var CoachInvitationService $service */
        $service = self::getContainer()->get(CoachInvitationService::class);
        $service->invite(new CoachInvitationRequest($email), $trainer);

        $mail = $this->mailHandler->last();
        self::assertNotNull($mail, 'Precondition failed: invite() did not queue a mail.');
        $token = $mail->context['token'];
        self::assertIsString($token);

        return $token;
    }

    /**
     * Consumes one `share_link_registration_source` token for `$ip` via the
     * real, production `RateLimiterFactory` service, in-process -- see the
     * class docblock for why this stands in for a real prior HTTP attempt.
     */
    private function consumeSourceLimiter(string $ip): RateLimit
    {
        return $this->sourceLimiter()->create(IpTruncator::truncate($ip))->consume();
    }

    private function sourceLimiter(): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.share_link_registration_source');
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter;
    }

    /**
     * A bare POST directly against a plain Symfony Form's action, with no
     * preceding GET -- see the class docblock for why skipping the GET is
     * what keeps this the client's *first* (and only) real HTTP request per
     * test method, and why the literal CSRF field value below is what a
     * request with no cookie/session needs to pass stateless CSRF.
     *
     * @param array<string, string> $fields the form's own fields, unprefixed
     */
    private function bareFormPost(string $uri, string $formName, array $fields): void
    {
        $payload = [$formName => array_merge($fields, ['_token' => self::CSRF_TOKEN_VALUE])];

        $this->client->request(
            'POST',
            $uri,
            $payload,
            [],
            ['HTTP_REFERER' => 'http://localhost/'],
        );
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
