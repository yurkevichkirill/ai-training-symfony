<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\UserAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test against the real app_test database (AC-5).
 *
 * Each test runs inside a transaction that is rolled back in tearDown, so the
 * cases stay independent without a fixture-reload step -- same pattern as
 * UserRepositoryTest. UserAccountService::create() opens its own *nested*
 * transaction via EntityManager::wrapInTransaction(); DBAL 4 always nests
 * transactions with savepoints, so a failed nested transaction only rolls
 * back to its own savepoint and never disturbs this test's outer one.
 *
 * The service is built by hand from its two collaborators rather than
 * fetched as `self::getContainer()->get(UserAccountService::class)`: nothing
 * in the app wires it in yet (that lands in a later task), so as an
 * unreferenced private service it gets compiled out of the container
 * entirely -- the test container's private-service locator only keeps
 * services something else actually references. `doctrine` and
 * `security.user_password_hasher` are both real, independently-consumed
 * services, so they survive and can be fetched directly.
 */
final class UserAccountServiceConcurrentCreationTest extends KernelTestCase
{
    private const string PLAIN_PASSWORD = 'a-valid-test-password-12';

    private EntityManagerInterface $em;
    private UserAccountService $service;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = new UserAccountService(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get('security.user_password_hasher'),
        );

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The concurrent-registration edge case (AC-5): two attempts to create an
     * account for the same address -- typed with different casing, exactly as
     * two racing registration requests would arrive -- collide on
     * `UNIQUE (email)`. Exactly one must succeed; the other must raise the
     * typed EmailAlreadyInUseException, never an uncaught error. Doctrine
     * checks the (non-deferrable) unique index at INSERT time, so issuing the
     * two attempts one after another against the same open transaction
     * reproduces the same database-level collision a real concurrent race
     * would hit, without needing two live connections.
     */
    public function testOnlyOneOfTwoRacingCreationsForTheSameEmailSucceeds(): void
    {
        $first = $this->service->create('Ann@Example.test', self::PLAIN_PASSWORD, UserRole::PLAYER);

        self::assertInstanceOf(User::class, $first);
        self::assertSame('ann@example.test', $first->getEmail());

        try {
            $this->service->create('  ANN@EXAMPLE.TEST  ', self::PLAIN_PASSWORD, UserRole::PLAYER);
            self::fail('Expected EmailAlreadyInUseException to be thrown for the colliding email.');
        } catch (EmailAlreadyInUseException $e) {
            self::assertSame('ann@example.test', $e->getEmail());
        }

        // The manager-closed pitfall: UniqueConstraintViolationException left
        // the EntityManager the service was using in a closed state. If
        // UserAccountService reused that same closed instance, this call
        // would blow up with "EntityManager is closed" instead of succeeding
        // -- proving the fresh-manager recovery actually works, not just that
        // it is documented.
        $second = $this->service->create('bob@example.test', self::PLAIN_PASSWORD, UserRole::COACH);

        self::assertInstanceOf(User::class, $second);
        self::assertSame('bob@example.test', $second->getEmail());
        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
    }

    public function testCreatedUserHasAWorkingArgon2idHashAndTheRequestedRole(): void
    {
        $user = $this->service->create('coach@example.test', self::PLAIN_PASSWORD, UserRole::COACH);

        self::assertSame(UserRole::COACH, $user->getRole());
        self::assertNotSame(self::PLAIN_PASSWORD, $user->getPasswordHash());
        self::assertTrue(password_verify(self::PLAIN_PASSWORD, $user->getPasswordHash()));
    }
}
