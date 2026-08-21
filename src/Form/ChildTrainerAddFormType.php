<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\TrainerPlayerAssociationRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Task 27 (AC-8): connecting an *existing* child to one more trainer, by
 * either a ShareLink `code` or a direct pick from the parent's own "My
 * Trainers" list (built the same way `ChildProfileFormType`'s multi-trainer
 * shape is: `TrainerPlayerAssociationRepository::findActiveForPlayer($parent)`,
 * D2). No `data_class`, the same convention as every other input form in
 * this project: the controller builds the readonly `AddChildTrainerRequest`
 * from the submitted array itself.
 *
 * The `Assert\Callback` at the form level requires **exactly one** of
 * `shareLinkCode`/`trainerId` to be present -- neither or both is a
 * validation error. Resolving an unknown/inactive `shareLinkCode` (which
 * raises `ShareLinkUnavailableException`) is `Task 31`'s
 * `ChildTrainerController::add()` job (`PlayerShareLinkResolver::resolve()`),
 * not this form's -- this form only shapes and validates the two inputs.
 */
final class ChildTrainerAddFormType extends AbstractType
{
    public function __construct(
        private readonly TrainerPlayerAssociationRepository $associations,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $parent */
        $parent = $options['parent'];

        $associations = $this->associations->findActiveForPlayer($parent);

        $choices = [];

        foreach ($associations as $association) {
            $trainer = $association->getTrainer();
            $choices[$trainer->getDisplayName()] = (string) $trainer->getId();
        }

        $builder
            ->add('shareLinkCode', TextType::class, [
                'label' => 'Invitation code',
                'required' => false,
            ])
            ->add('trainerId', ChoiceType::class, [
                'label' => 'My trainers',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'Choose a trainer',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('parent')
            ->setAllowedTypes('parent', User::class)
            ->setDefaults([
                'constraints' => [
                    new Callback([self::class, 'validateExactlyOnePresent']),
                ],
            ])
        ;
    }

    /**
     * @param array{shareLinkCode?: ?string, trainerId?: ?string} $data
     */
    public static function validateExactlyOnePresent(array $data, ExecutionContextInterface $context): void
    {
        $hasCode = '' !== trim((string) ($data['shareLinkCode'] ?? ''));
        $hasTrainerId = '' !== trim((string) ($data['trainerId'] ?? ''));

        if ($hasCode === $hasTrainerId) {
            $context->buildViolation('Enter an invitation code, or choose a trainer -- not both, not neither.')
                ->atPath('shareLinkCode')
                ->addViolation();
        }
    }
}
