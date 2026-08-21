<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 (AC-2, AC-4, AC-10, AC-11): the confirmation page shows the target's
 * name and role before anything switches; confirming switches the session,
 * lands on `app_home`, and every subsequent request behaves as the
 * impersonated user; exactly one session row and one IMPERSONATION_STARTED
 * event exist afterward. Also AC-7: an AccountEvent written while
 * impersonating carries the real Super Admin's id as impersonatorUserId.
 */
final class ImpersonationStartTest extends WebTestCase
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

    public function testConfirmationPageShowsTargetNameAndRoleAndCreatesNoRowYet(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainer->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($trainer->getDisplayName(), (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('TRAINER', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countSessionsFor($trainer), 'No session row must exist before confirmation is submitted.');
    }

    public function testConfirmingSwitchesTheSessionAndEveryPageRendersAsTheTrainer(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $this->submitImpersonate($trainer);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        // AC-4: a Trainer-only page is reachable, a Super-Admin-only page is not.
        $this->client->request('GET', '/trainer/players');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // AC-10, AC-11: exactly one session row and one IMPERSONATION_STARTED event.
        self::assertSame(1, $this->countSessionsFor($trainer));
        self::assertSame(1, $this->countEventsOfType($trainer, AccountEventType::IMPERSONATION_STARTED));
    }

    /**
     * AC-7: any AccountEvent written while impersonating carries the real
     * Super Admin's id as `impersonatorUserId` in its context, and the same
     * action performed normally carries no such key.
     */
    public function testAccountEventWrittenWhileImpersonatingCarriesTheRealAdminAsImpersonator(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Save')->form([
            'profile_common_form[firstName]' => 'Impersonated',
            'profile_common_form[lastName]' => 'Edit',
        ]));

        $rowsAfterImpersonatedEdit = $this->em->getConnection()->fetchAllAssociative(
            "SELECT actor_user_id, subject_user_id, context FROM account_event WHERE type = 'PROFILE_UPDATED' AND subject_user_id = :id",
            ['id' => (string) $trainer->getId()],
        );
        self::assertCount(1, $rowsAfterImpersonatedEdit);
        $impersonatedRow = $rowsAfterImpersonatedEdit[0];
        self::assertSame((string) $trainer->getId(), $impersonatedRow['actor_user_id'], 'The event actor must be the impersonated user, not the Super Admin.');

        $impersonatedContext = json_decode((string) $impersonatedRow['context'], true);
        self::assertSame((string) $admin->getId(), $impersonatedContext['impersonatorUserId'] ?? null, 'The context must carry the real Super Admin as impersonator.');

        // Negative control: a normal (non-impersonated) profile edit carries
        // no such key. Exit impersonation, sign out of the admin's own
        // session entirely, then sign in fresh as the trainer -- genuinely
        // "not impersonating," not merely "exited."
        $this->em->clear();
        $this->client->request('GET', '/', ['_switch_user' => '_exit']);
        // Two hops: the exit's own redirect to app_home, then app_home's own
        // role-landing redirect to the admin dashboard.
        $this->client->followRedirect();
        $dashboard = $this->client->followRedirect();
        $this->client->submit($dashboard->selectButton('Sign out')->form());

        $loginCrawler = $this->client->request('GET', '/login');
        $this->client->submit($loginCrawler->selectButton('Sign in')->form([
            '_username' => $trainer->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));
        $profileCrawler = $this->client->request('GET', '/profile');
        $this->client->submit($profileCrawler->selectButton('Save')->form([
            'profile_common_form[firstName]' => 'Normal',
            'profile_common_form[lastName]' => 'Edit',
        ]));

        // Both edits may land in the same second -- `account_event.occurred_at`
        // is second-precision -- so the two rows are told apart by their
        // context, never by ordering.
        $allRows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT context FROM account_event WHERE type = 'PROFILE_UPDATED' AND subject_user_id = :id",
            ['id' => (string) $trainer->getId()],
        );
        self::assertCount(2, $allRows, 'Exactly the impersonated edit and the normal edit must have been recorded.');

        $rowsWithoutImpersonator = array_filter(
            $allRows,
            static fn (array $row): bool => !\array_key_exists('impersonatorUserId', json_decode((string) $row['context'], true)),
        );
        self::assertCount(1, $rowsWithoutImpersonator, 'Exactly one PROFILE_UPDATED row (the normal edit) must carry no impersonatorUserId key.');
    }

    private function submitImpersonate(User $target): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $target->getId()));
        $this->client->submit($crawler->selectButton(\sprintf('View platform as %s', $target->getDisplayName()))->form());
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
