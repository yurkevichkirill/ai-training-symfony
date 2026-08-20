<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountInvitation;
use App\Entity\ProfileTrainer;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates a trainer account the way only a Super Admin may (AC-4, AC-8,
 * BR-005): a `User` with an unusable placeholder password, a `ProfileTrainer`,
 * and an `AccountInvitation` the trainer uses to set their own real password
 * (AC-5) -- see the architecture doc's G-10 resolution for why an invitation
 * link, not a temporary password, was chosen.
 *
 * `EmailAlreadyInUseException` from `UserAccountService::create()` propagates
 * unchanged; per that service's own contract, its EntityManager must not be
 * touched again once it throws (see `UserAccountService`'s class docblock).
 *
 * `UserAccountService::create()` commits `$user` in its own, already-finished
 * transaction before this method ever opens its own second one for the
 * `ProfileTrainer`/`AccountInvitation` pair. Any failure in that second
 * transaction is therefore compensated for, not rolled back: the catch below
 * deletes the now-orphaned `User` row directly rather than leaving an
 * unreachable account with a random placeholder password and no profile.
 */
final class TrainerOnboardingService
{
    private const INVITATION_TTL = 'P7D';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserAccountService $userAccountService,
        private readonly SelectorVerifierTokenFactory $tokenFactory,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function createTrainer(CreateTrainerRequest $request, User $actor): User
    {
        // Random, never-disclosed, never-submittable: there is no password
        // to change on first login because there was never one to begin
        // with (architecture doc's Decisions table).
        $placeholderPassword = base64_encode(random_bytes(32));

        $user = $this->userAccountService->create($request->email, $placeholderPassword, UserRole::TRAINER);
        $user->setName($request->firstName, $request->lastName);
        $user->setPhone($request->phone);

        $entityManager = $this->managerRegistry->getManagerForClass(User::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable();
        $profile = new ProfileTrainer($user, $request->businessName, $now);

        $pair = $this->tokenFactory->generate();
        $expiresAt = $now->add(new \DateInterval(self::INVITATION_TTL));
        $invitation = new AccountInvitation($user, $actor, $pair->selector, $pair->hashedVerifier, $expiresAt, $now);

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $profile, $invitation): void {
                $entityManager->persist($profile);
                $entityManager->persist($invitation);
            });
        } catch (\Throwable $e) {
            // The first wrapInTransaction() call above (inside
            // UserAccountService::create()) already committed $user in its
            // OWN transaction (see this class's docblock): a failure here
            // cannot roll that back. Do NOT touch $entityManager again --
            // this second wrapInTransaction() call closed it on the way out
            // (same closed-EntityManager mechanics UserAccountService::create()'s
            // own catch block documents at length) -- get a fresh manager
            // from the registry and delete the now-orphaned User row
            // through its raw connection instead of re-attaching a
            // possibly-inconsistent entity to a new EntityManager.
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);
            $freshManager->getConnection()->executeStatement(
                'DELETE FROM app_user WHERE id = :id',
                ['id' => (string) $user->getId()],
            );

            throw $e;
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::TRAINER_CREATED,
            actorUserId: $actor->getId(),
            subjectUserId: $user->getId(),
            context: ['businessName' => $request->businessName],
        ));

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $user->getEmail(),
            template: SendEmailMessage::TEMPLATE_TRAINER_INVITATION,
            context: ['token' => $pair->token],
        ));

        return $user;
    }
}
