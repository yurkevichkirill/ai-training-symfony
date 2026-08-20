<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerShareLink;
use App\Entity\ProfilePlayer;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Self-registration via a player ShareLink (AC-7…AC-10), in
 * `TrainerOnboardingService::createTrainer()`'s exact two-phase shape --
 * see that class's docblock for the closed-EntityManager mechanics this
 * relies on verbatim:
 *
 * 1. `UserAccountService::create()` commits `$user` in its own, already-
 *    finished transaction. `EmailAlreadyInUseException` propagates
 *    unchanged to a field-level form error (AC-10); per that service's own
 *    contract its EntityManager must not be touched again once it throws.
 * 2. Set the common display fields on the returned (still-managed) user.
 * 3. A second transaction, on the manager `getManagerForClass()` still
 *    returns (open, unless step 1 threw): persist the `ProfilePlayer`,
 *    persist the `TrainerPlayerAssociation`, and issue an atomic `UPDATE
 *    player_share_link SET usage_count = usage_count + 1 WHERE id = :id`
 *    (via DQL, in the same transaction) -- deliberately NOT
 *    `$link->incrementUsage()` + `persist($link)`, which hydrates the
 *    counter, increments it in PHP, and lets the UnitOfWork flush a
 *    fully-computed literal value; two concurrent registrations against the
 *    same link would both read `usage_count = 0` and both flush a literal
 *    `1`, losing one increment (Task 32 hardening fix, AC-6; see
 *    `PlayerShareLinkService::associate()`'s identical fix and longer
 *    rationale). The user's mutated display fields ride along in the same
 *    flush because `$user` is still a managed entity, no explicit re-persist
 *    needed. Any failure here is compensated, not rolled back: step 1
 *    already committed, so the catch deletes the now-orphaned `User` row
 *    directly rather than leaving an unreachable account with a real
 *    password and no profile -- reachable by anyone, since `/join/{code}`
 *    is public (unlike `TrainerOnboardingService`'s Super-Admin-only path).
 *    That compensating delete is itself wrapped: a failure to delete is
 *    logged at `critical` (with the orphaned user id and the original
 *    exception) rather than lost silently or allowed to replace the
 *    original exception the caller needs to see (Task 38 hardening fix).
 * 4. After commit: issue a verification token, dispatch the one
 *    confirmation email (which *is* the verification email -- architecture
 *    Decisions Q5′), then record `PLAYER_REGISTERED_VIA_SHARE_LINK`
 *    (actor = subject = the new player).
 *
 * The controller redirects to a "check your email" page, not a trainer
 * context: the association exists, but sign-in stays refused until the
 * address is verified.
 */
final class PlayerRegistrationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserAccountService $userAccountService,
        private readonly EmailVerificationTokenService $emailVerificationTokenService,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerViaShareLink(PlayerRegistrationRequest $request, PlayerShareLink $link): User
    {
        $user = $this->userAccountService->create($request->email, $request->plainPassword, UserRole::PLAYER);
        $user->setName($request->firstName, $request->lastName);
        $user->setPhone($request->phone);

        $entityManager = $this->managerRegistry->getManagerForClass(User::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable();
        $profile = new ProfilePlayer($user, $request->playerName, $request->playerAge, $request->playerGender, $now);
        $association = new TrainerPlayerAssociation($link->getTrainer(), $user, $link, $now);

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $profile, $association, $link): void {
                $entityManager->persist($profile);
                $entityManager->persist($association);

                // Atomic, database-computed increment -- see this method's
                // docblock for why this is not `$link->incrementUsage()` +
                // `persist($link)`.
                $entityManager->createQueryBuilder()
                    ->update(PlayerShareLink::class, 'l')
                    ->set('l.usageCount', 'l.usageCount + 1')
                    ->where('l = :link')
                    ->setParameter('link', $link)
                    ->getQuery()
                    ->execute();
            });
        } catch (\Throwable $e) {
            // The first wrapInTransaction() call above (inside
            // UserAccountService::create()) already committed $user in its
            // OWN transaction; a failure here cannot roll that back. Do NOT
            // touch $entityManager again -- this second wrapInTransaction()
            // call closed it on the way out. Get a fresh manager from the
            // registry and delete the now-orphaned User row through its raw
            // connection instead of re-attaching a possibly-inconsistent
            // entity to a new EntityManager (same compensating-cleanup
            // shape as TrainerOnboardingService::createTrainer()).
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);

            // Task 38 hardening fix: the delete used to run unguarded --
            // a failure here logged nothing and, if the delete itself
            // threw, that new exception silently replaced the original
            // one the caller actually needs to see. Both are now handled:
            // logged at `critical` with the orphaned user id and the
            // original exception on failure, and the *original* exception
            // is always what propagates.
            try {
                $freshManager->getConnection()->executeStatement(
                    'DELETE FROM app_user WHERE id = :id',
                    ['id' => (string) $user->getId()],
                );
            } catch (\Throwable $deleteException) {
                $this->logger->critical('Failed to delete orphaned app_user row after a failed player registration.', [
                    'userId' => (string) $user->getId(),
                    'exception' => $e,
                    'deleteException' => $deleteException,
                ]);
            }

            throw $e;
        }

        $token = $this->emailVerificationTokenService->issue($user);

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $user->getEmail(),
            template: SendEmailMessage::TEMPLATE_PLAYER_WELCOME,
            context: [
                'token' => $token,
                'trainerName' => $link->getTrainer()->getDisplayName(),
            ],
        ));

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::PLAYER_REGISTERED_VIA_SHARE_LINK,
            actorUserId: $user->getId(),
            subjectUserId: $user->getId(),
        ));

        return $user;
    }
}
