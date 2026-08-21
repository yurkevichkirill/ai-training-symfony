<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\PlayerGender;
use App\Enum\UserRole;
use App\Form\ChildProfileFormType;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Coverage for `ChildProfileFormType` (Task 26, AC-1, AC-3, AC-4, AC-5,
 * AC-6): basic constraint validation, the duplicate/AC-3 shape logic, and
 * the trainerIds field's three render shapes -- absent for zero active
 * trainers, a `CheckboxType` for exactly one, a `ChoiceType` checkbox list
 * for more than one, re-validated against that same active set (a forged
 * id is refused).
 */
final class ChildProfileFormTypeTest extends KernelTestCase
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

    public function testValidSubmissionWithNoActiveTrainersOmitsTheTrainerField(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        self::assertFalse($form->has('trainerIds'));

        $form->submit([
            'childName' => 'Jamie',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
            'school' => 'Oakwood Elementary',
        ]);

        self::assertTrue($form->isValid());
    }

    public function testExactlyOneActiveTrainerRendersAsACheckbox(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $trainer = $this->persistUser(UserRole::TRAINER);
        $this->connect($trainer, $parent);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        self::assertTrue($form->has('trainerIds'));
        self::assertSame('checkbox', $form->get('trainerIds')->getConfig()->getType()->getBlockPrefix());

        $form->submit([
            'childName' => 'Jamie',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
            'trainerIds' => '1',
        ]);

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('trainerIds')->getData());
    }

    public function testMoreThanOneActiveTrainerRendersAsAChoiceListAndRejectsAForgedId(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $trainerOne = $this->persistUser(UserRole::TRAINER);
        $trainerTwo = $this->persistUser(UserRole::TRAINER);
        $stranger = $this->persistUser(UserRole::TRAINER);
        $this->connect($trainerOne, $parent);
        $this->connect($trainerTwo, $parent);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        self::assertTrue($form->has('trainerIds'));
        self::assertSame('choice', $form->get('trainerIds')->getConfig()->getType()->getBlockPrefix());

        // A forged trainer id (not one of the parent's own active
        // associations) must be refused, not silently connected.
        $form->submit([
            'childName' => 'Jamie',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
            'trainerIds' => [(string) $stranger->getId()],
        ]);

        self::assertFalse($form->isValid());
    }

    public function testMoreThanOneActiveTrainerAcceptsARealSelection(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);
        $trainerOne = $this->persistUser(UserRole::TRAINER);
        $trainerTwo = $this->persistUser(UserRole::TRAINER);
        $this->connect($trainerOne, $parent);
        $this->connect($trainerTwo, $parent);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit([
            'childName' => 'Jamie',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
            'trainerIds' => [(string) $trainerOne->getId()],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame([(string) $trainerOne->getId()], $form->get('trainerIds')->getData());
    }

    public function testAgeOutsideOneToEighteenIsInvalid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit([
            'childName' => 'Jamie',
            'age' => '19',
            'gender' => PlayerGender::OTHER->value,
        ]);

        self::assertFalse($form->isValid());
    }

    public function testBlankNameIsInvalid(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit([
            'childName' => '',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
        ]);

        self::assertFalse($form->isValid());
    }

    public function testDuplicateAcknowledgedIsAPlainOptionalBooleanField(): void
    {
        $parent = $this->persistUser(UserRole::PLAYER);

        $form = $this->formFactory->create(ChildProfileFormType::class, null, ['parent' => $parent, 'csrf_protection' => false]);

        $form->submit([
            'childName' => 'Jamie',
            'age' => '9',
            'gender' => PlayerGender::OTHER->value,
            'duplicateAcknowledged' => '1',
        ]);

        self::assertTrue($form->isValid());
        self::assertTrue($form->get('duplicateAcknowledged')->getData());
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
