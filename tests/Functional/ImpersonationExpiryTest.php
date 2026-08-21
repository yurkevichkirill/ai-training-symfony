<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S6 (AC-8, AC-14, edge case 6): expiry is enforced on the very next request
 * past the deadline -- the impersonated request that discovers it never
 * reaches a controller -- and the row is closed as TIMEOUT with a non-null
 * duration. An unexpired session is left untouched.
 *
 * `expires_at` is written directly into the past between requests, the same
 * technique `SessionIdleExpiryTest` uses for `_last_activity` -- not a sleep.
 */
final class ImpersonationExpiryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedEmails = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedEmails as $email) {
            $connection->executeStatement('DELETE FROM account_event WHERE actor_user_id IN (SELECT id FROM app_user WHERE email = :email) OR subject_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM impersonation_session WHERE actor_user_id IN (SELECT id FROM app_user WHERE email = :email) OR subject_user_id IN (SELECT id FROM app_user WHERE email = :email)', ['email' => $email]);
            $connection->executeStatement('DELETE FROM app_user WHERE email = :email', ['email' => $email]);
        }

        parent::tearDown();
    }

    public function testARequestPastTheExpiryDeadlineForceExitsAndClosesTheRowAsTimeout(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        $this->expireSessionFor($trainer);

        $this->client->request('GET', '/trainer/players');

        self::assertResponseRedirects('/');
        self::assertStringNotContainsString(
            $trainer->getEmail(),
            (string) $this->client->getResponse()->getContent(),
            'The expired impersonated request must never reach the controller/render the target page.',
        );

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at, end_reason, started_at FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainer->getId()],
        );
        self::assertIsArray($row);
        self::assertNotNull($row['ended_at']);
        self::assertSame('TIMEOUT', $row['end_reason']);

        // The very next request is served as the admin again, not the trainer.
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public function testAnUnexpiredImpersonatedRequestIsUntouched(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        $this->client->request('GET', '/trainer/players');
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainer->getId()],
        );
        self::assertIsArray($row);
        self::assertNull($row['ended_at']);
    }

    /**
     * `impersonation_session_expires_after_started_ck` requires
     * `expires_at > started_at` unconditionally, forever -- not only at
     * insert time -- so `expires_at` cannot simply be moved into the past
     * on its own. Both timestamps are shifted back together, keeping their
     * relative order intact while moving `expires_at` behind the current
     * wall-clock time, which is exactly what "already expired" means.
     */
    private function expireSessionFor(User $subject): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE impersonation_session SET started_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE subject_user_id = :id AND ended_at IS NULL",
            ['id' => (string) $subject->getId()],
        );
    }

    private function submitImpersonate(User $target): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $target->getId()));
        $this->client->submit($crawler->selectButton(\sprintf('View platform as %s', $target->getDisplayName()))->form());
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
