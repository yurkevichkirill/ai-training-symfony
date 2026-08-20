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
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\Exception\CoachAlreadyActiveElsewhereException;
use App\Service\Exception\CoachInvitationAlreadyAcceptedException;
use App\Service\Exception\CoachInvitationEmailMismatchException;
use App\Service\Exception\CoachInvitationExpiredException;
use App\Service\Exception\InvalidCoachInvitationException;
use App\Service\Exception\ShareLinkUnavailableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A trainer's single-use, seven-day invitation to a Coach addressed by email
 * (AC-3, AC-5, AC-17, AC-18, AC-21) -- issue it, resolve a token back to its
 * row for the public landing page, and accept it into a
 * `TrainerCoachAssociation` for an already-signed-in Coach.
 *
 * **The closed-EntityManager pitfall** `accept()` follows is the same one
 * `PlayerShareLinkService`/`UserAccountService` document at length: a
 * `UniqueConstraintViolationException` escaping `wrapInTransaction()` leaves
 * that EntityManager instance permanently closed, so the catch block below
 * recovers via `ManagerRegistry::resetManager()` rather than ever reusing
 * the closed instance -- and, unlike `PlayerShareLinkService`, never needs
 * to re-read a winning row afterwards: on the partial unique index
 * `uniq_trainer_coach_active_coach`, the loser of that race is *always* a
 * refusal (`CoachAlreadyActiveElsewhereException`), never an idempotent
 * success, because the pre-check just above the insert already established
 * this coach had no active association at all.
 */
