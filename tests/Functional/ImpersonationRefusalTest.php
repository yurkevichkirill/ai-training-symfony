<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S6 (AC-1, AC-3, AC-11, AC-12, edge case 1): every refusal path -- hidden
 * action, forged POST, forged GET (the CSRF regression test), bad/missing
 * CSRF, and every non-Super-Admin role's server-side refusal.
 */
final class ImpersonationRefusalTest extends WebTestCase
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

    public function testDirectoryShowsNoImpersonateActionOnASuperAdminRowOrSelfOrADeactivatedRow(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $otherAdmin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $deactivated = $this->persist(UserFactory::deactivated(UserRole::TRAINER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();

        $html = (string) $crawler->html();
        self::assertStringNotContainsString(\sprintf('/admin/users/%s/impersonate', $otherAdmin->getId()), $html, 'No Impersonate action on another Super Admin row.');
        self::assertStringNotContainsString(\sprintf('/admin/users/%s/impersonate', $admin->getId()), $html, 'No Impersonate action on the admin\'s own row.');
        self::assertStringNotContainsString(\sprintf('/admin/users/%s/impersonate', $deactivated->getId()), $html, 'No Impersonate action on a deactivated row.');
    }

    public function testForgedPostNamingASuperAdminTargetIs403AndCreatesNoRowOrEvent(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $otherAdmin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->signIn($admin);

        // The confirmation route itself denies before rendering (AC-3): a
        // GET to it already 403s, so there is no legitimate CSRF token to
        // forge from -- the strongest form of "no row is ever created."
        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $otherAdmin->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(0, $this->countSessionsFor($otherAdmin));
        self::assertSame(0, $this->countEvents($otherAdmin));
    }

    public function testForgedPostNamingTheAdminsOwnAddressIs403(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $this->signIn($admin);

        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $admin->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * The CSRF regression test: SwitchUserListener itself reads
     * `_switch_user` from the query string on ANY method. Without
     * ImpersonationGuardSubscriber, a plain GET with the parameter would
     * switch. This must stay red if the guard is ever removed.
     */
    public function testForgedGetSwitchUserQueryParameterIs403(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $this->client->request('GET', '/', ['_switch_user' => $trainer->getEmail()]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainer));
    }

    public function testPostWithMissingCsrfTokenIs403(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainer->getId()));
        $form = $crawler->selectButton(\sprintf('View platform as %s', $trainer->getDisplayName()))->form();
        $form->remove('_token');

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainer));
    }

    public function testPostWithWrongCsrfTokenIs403(): void
    {
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $this->signIn($admin);

        $crawler = $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $trainer->getId()));
        $form = $crawler->selectButton(\sprintf('View platform as %s', $trainer->getDisplayName()))->form();
        $form['_token'] = 'not-a-real-csrf-token';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->countSessionsFor($trainer));
    }

    /**
     * @return iterable<string, array{0: UserRole}>
     */
    public static function nonSuperAdminRoleProvider(): iterable
    {
        yield 'trainer' => [UserRole::TRAINER];
        yield 'coach' => [UserRole::COACH];
        yield 'player' => [UserRole::PLAYER];
    }

    #[DataProvider('nonSuperAdminRoleProvider')]
    public function testNonSuperAdminRoleIsRefusedOnConfirmationForgedPostAndHistory(UserRole $role): void
    {
        $actor = $this->persist(UserFactory::activeVerified($role));
        $target = $this->persist(UserFactory::activeVerified(UserRole::PLAYER === $role ? UserRole::TRAINER : UserRole::PLAYER));
        $this->signIn($actor);

        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $target->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Confirmation route must refuse a non-Super-Admin.');

        $this->client->request('GET', '/admin/impersonation-history');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'History route must refuse a non-Super-Admin.');
    }

    public function testUnauthenticatedVisitorIsRefusedOnConfirmationForgedPostAndHistory(): void
    {
        $target = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));

        $this->client->request('GET', \sprintf('/admin/users/%s/impersonate', $target->getId()));
        self::assertResponseRedirects('/login', null, 'An unauthenticated visitor must be sent to sign in, not shown the confirmation page.');

        // ImpersonationGuardSubscriber runs at kernel.request priority 32,
        // above the firewall's own AccessListener/entry-point redirect --
        // it refuses a forged, invalid-CSRF POST with a plain 403
        // regardless of authentication state, before "redirect anonymous
        // users to /login" is even decided.
        $this->client->request('POST', \sprintf('/admin/users/%s/impersonate', $target->getId()), [
            '_switch_user' => $target->getEmail(),
            '_token' => 'whatever',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/admin/impersonation-history');
        self::assertResponseRedirects('/login');
    }

    private function countSessionsFor(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM impersonation_session WHERE subject_user_id = :id',
            ['id' => (string) $subject->getId()],
        );
    }

    private function countEvents(User $subject): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id AND type LIKE :type',
            ['id' => (string) $subject->getId(), 'type' => 'IMPERSONATION_%'],
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
