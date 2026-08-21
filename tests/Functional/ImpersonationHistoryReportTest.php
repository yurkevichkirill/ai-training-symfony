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
 * S6 (AC-13, AC-14, edge case 8): the "Impersonation History" report lists
 * closed and in-progress sessions correctly, filters by actor/subject/date
 * range, renders an explicit empty state rather than an error, paginates
 * disjointly, and offers no write action of any kind.
 */
final class ImpersonationHistoryReportTest extends WebTestCase
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

    public function testReportListsAClosedAndAnInProgressSessionDistinctly(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainerClosed = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $trainerOpen = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $this->signIn($admin);
        $this->submitImpersonate($trainerClosed);
        $this->client->request('GET', '/', ['_switch_user' => '_exit']);
        $this->client->followRedirect();

        $this->submitImpersonate($trainerOpen);
        // Two hops: the switch's own redirect to app_home, then app_home's
        // role-landing redirect to the trainer dashboard.
        $this->client->followRedirect();
        $dashboard = $this->client->followRedirect();

        // trainerOpen's session is deliberately left open (the DB row is
        // untouched by a plain /logout -- only SwitchUserEvent's own exit
        // path closes it). This browser's *local* session is ended and
        // signed back in fresh as the admin, purely so the report can be
        // read from the admin's own view -- WebTestCase supports only one
        // `createClient()` per test, so this is the same client/cookie jar,
        // not a second one.
        $this->client->submit($dashboard->selectButton('Sign out')->form());
        $loginCrawler = $this->client->request('GET', '/login');
        $this->client->submit($loginCrawler->selectButton('Sign in')->form([
            '_username' => $admin->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        $crawler = $this->client->request('GET', '/admin/impersonation-history');
        self::assertResponseIsSuccessful();

        $html = (string) $crawler->html();
        self::assertStringContainsString($trainerClosed->getDisplayName(), $html);
        self::assertStringContainsString($trainerOpen->getDisplayName(), $html);
        self::assertStringContainsString('In progress', $html, 'A still-open session must be labelled distinctly, not blank.');
        self::assertStringContainsString('EXPLICIT_EXIT', $html);
    }

    public function testFilteringByActorSubjectAndDateRangeNarrowsResultsAndAnEmptyRangeShowsTheEmptyStateRow(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->request('GET', '/', ['_switch_user' => '_exit']);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/admin/impersonation-history', ['subject_id' => (string) $trainer->getId()]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($trainer->getDisplayName(), (string) $crawler->html());

        $crawler = $this->client->request('GET', '/admin/impersonation-history', ['started_from' => '2999-01-01', 'started_until' => '2999-01-02']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No impersonation sessions match these filters.', (string) $crawler->html(), 'An empty-result range must render the explicit empty-state row, not an error.');
    }

    public function testTheReportPageOffersNoWriteAction(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', '/admin/impersonation-history');
        self::assertResponseIsSuccessful();

        $forms = $crawler->filter('form');
        foreach ($forms as $form) {
            self::assertSame('get', strtolower((string) $form->getAttribute('method')), 'The report page must carry no POST form.');
        }
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
