<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\PlayerGender;
use App\Repository\TrainerPlayerAssociationRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Task 26 (AC-1, AC-3, AC-4, AC-5, AC-6): the "add a child" form over
 * `CreateChildRequest`. No `data_class`, the same convention every other
 * input form in this project already follows (see `CreateTrainerFormType`'s
 * docblock): the controller builds the readonly DTO from the submitted
 * array itself, since a readonly DTO with required constructor arguments
 * has no writable properties for the form component's default
 * `PropertyAccessor`-based mapping to target.
 *
 * `photo` reuses `FileStorage`'s own limits (5 MB, jpeg/png/webp) as an
 * `Assert\Image` constraint -- this project has no earlier *form-level*
 * photo constraint to copy verbatim (S2's `ProfileController::uploadPhoto()`
 * validates the raw `UploadedFile` through `FileStorage::store()` instead of
 * a Symfony Form), so this is the closest equivalent, not a literal reuse.
 *
 * `trainerIds` (AC-3's three shapes) is built from the parent's own active
 * `TrainerPlayerAssociation`s (`TrainerPlayerAssociationRepository::findActiveForPlayer()`,
 * D2) and rendered three ways depending on how many that parent has:
 *
 * - zero: the field is not added to the form at all.
 * - exactly one: a single Yes/No `CheckboxType` -- there is only one
 *   possible id, so there is nothing to forge; the field carries a bool,
 *   and the one trainer's id is implicit for whichever controller acts on
 *   the checked value.
 * - more than one: a `ChoiceType` (`multiple`, `expanded` -- a checkbox
 *   list), `choices` built from that same active set, so a submitted id
 *   outside it fails form validation as an invalid choice rather than being
 *   silently connected (a forged trainer id is refused, not connected).
 *
 * `duplicateAcknowledged` (BR-019, edge case: name/age close to an existing
 * child) is never a validation constraint -- `ChildAccountService::findSimilar()`
 * runs on submit *outside* this form (the owning controller's job): a hit
 * re-renders the form with a warning and this now-set hidden field, and the
 * next submit proceeds. This form only carries the field; it does not
 * decide when to warn.
 */
final class ChildProfileFormType extends AbstractType
{
    private const MAX_PHOTO_BYTES = '5M';

    /** @var list<string> content-sniffed MIME types `FileStorage` accepts */
    private const ALLOWED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly TrainerPlayerAssociationRepository $associations,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $parent */
        $parent = $options['parent'];

        $builder
            ->add('childName', TextType::class, [
                'label' => "Child's name",
                'constraints' => [new NotBlank(), new Length(max: 160)],
            ])
            ->add('age', IntegerType::class, [
                'label' => "Child's age",
                'constraints' => [new NotNull(), new Range(min: 1, max: 18)],
            ])
            ->add('gender', ChoiceType::class, [
                'label' => "Child's gender",
                'choices' => PlayerGender::cases(),
                'choice_label' => static fn (PlayerGender $gender): string => $gender->name,
                'choice_value' => static fn (?PlayerGender $gender): string => $gender?->value ?? '',
                'constraints' => [new NotNull(), new Choice(choices: PlayerGender::cases())],
            ])
            ->add('school', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 160)],
            ])
            ->add('photo', FileType::class, [
                'required' => false,
                'constraints' => [new Image(maxSize: self::MAX_PHOTO_BYTES, mimeTypes: self::ALLOWED_PHOTO_MIME_TYPES)],
            ])
            ->add('duplicateAcknowledged', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ])
        ;

        $this->addTrainerField($builder, $parent);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('parent')
            ->setAllowedTypes('parent', User::class)
        ;
    }

    private function addTrainerField(FormBuilderInterface $builder, User $parent): void
    {
        $associations = $this->associations->findActiveForPlayer($parent);
        $trainers = array_map(static fn ($association): User => $association->getTrainer(), $associations);

        if ([] === $trainers) {
            return;
        }

        if (1 === \count($trainers)) {
            $builder->add('trainerIds', CheckboxType::class, [
                'label' => \sprintf('Will your child also train with %s?', $trainers[0]->getDisplayName()),
                'required' => false,
            ]);

            return;
        }

        $choices = [];

        foreach ($trainers as $trainer) {
            $choices[$trainer->getDisplayName()] = (string) $trainer->getId();
        }

        $builder->add('trainerIds', ChoiceType::class, [
            'label' => 'Also train with',
            'choices' => $choices,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);
    }
}
