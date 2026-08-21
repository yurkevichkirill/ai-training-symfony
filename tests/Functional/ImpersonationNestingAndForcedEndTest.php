<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Service\AccountLifecycleService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 (AC-9, edge cases 3, 4, 5, 7): a second impersonation attempt while one
 * is active is refused and leaves the first untouched; deactivating either
 * party mid-session force-ends it as ACCOUNT_STATE_CHANGE; exiting and
 * re-impersonating the same target creates an independent second session.
 */
final class ImpersonationNestingAndForcedEndTest extends WebTestCase
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

    public function testASecondImpersonationPostWhileOneIsActiveIs403AndLeavesTheFirstSessionUnchanged(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainerA);
        $this->client->followRedirect();

        // While impersonating trainerA, attempt to impersonate trainerB.
        // The confirmation route is class-gated ROLE_SUPER_ADMIN, which the
        // current (impersonated Trainer) token does not hold.
        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainerB->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainerA->getId()],
        );
        self::assertIsArray($row);
        self::assertNull($row['ended_at'], 'The first session must remain open and unchanged.');
        self::assertSame(0, $this->countSessionsFor($trainerB));
    }

    public function testDeactivatingTheSubjectMidSessionForceEndsItAsAccountStateChangeAndBlocksTheBrowser(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        $this->deactivateViaLifecycleService($trainer, $admin);

        $this->client->request('GET', '/trainer/players');

        // The impersonated user's own session is invalidated entirely by
        // isEqualTo() (D7) -- the browser lands unauthenticated, not back
        // on the admin's own session.
        self::assertResponseRedirects('/login');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at, end_reason FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainer->getId()],
        );
        self::assertIsArray($row);
        self::assertNotNull($row['ended_at']);
        self::assertSame('ACCOUNT_STATE_CHANGE', $row['end_reason']);
    }

    public function testDeactivatingTheActorMidSessionForceEndsIt(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        // Deactivate the admin via a *different* Super Admin actor -- an
        // account cannot deactivate itself through this service in the
        // ordinary admin flow, and this is what fires D7's forced-end.
        $secondAdmin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->deactivateViaLifecycleService($admin, $secondAdmin);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at, end_reason FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainer->getId()],
        );
        self::assertIsArray($row);
        self::assertNotNull($row['ended_at']);
        self::assertSame('ACCOUNT_STATE_CHANGE', $row['end_reason']);
    }

    public function testExitingAndReImpersonatingTheSameTargetCreatesAnIndependentSecondSession(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        $this->client->request('GET', '/', ['_switch_user' => '_exit']);
        $this->client->followRedirect();

        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        self::assertSame(2, $this->countSessionsFor($trainer), 'Re-impersonating must create a brand-new, independent row.');
        self::assertSame(2, $this->countEventsOfType($trainer, AccountEventType::IMPERSONATION_STARTED));
    }

    /**
     * Goes through the real `AccountLifecycleService::deactivate()` (D7's
     * actual wiring point), fetched from the container -- a raw SQL status
     * flip would bypass the forced-end call entirely and prove nothing.
     */
    private function deactivateViaLifecycleService(User $subject, User $actor): void
    {
        $this->em->clear();
        $freshSubject = $this->em->find(User::class, $subject->getId());
        $freshActor = $this->em->find(User::class, $actor->getId());
        self::assertInstanceOf(User::class, $freshSubject);
        self::assertInstanceOf(User::class, $freshActor);

        $service = self::getContainer()->get(AccountLifecycleService::class);
        \assert($service instanceof AccountLifecycleService);
        $service->deactivate($freshSubject, $freshActor);
    }

    private function countSessionsFor(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $subject->getId()],
        );
    }

    private function countEventsOfType(User $subject, AccountEventType $type): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id AND type = :type',
            ['id' => (string) $subject->getId(), 'type' => $type->value],
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
