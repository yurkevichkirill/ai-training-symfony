<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuthEvent;
use App\Enum\AuthEventType;
use App\Enum\UserRole;
use App\Service\AuthEventRecord;
use App\Service\AuthEventRecorder;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * AC-24's "contain no password or token material" proven two ways: a
 * structural guarantee that cannot regress silently (the reflection test),
 * and a positive proof that recording actually persists what it claims to
 * (the second test here). Task 35.
 */
final class AuthEventRecorderTest extends KernelTestCase
{
    /**
     * Name fragments that would plausibly indicate a parameter capable of
     * carrying password or raw token material, regardless of how a future
     * edit happened to spell it.
     */
    private const FORBIDDEN_NAME_PATTERN = '/password|passwd|pwd|token|secret|verifier|credential/i';

    /**
     * Class/interface names that would carry secret material (or the whole
     * HTTP request, which itself carries POSTed credentials) even under an
     * innocuous-looking parameter name.
     */
    private const FORBIDDEN_TYPE_NAMES = [
        Request::class,
        PasswordAuthenticatedUserInterface::class,
    ];

    private EntityManagerInterface $em;
    private AuthEventRecorder $recorder;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->recorder = self::getContainer()->get(AuthEventRecorder::class);
    }

    protected function tearDown(): void
    {
        // AuthEventRecorder writes through its own, genuinely separate
        // physical connection (Task 34's class docblock) and commits
        // immediately -- there is no test transaction to roll this back, so
        // clean up explicitly, the same pattern
        // AuthEventRecorderIdentityMapTest already established.
        $connection = $this->em->getConnection();
        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement(
                'DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)',
                ['email' => $email],
            );
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    /**
     * Guards against a future edit silently reintroducing a parameter that
     * could carry a password or raw token -- checked structurally
     * (parameter names and declared types), not by re-reading the class and
     * trusting it stays this way.
     */
    public function testConstructorHasNoParameterCapableOfCarryingPasswordOrTokenMaterial(): void
    {
        $constructor = new \ReflectionMethod(AuthEventRecord::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertNotEmpty($parameters, 'Precondition failed: AuthEventRecord has no constructor parameters to check.');

        foreach ($parameters as $parameter) {
            self::assertDoesNotMatchRegularExpression(
                self::FORBIDDEN_NAME_PATTERN,
                $parameter->getName(),
                \sprintf(
                    'AuthEventRecord constructor parameter "$%s" has a name that could plausibly carry password or token material.',
                    $parameter->getName(),
                ),
            );

            foreach ($this->flattenTypeNames($parameter->getType()) as $typeName) {
                self::assertDoesNotMatchRegularExpression(
                    self::FORBIDDEN_NAME_PATTERN,
                    $typeName,
                    \sprintf(
                        'AuthEventRecord constructor parameter "$%s" is typed "%s", which could plausibly carry password or token material.',
                        $parameter->getName(),
                        $typeName,
                    ),
                );

                self::assertNotContains(
                    $typeName,
                    self::FORBIDDEN_TYPE_NAMES,
                    \sprintf(
                        'AuthEventRecord constructor parameter "$%s" is typed "%s", which can carry secret material wholesale.',
                        $parameter->getName(),
                        $typeName,
                    ),
                );
            }
        }

        // Belt and suspenders: pin the exact known-safe shape today, so an
        // added parameter of an innocuous-looking name/type (e.g. a future
        // `$rawInput` holding an unparsed request body) still fails this
        // test and forces a deliberate, reviewed update rather than sailing
        // through silently.
        self::assertSame(
            ['type', 'outcome', 'userId', 'identifierAttempted', 'ip', 'userAgent', 'context'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters),
            'AuthEventRecord constructor parameter list changed -- re-verify none of the new parameters can carry secret material, then update this expectation deliberately.',
        );
    }

    /**
     * The positive half: recording an event must actually persist a row
     * with the values the caller supplied, not merely avoid throwing.
     *
     * `AuthEventRecorder` writes through its own, genuinely separate
     * physical DBAL connection (Task 34). This test deliberately reads the
     * result back through `$this->em` -- the *container's* EntityManager,
     * over a *different* connection -- rather than the recorder's own, to
     * empirically confirm cross-connection visibility rather than assume
     * it: `$em->clear()` forces the read below to hit the database instead
     * of returning nothing from an identity map that never saw the write,
     * and the assertion passing is the proof that two independently
     * committed connections against the same Postgres database (default
     * READ COMMITTED) see each other's committed writes -- exactly the
     * property the recorder's whole design depends on.
     */
    public function testRecordPersistsARowWithTheExpectedTypeOutcomeUserIdAndIp(): void
    {
        $email = UserFactory::email('auth-event-recorder');
        $user = UserFactory::activeVerified(UserRole::PLAYER, $email);
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedEmails[] = $email;
        $userId = $user->getId();

        $this->recorder->record(new AuthEventRecord(
            type: AuthEventType::LOGIN_SUCCEEDED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $userId,
            ip: '203.0.113.42',
            userAgent: 'PHPUnit/AuthEventRecorderTest',
        ));

        // Cross-connection visibility check (see docblock above): reading
        // through a completely different EntityManager/connection than the
        // one record() wrote through.
        $this->em->clear();

        $authEvent = $this->em->getRepository(AuthEvent::class)->findOneBy(['user' => $user]);

        self::assertInstanceOf(
            AuthEvent::class,
            $authEvent,
            'record() must actually persist a row visible from a separate, already-committed connection.',
        );
        self::assertSame(AuthEventType::LOGIN_SUCCEEDED->value, $authEvent->getType());
        self::assertSame(AuthEventRecord::OUTCOME_SUCCESS, $authEvent->getOutcome());
        self::assertNotNull($authEvent->getUser());
        self::assertSame($userId->toRfc4122(), $authEvent->getUser()->getId()->toRfc4122());
        self::assertSame('203.0.113.42', $authEvent->getIp());
    }

    /**
     * @return list<string>
     */
    private function flattenTypeNames(?\ReflectionType $type): array
    {
        if (null === $type) {
            return [];
        }

        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                $names = [...$names, ...$this->flattenTypeNames($inner)];
            }

            return $names;
        }

        return [];
    }
}
