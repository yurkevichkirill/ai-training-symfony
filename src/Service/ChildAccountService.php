<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountInvitation;
use App\Entity\ChildAccount;
use App\Entity\ProfilePlayer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\ChildAccountRepository;
use App\Repository\ProfileRepository;
use App\Repository\UserRepository;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\Exception\ShareLinkUnavailableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Child account creation and the family-list reads around it (S4, AC-1,
 * AC-2, AC-4, AC-5, AC-6, AC-7; D1c, D1d).
 *
 * **`createChild()`** follows `TrainerOnboardingService::createTrainer()`'s
 * established two-phase shape -- see that class's docblock for the
 * closed-EntityManager mechanics this relies on verbatim -- with
 * `PlayerRegistrationService::registerViaShareLink()`'s extra discipline of
 * logging (rather than losing) a failed compensating delete:
 *
 * 1. `UserAccountService::create()` commits a `User` (`role = PLAYER`, D1) in
 *    its own, already-finished transaction, with a *bootstrap* placeholder
 *    email and a random, never-disclosed, never-submittable password
 *    (`base64_encode(random_bytes(32))`, hashed and immediately discarded --
 *    nobody, including the parent, ever holds a credential for this
 *    account). The bootstrap value exists only because `create()` needs
 *    *some* unique, well-formed email up front and `User::__construct()`
 *    mints the account's real id itself -- there is no way to know that id
 *    before this call returns. Nothing is ever sent to either value (no mail
 *    goes out from this method).
 * 2. Everything else -- correcting the placeholder to the one truly derived
 *    from the account's own, now-real id (D1c), the display name, the
 *    optional photo, the `ProfilePlayer`, the `ChildAccount`, and one
 *    `ChildTrainerService::connect()` call per selected trainer id (AC-4) --
 *    is guarded by one `catch (\Throwable)`. Any failure compensates rather
 *    than rolls back (step 1 already committed): `ManagerRegistry::resetManager()`
 *    for the same closed-EntityManager reason `UserAccountService`'s own
 *    docblock documents, then a raw `DELETE FROM app_user WHERE id = :id`
 *    (cascading away the `ProfilePlayer`/`ChildAccount`/any already-connected
 *    `TrainerPlayerAssociation` row), logged at `critical` if that delete
 *    itself fails, and the *original* exception rethrown either way (Task 38's
 *    hardening fix, applied here from the start rather than added later).
 *    A stored photo is likewise deleted on this path.
 * 3. Post-commit: `AccountEventRecorder::record(CHILD_ACCOUNT_CREATED)`
 *    (actor = parent, subject = child, context `{trainerCount}`). No email.
 *
 * **`enableSignIn()`** (D1d) replaces the placeholder with a real,
 * platform-unique address and issues an `AccountInvitation` through
 * `SelectorVerifierTokenFactory`, exactly as `TrainerOnboardingService::createTrainer()`
 * does for a trainer -- reusing S2's already-shipped `/invitations/{token}`
 * set-your-password flow rather than inventing new credential machinery.
 * `EmailAlreadyInUseException` surfaces as a field error: this form is
 * authenticated and parent-only, so S3's public-endpoint enumeration concern
 * (`TEMPLATE_DUPLICATE_REGISTRATION_ATTEMPT`) does not apply here.
 */
