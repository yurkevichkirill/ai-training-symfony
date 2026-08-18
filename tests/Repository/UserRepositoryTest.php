<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test against the real app_test database.
 *
 * Each test runs inside a transaction that is rolled back in tearDown, so the
 * cases stay independent without a fixture-reload step. (doctrine-test-bundle
 * is not a project dependency; this is the same isolation by hand.)
 */
final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(UserRepository::class);

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
     * The edge case the spec calls out: a user stored normalized must be found
     * however the address was typed at the login form (AC-5).
     */
    #[DataProvider('equivalentIdentifiers')]
    public function testLoadUserByIdentifierNormalizesTheInput(string $typed): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER, 'ann@x.com');
        $this->em->persist($user);
        $this->em->flush();

        $loaded = $this->repository->loadUserByIdentifier($typed);

        self::assertInstanceOf(User::class, $loaded);
        self::assertTrue($loaded->getId()->equals($user->getId()));
        self::assertSame('ann@x.com', $loaded->getEmail());
    }

    public function testLoadUserByIdentifierReturnsNullForAnUnknownAccount(): void
    {
        self::assertNull($this->repository->loadUserByIdentifier('nobody@x.com'));
    }

    /**
     * The stored value is normalized on the way in, so the equality lookup in
     * loadUserByIdentifier() can rely on it.
     */
    public function testStoredEmailIsNormalizedOnPersist(): void
    {
        $user = new User('  MiXeD@Example.TEST  ', UserFactory::passwordHash(), UserRole::COACH);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->repository->loadUserByIdentifier('mixed@example.test');

        self::assertInstanceOf(User::class, $loaded);
        self::assertSame('mixed@example.test', $loaded->getEmail());
    }

    public function testUpgradePasswordPersistsTheRehashedValue(): void
    {
        $user = UserFactory::activeVerified(UserRole::TRAINER);
        $this->em->persist($user);
        $this->em->flush();

        $rehashed = password_hash(UserFactory::PASSWORD, \PASSWORD_ARGON2ID, ['time_cost' => 4, 'memory_cost' => 12]);
        $this->repository->upgradePassword($user, $rehashed);
        $this->em->clear();

        $loaded = $this->repository->loadUserByIdentifier($user->getEmail());

        self::assertInstanceOf(User::class, $loaded);
        self::assertSame($rehashed, $loaded->getPassword());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentIdentifiers(): iterable
    {
        yield 'exact' => ['ann@x.com'];
        yield 'mixed case' => ['Ann@x.com'];
        yield 'mixed case with trailing space' => ['Ann@x.com '];
        yield 'upper case with surrounding whitespace' => ['  ANN@X.COM  '];
    }
}
