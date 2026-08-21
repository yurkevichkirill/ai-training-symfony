<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ChildAccount;
use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\PlayerShareLinkService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Task 34 (AC-15, AC-16, D3): a signed-in child following ANY trainer's
 * player ShareLink through the real `/join/{code}` route is unconditionally
 * blocked -- no association is ever created, the parent is notified, and an
 * already-existing active association with that exact trainer is no
 * carve-out (the spec's repeat-click edge case).
 *
 * `loginUser()` (token injected directly into the security context) rather
 * than a real `/login` POST: this keeps the whole scenario down to the
 * single `/join/{code}` request the assertions care about.
 *
 * Deliberately no wrapping transaction -- same reason as
 * `PlayerShareLinkAssociationTest`: `AccountEventRecorder` records through
 * its own independent physical connection, which must see already-committed
 * rows.
 */
final class PlayerShareLinkChildBlockedTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * @see AC-15 a signed-in child sees the block, not the instant-connect
     *      outcome
     * @see AC-16 the parent is emailed the same moment
     */
    public function testASignedInChildFollowingAShareLinkIsBlockedAndParentNotifiedAc15Ac16(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child')));
        $this->persistChildAccount($childUser, $parent);
        $link = $this->createLinkFor($trainer);

        $this->client->loginUser($childUser);
        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-message', 'Ask your parent');

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        self::assertNull($associationRepository->findOneFor($trainer, $childUser), 'No association may ever be created on this branch.');

        $requestCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM child_trainer_request WHERE child_user_id = :id',
            ['id' => (string) $childUser->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $requestCount);

        $eventCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM account_event WHERE subject_user_id = :id AND type = :type',
            ['id' => (string) $childUser->getId(), 'type' => AccountEventType::CHILD_SHARE_LINK_BLOCKED->value],
        )->fetchOne();
        self::assertSame(1, (int) $eventCount);

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST, $mail->template);
        self::assertSame($parent->getEmail(), $mail->to);
    }

    /**
     * Edge case: a child re-clicking the ShareLink of a trainer they're
     * already connected to gets the same block+notify, no carve-out --
     * D3's "no condition short-circuits it", read literally.
     */
    public function testAChildAlreadyConnectedToTheTrainerStillGetsBlockedOnReclick(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $parent = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('parent-b')));
        $childUser = $this->persist(UserFactory::activeVerified(UserRole::PLAYER, UserFactory::email('child-b')));
        $this->persistChildAccount($childUser, $parent);
        $link = $this->createLinkFor($trainer);

        /** @var PlayerShareLinkService $shareLinkService */
        $shareLinkService = self::getContainer()->get(PlayerShareLinkService::class);
        // Directly establish an existing active association -- the branch
        // this test proves is never a carve-out from the block.
        $shareLinkService->associateWithTrainer($childUser, $trainer, $link, $parent);

        $associationRepository = self::getContainer()->get(TrainerPlayerAssociationRepository::class);
        self::assertInstanceOf(TrainerPlayerAssociation::class, $associationRepository->findOneFor($trainer, $childUser));

        $this->client->loginUser($childUser);
        $this->client->request('GET', '/join/'.$link->getCode());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-message', 'Ask your parent');

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST, $mail->template);
    }

    private function createLinkFor(User $trainer): PlayerShareLink
    {
        /** @var PlayerShareLinkService $service */
        $service = self::getContainer()->get(PlayerShareLinkService::class);

        return $service->getOrCreateFor($trainer);
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