final class CoachInvitationService
{
    private const INVITATION_TTL = 'P7D';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CoachInvitationRepository $invitationRepository,
        private readonly TrainerCoachAssociationRepository $associationRepository,
        private readonly SelectorVerifierTokenFactory $tokenFactory,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * AC-5, AC-19: generates the selector/verifier pair, sets
     * `expiresAt = now + P7D`, persists, then (post-commit) dispatches the
     * invitation email carrying the trainer's optional personal message.
     *
     * **No `AccountEvent` is recorded here** -- `account_event.subject_user_id`
     * is NOT NULL and no `User` exists yet for the invited address (see the
     * architecture doc's Decisions table). The `CoachInvitation` row is
     * itself the record of "an invitation was sent".
     */
    public function invite(CoachInvitationRequest $request, User $trainer): CoachInvitation
    {
        $entityManager = $this->openEntityManager();

        $pair = $this->tokenFactory->generate();
        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval(self::INVITATION_TTL));

        $invitation = new CoachInvitation(
            $trainer,
            User::normalizeEmail($request->email),
            $request->name,
            $request->message,
            $pair->selector,
            $pair->hashedVerifier,
            $expiresAt,
            $now,
        );

        $entityManager->wrapInTransaction(static function () use ($entityManager, $invitation): void {
            $entityManager->persist($invitation);
        });

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $invitation->getInvitedEmail(),
            template: SendEmailMessage::TEMPLATE_COACH_INVITATION,
            context: [
                'token' => $pair->token,
                'trainerName' => $trainer->getDisplayName(),
                'message' => $invitation->getMessage() ?? '',
            ],
        ));

        return $invitation;
    }

    /**
     * Resolves a `/coach-invitation/{token}` token to its `CoachInvitation`
     * row for the public landing page (AC-3, AC-14, AC-18, edge case:
     * trainer deactivated/deleted). A plain read -- no transaction, no row
     * lock; `accept()` re-verifies everything from scratch inside its own
     * transaction and does not depend on this method's result.
     *
     * Guard order: token shape/hash first (non-enumerating -- an unknown
     * selector and a hash mismatch are indistinguishable); then the
     * inviting trainer's status, so a deactivated/deleted trainer renders
     * the same "no longer available" outcome
     * {@see PlayerShareLinkResolver::resolve()} uses, regardless of the
     * invitation's own accepted/expired state; then accepted (AC-18's
     * "already used" -- checked ahead of expiry so it agrees with
     * `CoachInvitation::status()`'s own "accepted takes priority over the
     * clock" rule); then expired (AC-18's "expired").
     *
     * @throws InvalidCoachInvitationException      malformed token, unknown
     *                                                selector, or verifier
     *                                                mismatch
     * @throws ShareLinkUnavailableException         the inviting trainer is
     *                                                no longer `ACTIVE`
     * @throws CoachInvitationAlreadyAcceptedException already used
     * @throws CoachInvitationExpiredException       more than 7 days old
     */
    public function resolve(string $token): CoachInvitation
    {
        $parts = SelectorVerifierTokenFactory::split($token);

        if (null === $parts) {
            throw new InvalidCoachInvitationException();
        }

        [$selector, $verifier] = $parts;

        $invitation = $this->invitationRepository->findOneBySelectorWithTrainer($selector);

        if (null === $invitation || !hash_equals($invitation->getHashedVerifier(), SelectorVerifierTokenFactory::hash($verifier))) {
            throw new InvalidCoachInvitationException();
        }

        if (!$invitation->getTrainer()->isActive()) {
            throw new ShareLinkUnavailableException();
        }

        if ($invitation->isAccepted()) {
            throw new CoachInvitationAlreadyAcceptedException();
        }

        if ($invitation->isExpired(new \DateTimeImmutable())) {
            throw new CoachInvitationExpiredException();
        }

        return $invitation;
    }

    /**
     * One transaction on a fresh manager, mirroring
     * `AccountInvitationService::consume()`'s identity-map warm-up exactly
     * (steps below are numbered to match the architecture doc's six-step
     * sequence for this method):
     *
     * 1. Warm the identity map with the invitation's `trainer` (a plain,
     *    top-level `find()` by the scalar FK, never touching the
     *    association), then `SELECT ... FOR UPDATE` by selector -- two
     *    devices on the same link serialize here; the loser sees the
     *    already-updated row once its own lock is granted.
     * 2. `hash_equals` the verifier -- refuse if it doesn't match, before
     *    anything else is inspected. Immediately followed by a
     *    belt-and-braces re-check that the inviting trainer is still
     *    `ACTIVE` (Task 38 hardening fix) -- the same guard
     *    `PlayerShareLinkService::associate()` has, in case this method is
     *    ever reached by a path other than the controller's prior
     *    `resolve()` call, which already enforces this.
     * 3. AC-21: refuse unless the signed-in account is a `COACH` whose
     *    (already normalized) email equals the invitation's
     *    `invitedEmail` -- refuses "different email" and "signed-in Player"
     *    alike, and must run before the accepted/expired checks below so a
     *    mismatched caller can never ride an accepted invitation's
     *    idempotent branch to someone else's association.
     * 4. If `acceptedAt` is already set: an active association for this
     *    exact `(trainer, coach)` pair is an idempotent success (the "coach
     *    re-follows their own accepted link" edge case); otherwise AC-18's
     *    "already used" refusal. Checked **before** expiry, deliberately:
     *    an invitation accepted on day 2 and re-followed on day 10 must
     *    still succeed idempotently, the same "accepted takes priority over
     *    the clock" rule `CoachInvitation::status()` and `resolve()` already
     *    apply -- `accept()` disagreeing with them here was a bug caught
     *    during implementation review, not a deliberate design choice.
     * 5. Only reached for a still-pending invitation: refuse if expired
     *    (AC-18's "expired", distinguished from "already used" above).
     * 6. AC-16: the coach's one active association, if any, must belong to
     *    *this* invitation's trainer -- a different trainer is refused
     *    outright; the same trainer means a second invitation from them is
     *    being accepted, which reuses the existing association instead of
     *    duplicating it. Otherwise: create the association, mark the
     *    invitation accepted, then (post-commit) record
     *    `COACH_INVITATION_ACCEPTED` -- only when this call is the one that
     *    actually flipped `acceptedAt` from null, mirroring
     *    `PlayerShareLinkService::associate()`'s "no second event on
     *    replay" rule.
     *
     * @throws InvalidCoachInvitationException
     * @throws ShareLinkUnavailableException the inviting trainer is no
     *                                        longer `ACTIVE`
     * @throws CoachInvitationExpiredException
     * @throws CoachInvitationEmailMismatchException
     * @throws CoachInvitationAlreadyAcceptedException
     * @throws CoachAlreadyActiveElsewhereException
     */
    public function accept(string $token, User $coach): TrainerCoachAssociation
    {
        $parts = SelectorVerifierTokenFactory::split($token);

        if (null === $parts) {
            throw new InvalidCoachInvitationException();
        }

        [$selector, $verifier] = $parts;

        $entityManager = $this->openEntityManager();

        try {
            /** @var array{0: TrainerCoachAssociation, 1: bool, 2: string, 3: string} $result */
            $result = $entityManager->wrapInTransaction(function () use ($entityManager, $selector, $verifier, $coach): array {
                // See AccountInvitationService::consume()'s identical warm-up
                // for why this must run, as a plain top-level find(), before
                // the FOR UPDATE query below ever touches the invitation's
                // `trainer` association.
                $trainerId = $this->invitationRepository->findTrainerIdBySelector($selector);

                if (null !== $trainerId) {
                    $entityManager->find(User::class, $trainerId);
                }

                $invitation = $this->invitationRepository->findOneBySelectorForUpdate($selector);

                if (null === $invitation || !hash_equals($invitation->getHashedVerifier(), SelectorVerifierTokenFactory::hash($verifier))) {
                    throw new InvalidCoachInvitationException();
                }

                // Task 38 hardening fix: the same `isActive()` trainer guard
                // `PlayerShareLinkService::associate()` has, for voter/
                // service-guard symmetry -- before this fix, accept() relied
                // solely on the controller's prior resolve() call to have
                // already refused a deactivated/deleted trainer, so a caller
                // reaching accept() by any other path could bypass it.
                // Positioned exactly where resolve() places its own copy of
                // this same check: right after the token/hash check, ahead
                // of every accepted/expired/email-mismatch branch below.
                if (!$invitation->getTrainer()->isActive()) {
                    throw new ShareLinkUnavailableException();
                }

                $now = new \DateTimeImmutable();

                if (UserRole::COACH !== $coach->getRole() || $coach->getEmail() !== $invitation->getInvitedEmail()) {
                    throw new CoachInvitationEmailMismatchException();
                }

                $trainer = $invitation->getTrainer();
                $active = $this->associationRepository->findActiveForCoach($coach);

                $trainerId = (string) $trainer->getId();
                $invitationId = (string) $invitation->getId();

                if ($invitation->isAccepted()) {
                    if (null !== $active && $active->getTrainer()->getId()->equals($trainer->getId())) {
                        return [$active, false, $trainerId, $invitationId];
                    }

                    throw new CoachInvitationAlreadyAcceptedException();
                }

                if ($invitation->isExpired($now)) {
                    throw new CoachInvitationExpiredException();
                }

                if (null !== $active) {
                    if (!$active->getTrainer()->getId()->equals($trainer->getId())) {
                        throw new CoachAlreadyActiveElsewhereException();
                    }

                    $invitation->accept($now);
                    $entityManager->persist($invitation);

                    return [$active, true, $trainerId, $invitationId];
                }

                $association = new TrainerCoachAssociation($trainer, $coach, $invitation, $now);
                $invitation->accept($now);
                $entityManager->persist($invitation);
                $entityManager->persist($association);

                return [$association, true, $trainerId, $invitationId];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Do NOT touch $entityManager again -- wrapInTransaction() above
            // already closed it. The pre-check just above the insert already
            // established this coach had no active association at all, so a
            // violation reaching here can only mean a *different* invitation
            // for this same coach won a concurrent race for the partial
            // unique index -- always a refusal, never an idempotent success.
            $this->managerRegistry->resetManager();

            throw new CoachAlreadyActiveElsewhereException($e);
        }

        [$association, $recordEvent, $trainerId, $invitationId] = $result;

        if ($recordEvent) {
            $this->accountEventRecorder->record(new AccountEventRecord(
                type: AccountEventType::COACH_INVITATION_ACCEPTED,
                actorUserId: $coach->getId(),
                subjectUserId: $coach->getId(),
                context: [
                    'trainerId' => $trainerId,
                    'invitationId' => $invitationId,
                ],
            ));
        }

        return $association;
    }

    /**
     * Same recovery pattern as `UserAccountService::openEntityManager()`:
     * detect a manager a previous call left closed and ask the registry to
     * reset it, rather than ever reusing a closed instance.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(CoachInvitation::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', CoachInvitation::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
