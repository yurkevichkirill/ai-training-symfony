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

/**
 * S6 (AC-5, AC-6, AC-9): the sticky banner is present on every authenticated
 * route while impersonating and absent otherwise; "Exit Impersonation"
 * restores the admin's own session and closes the row exactly once.
 */
final class ImpersonationBannerAndExitTest extends WebTestCase
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

    public function testBannerIsPresentOnTrainerPlayerAndProfilePagesWhileImpersonatingAndCarriesTheTargetName(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        $this->client->followRedirect();

        foreach (['/trainer/players', '/profile'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $html = (string) $crawler->html();
            self::assertStringContainsString('Impersonation', $html, \sprintf('Banner must appear on %s.', $path));
            self::assertStringContainsString($trainer->getDisplayName(), $html, \sprintf('Banner must name the target on %s.', $path));
        }
    }

    public function testBannerIsAbsentWhenNotImpersonating(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($trainer);

        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Impersonation', (string) $crawler->html());
    }

    public function testExitImpersonationRestoresTheAdminAndClosesTheRowExactlyOnce(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);
        $this->submitImpersonate($trainer);
        // Two hops: the switch's own redirect to app_home, then app_home's
        // role-landing redirect to the trainer dashboard.
        $this->client->followRedirect();
        $home = $this->client->followRedirect();

        $exitLink = $home->selectLink('Exit Impersonation')->link();
        $this->client->click($exitLink);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ended_at, end_reason FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $trainer->getId()],
        );
        self::assertIsArray($row);
        self::assertNotNull($row['ended_at']);
        self::assertSame('EXPLICIT_EXIT', $row['end_reason']);
        self::assertSame(1, $this->countEndedEvents($trainer));

        // A second exit attempt (e.g. a stale banner link reloaded) closes nothing further.
        $this->client->request('GET', '/', ['_switch_user' => '_exit']);
        self::assertSame(1, $this->countEndedEvents($trainer), 'A second exit must not write a second IMPERSONATION_ENDED event.');
    }

    private function submitImpersonate(User $target): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $target->getId()));
        $this->client->submit($crawler->selectButton(\sprintf('View platform as %s', $target->getDisplayName()))->form());
    }

    private function countEndedEvents(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id AND type = :type',
            ['id' => (string) $subject->getId(), 'type' => AccountEventType::IMPERSONATION_ENDED->value],
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