final class ChildAccountService
{
    private const INVITATION_TTL = 'P7D';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserAccountService $userAccountService,
        private readonly ChildEmailFactory $childEmailFactory,
        private readonly UserRepository $userRepository,
        private readonly ChildAccountRepository $childAccountRepository,
        private readonly ProfileRepository $profileRepository,
        private readonly ChildTrainerService $childTrainerService,
        private readonly SelectorVerifierTokenFactory $tokenFactory,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
        private readonly FileStorage $fileStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * AC-1, AC-2, AC-4, AC-5, AC-6. See this class's own docblock for the
     * two-phase shape and its compensating-delete discipline.
     */
    public function createChild(User $parent, CreateChildRequest $request): ChildAccount
    {
        $unusableSecret = base64_encode(random_bytes(32));
        $bootstrapEmail = $this->childEmailFactory->forChild(new UuidV7());

        $childUser = $this->userAccountService->create($bootstrapEmail, $unusableSecret, UserRole::PLAYER);

        $photoKey = null;

        try {
            // D1c: correct the bootstrap placeholder above to the one truly
            // derived from this account's own, now-real id.
            $childUser->setEmail($this->childEmailFactory->forChild($childUser->getId()));
            $childUser->setName($request->childName, null);

            $trainers = $this->resolveTrainers($request->trainerIds);

            if (null !== $request->photo) {
                $photoKey = $this->fileStorage->store($request->photo, 'photos');
                $childUser->setPhotoKey($photoKey);
            }

            $now = new \DateTimeImmutable();
            $profile = new ProfilePlayer($childUser, $request->childName, $request->age, $request->gender, $now);
            $profile->setSchool($request->school);
            $childAccount = new ChildAccount($childUser, $parent, $now);

            $entityManager = $this->managerRegistry->getManagerForClass(User::class);
            \assert($entityManager instanceof EntityManagerInterface);

            $entityManager->wrapInTransaction(function () use ($entityManager, $profile, $childAccount): void {
                $entityManager->persist($profile);
                $entityManager->persist($childAccount);
            });

            foreach ($trainers as $trainer) {
                $this->childTrainerService->connect($parent, $childAccount, $trainer, null);
            }
        } catch (\Throwable $e) {
            // Do NOT touch any EntityManager instance obtained above again --
            // if wrapInTransaction() ran and threw, it already closed it (see
            // UserAccountService's docblock); if the failure happened earlier
            // (trainer resolution, photo validation), resetting anyway keeps
            // one recovery path for both cases, at no cost.
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);

            try {
                // A trainer connected earlier in the foreach above (if any)
                // already recorded its own CHILD_TRAINER_CONNECTED
                // `AccountEvent` through `AccountEventRecorder`'s
                // independent physical connection -- a genuinely separate,
                // already-committed transaction unaffected by this
                // method's own failure. `account_event.subject_user_id`/
                // `actor_user_id` are `ON DELETE RESTRICT` (deliberately,
                // for an account that goes on existing), so those rows
                // must go first or the compensating delete below is refused
                // by the database -- the same order every test in this
                // suite's own teardown already follows.
                $freshManager->getConnection()->executeStatement(
                    'DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id',
                    ['id' => (string) $childUser->getId()],
                );
                $freshManager->getConnection()->executeStatement(
                    'DELETE FROM app_user WHERE id = :id',
                    ['id' => (string) $childUser->getId()],
                );
            } catch (\Throwable $deleteException) {
                $this->logger->critical('Failed to delete orphaned app_user row after a failed child account creation.', [
                    'userId' => (string) $childUser->getId(),
                    'exception' => $e,
                    'deleteException' => $deleteException,
                ]);
            }

            if (null !== $photoKey) {
                $this->fileStorage->delete($photoKey);
            }

            throw $e;
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::CHILD_ACCOUNT_CREATED,
            actorUserId: $parent->getId(),
            subjectUserId: $childUser->getId(),
            context: ['trainerCount' => \count($request->trainerIds)],
        ));

        return $childAccount;
    }

    /**
     * D1d. Replaces the placeholder email with a real, platform-unique one
     * and issues an `AccountInvitation` through `SelectorVerifierTokenFactory`
     * -- exactly as `TrainerOnboardingService::createTrainer()` does -- so
     * the child can set their own password through S2's `/invitations/{token}`
     * flow. No new credential machinery.
     *
     * @throws EmailAlreadyInUseException another account already holds `$email`
     */
    public function enableSignIn(User $parent, ChildAccount $child, string $email, ?\DateTimeImmutable $now): void
    {
        $now ??= new \DateTimeImmutable();
        $childUser = $child->getChildUser();

        $entityManager = $this->openEntityManager();

        $childUser->setEmail($email);
        $child->enableSignIn($now);

        $pair = $this->tokenFactory->generate();
        $expiresAt = $now->add(new \DateInterval(self::INVITATION_TTL));
        $invitation = new AccountInvitation($childUser, $parent, $pair->selector, $pair->hashedVerifier, $expiresAt, $now);

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $invitation): void {
                $entityManager->persist($invitation);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Do NOT touch $entityManager again -- wrapInTransaction() above
            // already closed it (see UserAccountService's docblock). The
            // next call gets a working manager from openEntityManager(),
            // which detects the closed instance and asks the registry to
            // reset it.
            throw new EmailAlreadyInUseException($email, $e);
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::CHILD_SIGN_IN_ENABLED,
            actorUserId: $parent->getId(),
            subjectUserId: $childUser->getId(),
        ));

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $childUser->getEmail(),
            template: SendEmailMessage::TEMPLATE_CHILD_SIGN_IN_INVITATION,
            context: [
                'token' => $pair->token,
                'childName' => $childUser->getDisplayName(),
            ],
        ));
    }

    /**
     * AC-7: every child this parent created, newest first.
     *
     * @return list<ChildAccount>
     */
    public function findChildrenOf(User $parent): array
    {
        return $this->childAccountRepository->findChildrenOf($parent);
    }

    /**
     * BR-019's duplicate check: a name/age combination "close" to an
     * existing child of this parent. Used only for a soft warning -- never
     * to block saving (edge case: name/age close to an existing child).
     * "Close" is same (case/whitespace-insensitive) declared name and a
     * declared age within one year.
     *
     * @return list<ChildAccount>
     */
    public function findSimilar(User $parent, string $name, int $age): array
    {
        $children = $this->childAccountRepository->findChildrenOf($parent);

        if ([] === $children) {
            return [];
        }

        $childUsers = array_map(static fn (ChildAccount $child): User => $child->getChildUser(), $children);
        $profiles = $this->profileRepository->findPlayerProfilesFor($childUsers);
        $normalizedName = mb_strtolower(trim($name), 'UTF-8');

        return array_values(array_filter(
            $children,
            static function (ChildAccount $child) use ($profiles, $normalizedName, $age): bool {
                $profile = $profiles[$child->getChildUser()->getId()->toRfc4122()] ?? null;

                if (!$profile instanceof ProfilePlayer) {
                    return false;
                }

                $sameName = $normalizedName === mb_strtolower(trim($profile->getPlayerName()), 'UTF-8');
                $closeAge = 1 >= abs($profile->getDeclaredAge() - $age);

                return $sameName && $closeAge;
            },
        ));
    }

    /**
     * @param list<string> $trainerIds
     *
     * @return list<User>
     *
     * @throws ShareLinkUnavailableException an id is not a well-formed Uuid or
     *                                        does not name an existing account
     *                                        -- `ChildTrainerService::connect()`
     *                                        is what checks role/active-ness
     *                                        once the trainer is resolved
     */
    private function resolveTrainers(array $trainerIds): array
    {
        $trainers = [];

        foreach ($trainerIds as $trainerId) {
            if (!Uuid::isValid($trainerId)) {
                throw new ShareLinkUnavailableException();
            }

            $trainer = $this->userRepository->find($trainerId);

            if (!$trainer instanceof User) {
                throw new ShareLinkUnavailableException();
            }

            $trainers[] = $trainer;
        }

        return $trainers;
    }

    /**
     * Same recovery pattern as `UserAccountService`/`ChildTrainerService`'s
     * own `openEntityManager()`: detect a manager a previous call left
     * closed and ask the registry to reset it, rather than ever reusing a
     * closed instance.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(User::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', User::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
