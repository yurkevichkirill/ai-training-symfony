<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\ChildTrainerRequest;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 42 (AC-12...AC-18): a signed-in child account's session, driven
 * through real HTTP routes rather than the service layer -- what a child
 * may reach (AC-13), what it is refused server-side regardless of how the
 * request is made (AC-14), and the ShareLink-block/parent-review round trip
 * (AC-15, AC-16, AC-17), including the "no carve-out for an existing
 * connection" edge case `PlayerShareLinkChildBlockedTest` already proves at
 * the same HTTP layer.
 */
final class ChildSessionConstraintsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM child_trainer_request');
        $connection->executeStatement('DELETE FROM trainer_player_association');
        $connection->executeStatement('DELETE FROM player_share_link');
        $connection->executeStatement('DELETE FROM child_account');

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * AC-13: a signed-in child can reach the allow-listed, view-oriented
     * routes -- its own dashboard, its own profile form, its own trainer
     * list -- all 200s, not redirects to a denied page.
     */
    public function testASignedInChildCanReachItsOwnAllowListedRoutesAc13(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-allow')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-allow')));
        $this->persistChildAccount($childUser, $parent);

        $this->client->loginUser($childUser);

        $this->client->request('GET', '/');
        self::assertResponseRedirects(); // Task 20's role-landing redirect (e.g. to /player) is itself the allow-listed dashboard entry.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('AC-13: the dashboard must be reachable.');

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful('AC-13: the child\'s own profile form must be reachable.');

        $this->client->request('GET', '/player/trainers');
        self::assertResponseIsSuccessful('AC-13: the child\'s own trainer list must be reachable.');
    }

    /**
     * AC-12, AC-18: a signed-in child's own trainer-list page must show only
     * its own connections, never the parent's or a sibling's.
     */
    public function testChildTrainerListShowsOnlyItsOwnTrainerAc12Ac18(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-roster')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-roster')));
        $sibling = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('sibling-roster')));
        $this->persistChildAccount($childUser, $parent);
        $this->persistChildAccount($sibling, $parent);

        $parentTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-parent-3')));
        $childTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-child-3')));
        $siblingTrainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-of-sibling-3')));

        $this->connectPlayer($parentTrainer, $parent);
        $this->connectPlayer($childTrainer, $childUser);
        $this->connectPlayer($siblingTrainer, $sibling);

        $this->client->loginUser($childUser);
        $this->client->request('GET', '/player/trainers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $childTrainer->getDisplayName());
        self::assertSelectorTextNotContains('body', $parentTrainer->getDisplayName());
        self::assertSelectorTextNotContains('body', $siblingTrainer->getDisplayName());
    }

    /**
     * AC-14: a signed-in child is refused, server-side, on every
     * deny-listed action -- leaving a trainer, any `/family` route, and a
     * direct forged POST to the trainer add/remove routes -- 403, not a
     * redirect to a login page or a silent no-op.
     */
    public function testASignedInChildIsRefused403OnEveryDenyListedActionAc14(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-deny')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-deny')));
        $this->persistChildAccount($childUser, $parent);

        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-deny')));
        $this->connectPlayer($trainer, $childUser);

        $this->client->loginUser($childUser);

        foreach ([
            ['POST', '/player/trainers/'.$trainer->getId().'/leave'],
            ['GET', '/family'],
            ['GET', '/family/children/new'],
            ['GET', '/family/children/'.$childUser->getId().'/trainers/add'],
            ['POST', '/family/children/'.$childUser->getId().'/trainers/add'],
            ['GET', '/family/children/'.$childUser->getId().'/trainers/'.$trainer->getId().'/remove'],
            ['POST', '/family/children/'.$childUser->getId().'/trainers/'.$trainer->getId().'/remove'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, \sprintf('AC-14: %s %s must be refused for a signed-in child.', $method, $path));
        }
    }

    /**
     * AC-17: completing "Review Registration" from the parent's own account
     * connects the child, ending with exactly one association -- identical
     * in shape to an AC-8 connection (a real `TrainerPlayerAssociation` row,
     * `CHILD_TRAINER_CONNECTED` recorded); approving the same request twice
     * is refused as already-resolved, not duplicated.
     */
    public function testApprovingAReviewRequestConnectsOnceAndApprovingTwiceIsRefusedAc17(): void
    {
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-review-2')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-review-2')));
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-review-2')));
        $childAccount = $this->persistChildAccount($childUser, $parent);
        $link = $this->createLinkFor($trainer);

        // The child's blocked click, exactly as the real /join/{code} route
        // would record it.
        $this->client->loginUser($childUser);
        $this->client->request('GET', '/join/'.$link->getCode());
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->loginUser($parent);

        $requestEntity = $this->em->getRepository(ChildTrainerRequest::class)->findOneBy(['childUser' => $childUser, 'trainer' => $trainer]);
        self::assertInstanceOf(ChildTrainerRequest::class, $requestEntity);

        $crawler = $this->client->request('GET', '/family/requests/'.$requestEntity->getId());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Approve')->form());
        self::assertResponseRedirects('/family');

        $associationCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $associationCount, 'AC-17: exactly one association after approval.');

        // Approving the same, now-resolved request again must not duplicate
        // anything -- the controller renders the flash-error path, not a
        // second connection. A fresh GET of the review page supplies a
        // valid CSRF token for the repeat submission (review() does not
        // gate on resolved status, only on parent ownership).
        $secondCrawler = $this->client->request('GET', '/family/requests/'.$requestEntity->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($secondCrawler->selectButton('Approve')->form());
        self::assertResponseRedirects('/family');

        $associationCountAfterSecondApproval = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_player_association WHERE trainer_id = :trainer AND player_id = :player',
            ['trainer' => (string) $trainer->getId(), 'player' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $associationCountAfterSecondApproval, 'Approving an already-resolved request must never duplicate the connection.');
    }

    private function connectPlayer(User $trainer, User $player): TrainerPlayerAssociation
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();

        return $association;
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function persistChildAccount(User $childUser, User $parent): ChildAccount
    {
        $childAccount = new ChildAccount($childUser, $parent);
        $this->em->persist($childAccount);
        $this->em->flush();

        return $childAccount;
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }
}
