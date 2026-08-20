<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachInvitation;
use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\CoachInvitationRepository;
use App\Service\Exception\CoachInvitationAlreadyAcceptedException;
use App\Service\Exception\CoachInvitationExpiredException;
use App\Service\Exception\InvalidCoachInvitationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Self-registration from a coach invitation (AC-14, AC-15), for a Coach with
 * no account yet -- in `PlayerRegistrationService::registerViaShareLink()`'s
 * exact two-phase shape (see that class's docblock for the closed-
 * EntityManager mechanics this relies on verbatim):
 *
 * 1. `UserAccountService::create()` commits `$user` in its own,
 *    already-finished transaction, with the email taken from
 *    `$invitation->getInvitedEmail()` -- **never** from `$request`, which
 *    has no `email` field at all (architecture Decisions Q4). An
 *    `EmailAlreadyInUseException` here means the coach already has an
 *    account; the controller renders "sign in and open this link again",
 *    after which AC-21's equality guard on the signed-in `accept()` path
 *    guarantees this same link only completes for the matching address.
 * 2. Set the common display fields on the returned (still-managed) user.
 * 3. A second transaction, on the manager `getManagerForClass()` still
 *    returns (open, unless step 1 threw): re-locks the invitation row by
 *    selector (`SELECT ... FOR UPDATE`, the same single-use/AC-16
 *    discipline `CoachInvitationService::accept()` uses -- see that
 *    class's docblock), then creates the `TrainerCoachAssociation` and
 *    marks the invitation accepted. Any failure here is compensated, not
 *    rolled back: step 1 already committed, so the catch deletes the
 *    now-orphaned `User` row directly, exactly as
 *    `PlayerRegistrationService::registerViaShareLink()` and
 *    `TrainerOnboardingService::createTrainer()` do -- reachable by
 *    anyone, since `/coach-invitation/{token}` is public.
 * 4. After commit: issue a verification token, dispatch
 *    `TEMPLATE_COACH_WELCOME`, then record `COACH_INVITATION_ACCEPTED`
 *    (actor = subject = the new coach).
 *
 * Role and email integrity (AC-21) are structural here, not checked: the
 * new account is always created with `UserRole::COACH` and the invitation's
 * own address, so a mismatch is unrepresentable rather than merely refused.
 *
 * **Task 38 hardening fix.** Step 3 used to also look up
 * `TrainerCoachAssociationRepository::findActiveForCoach($user)` and branch
 * on an existing active association for this coach, mirroring
 * `CoachInvitationService::accept()`'s own "same trainer reuses it,
 * different trainer refuses" shape. That lookup could never return
 * non-null here: `$user` was created moments earlier in this very method
 * (step 1, above), so no `TrainerCoachAssociation` referencing it can
 * possibly exist yet -- nothing else has ever seen this user id. The dead
 * branch (and the now-unused `TrainerCoachAssociationRepository` dependency
 * and `CoachAlreadyActiveElsewhereException` it existed to support) is
 * removed; the three checks that can actually fire against a brand-new
 * coach are: the invitation itself must resolve (null/invalid), must not
 * be expired, and must not already be accepted (by someone else's earlier
 * request against this same link -- the "two devices on one link" edge
 * case, still real for a fresh registration).
 */
final class CoachRegistrationService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserAccountService $userAccountService,
        private readonly CoachInvitationRepository $invitationRepository,
        private readonly EmailVerificationTokenService $emailVerificationTokenService,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registerAndAccept(CoachRegistrationRequest $request, CoachInvitation $invitation): User
    {
        $user = $this->userAccountService->create($invitation->getInvitedEmail(), $request->plainPassword, UserRole::COACH);
        $user->setName($request->firstName, $request->lastName);
        $user->setPhone($request->phone);

        $entityManager = $this->managerRegistry->getManagerForClass(User::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $selector = $invitation->getSelector();

        try {
            /** @var array{0: TrainerCoachAssociation, 1: string, 2: string} $result */
            $result = $entityManager->wrapInTransaction(function () use ($entityManager, $selector, $user): array {
                // Re-lock the invitation row within this second transaction
                // -- $invitation, as passed into this method, may have been
                // read before this transaction (or even this request's
                // manager) existed; the FOR UPDATE re-read is what serializes
                // this call against a concurrent CoachInvitationService::
                // accept() or registerAndAccept() call on the exact same
                // token (edge case: two devices on one link).
                $freshInvitation = $this->invitationRepository->findOneBySelectorForUpdate($selector);

                if (null === $freshInvitation) {
                    throw new InvalidCoachInvitationException();
                }

                $now = new \DateTimeImmutable();

                if ($freshInvitation->isExpired($now)) {
                    throw new CoachInvitationExpiredException();
                }

                // Task 38 hardening fix: no active-association lookup here
                // any more -- see the class docblock for why `$user`, just
                // created a few lines above, can never already have one.
                // "Already accepted" therefore always means someone else's
                // earlier request against this same link (two devices on
                // one link), never this coach's own idempotent replay.
                if ($freshInvitation->isAccepted()) {
                    throw new CoachInvitationAlreadyAcceptedException();
                }

                $trainer = $freshInvitation->getTrainer();
                $trainerId = (string) $trainer->getId();
                $invitationId = (string) $freshInvitation->getId();

                $association = new TrainerCoachAssociation($trainer, $user, $freshInvitation, $now);
                $freshInvitation->accept($now);
                $entityManager->persist($freshInvitation);
                $entityManager->persist($association);

                return [$association, $trainerId, $invitationId];
            });
        } catch (\Throwable $e) {
            // The first wrapInTransaction() call above (inside
            // UserAccountService::create()) already committed $user in its
            // OWN transaction; a failure here cannot roll that back. Do NOT
            // touch $entityManager again -- this second wrapInTransaction()
            // call closed it on the way out (same closed-EntityManager
            // mechanics UserAccountService::create()'s own catch block
            // documents at length). Get a fresh manager from the registry
            // and delete the now-orphaned User row through its raw
            // connection instead of re-attaching a possibly-inconsistent
            // entity to a new EntityManager.
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);

            // Task 38 hardening fix: the compensating delete used to run
            // unguarded -- a failure here logged nothing (an orphaned,
            // unreachable `app_user` row with a real password could go
            // unnoticed indefinitely) and, if the delete itself threw,
            // that new exception silently replaced the original one the
            // caller actually needs to see. Both are now handled: the
            // delete is logged at `critical` with the orphaned user id and
            // the original exception on failure, and the *original*
            // exception is always what propagates.
            try {
                $freshManager->getConnection()->executeStatement(
                    'DELETE FROM app_user WHERE id = :id',
                    ['id' => (string) $user->getId()],
                );
            } catch (\Throwable $deleteException) {
                $this->logger->critical('Failed to delete orphaned app_user row after a failed coach registration.', [
                    'userId' => (string) $user->getId(),
                    'exception' => $e,
                    'deleteException' => $deleteException,
                ]);
            }

            throw $e;
        }

        [, $trainerId, $invitationId] = $result;

        $token = $this->emailVerificationTokenService->issue($user);

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $user->getEmail(),
            template: SendEmailMessage::TEMPLATE_COACH_WELCOME,
            context: ['token' => $token],
        ));

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::COACH_INVITATION_ACCEPTED,
            actorUserId: $user->getId(),
            subjectUserId: $user->getId(),
            context: [
                'trainerId' => $trainerId,
                'invitationId' => $invitationId,
            ],
        ));

        return $user;
    }
}
