<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\UserRole;
use App\Form\ChildTrainerAddFormType;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Coverage for `ChildTrainerAddFormType` (Task 27, AC-8): the "exactly one
 * of shareLinkCode/trainerId" `Assert\Callback`, and that the trainer choice
 * list is built from the parent's own active associations.
 */
final class ChildTrainerAddFormTypeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FormFactoryInterface $formFactory;

    /** @var list<string> */
    private array $persistedUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->formFactory = $container->get(FormFactoryInterface::class);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM trainer_player_association WHERE trainer_id = :id OR player_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testNeitherFieldPresentIsInvalid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $form = $this->formFactory->create(ChildTrainerAddFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit(['shareLinkCode' => '', 'trainerId' => '']);

        self::assertFalse($form->isValid());
    }

    public function testBothFieldsPresentIsInvalid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $trainer = $this->persistUser(UserRole::TRAINER);
        $this->connect($trainer, $parent);

        $form = $this->formFactory->create(ChildTrainerAddFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit(['shareLinkCode' => 'ABC123', 'trainerId' => (string) $trainer->getId()]);

        self::assertFalse($form->isValid());
    }

    public function testOnlyShareLinkCodeIsValid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $form = $this->formFactory->create(ChildTrainerAddFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit(['shareLinkCode' => 'ABC123', 'trainerId' => '']);

        self::assertTrue($form->isValid());
    }

    public function testOnlyTrainerIdFromMyTrainersIsValid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $trainer = $this->persistUser(UserRole::TRAINER);
        $this->connect($trainer, $parent);

        $form = $this->formFactory->create(ChildTrainerAddFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit(['shareLinkCode' => '', 'trainerId' => (string) $trainer->getId()]);

        self::assertTrue($form->isValid());
        self::assertSame((string) $trainer->getId(), $form->get('trainerId')->getData());
    }

    public function testTrainerIdNotAmongMyTrainersIsInvalid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $stranger = $this->persistUser(UserRole::TRAINER);

        $form = $this->formFactory->create(ChildTrainerAddFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit(['shareLinkCode' => '', 'trainerId' => (string) $stranger->getId()]);

        self::assertFalse($form->isValid());
    }

    private function persistUser(UserRole $role): User
    {
        $user = UserFactory::activeVerified($role);
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $user->getId();

        return $user;
    }

    private function connect(User $trainer, User $player): void
    {
        $association = new TrainerPlayerAssociation($trainer, $player, null);
        $this->em->persist($association);
        $this->em->flush();
    }
}
