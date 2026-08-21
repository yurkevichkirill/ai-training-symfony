<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerShareLink;
use App\Entity\TrainerPlayerAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\PlayerShareLinkRepository;
use App\Repository\TrainerPlayerAssociationRepository;
use App\Service\Exception\AccountNotEligibleException;
use App\Service\Exception\ChildActionNotPermittedException;
use App\Service\Exception\NoActiveTrainerAssociationException;
use App\Service\Exception\RoleNotEligibleForShareLinkException;
use App\Service\Exception\ShareLinkUnavailableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A trainer's one broadcastable player-invite link (AC-1, AC-2, AC-4) and
 * what following it does to the player's roster membership (AC-6, AC-11,
 * AC-12, AC-13, AC-20) -- plus, since Task 36 (AC-11 amendment), the
 * player's own way to end that membership again ({@see self::leave()}).
 * Task 10 widens the association writer into
 * {@see self::associateWithTrainer()} (an optional link, an explicit
 * trainer, and an actor distinct from the player) and the ender into
 * {@see self::endAssociation()}, so this remains the one writer of a
 * `TrainerPlayerAssociation` for both the original player-self-serve flows
 * and the parent-acting-for-a-child flows alike (D2b).
 *
 * **The closed-EntityManager pitfall** this class follows is the same one
 * `UserAccountService`/`AccountLifecycleService` document at length: a
 * `UniqueConstraintViolationException` escaping `wrapInTransaction()` leaves
 * that EntityManager instance permanently closed, so every method below
 * recovers via `ManagerRegistry::resetManager()` rather than ever reusing
 * the closed instance, and re-reads the winning row directly against the
 * freshly-reset manager -- never through the injected repositories, which
 * permanently cache whichever `EntityRepository` they first resolve (see
 * the repository methods' own docblocks).
 */
final class PlayerShareLinkService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly PlayerShareLinkRepository $shareLinkRepository,
        private readonly TrainerPlayerAssociationRepository $associationRepository,
        private readonly ShareLinkCodeGenerator $codeGenerator,
        private readonly AccountEventRecorder $accountEventRecorder,
        private readonly MessageBusInterface $messageBus,
        private readonly ChildAccountResolver $childAccountResolver,
        private readonly NotificationAddressResolver $notificationAddressResolver,
    ) {
    }

    /**
     * AC-4: idempotent get-or-create. `UNIQUE (trainer_id)` is what makes a
     * concurrent double-generate resolve to one row -- on
     * `UniqueConstraintViolationException` this resets the manager and
     * re-reads the winner's row instead of ever reusing the closed instance.
     */
    public function getOrCreateFor(User $trainer): PlayerShareLink
    {
        $existing = $this->shareLinkRepository->findOneByTrainer($trainer);

        if (null !== $existing) {
            return $existing;
        }

        $entityManager = $this->openEntityManager();
        $link = new PlayerShareLink($trainer, $this->codeGenerator->generate());

        try {
            $entityManager->wrapInTransaction(static function () use ($entityManager, $link): void {
                $entityManager->persist($link);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Do NOT touch $entityManager again -- wrapInTransaction() above
            // already closed it. Reset via the registry and query the fresh
            // manager directly, not through $this->shareLinkRepository (see
            // that repository's findOneByTrainer() docblock for why).
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);

            $winner = $freshManager->createQueryBuilder()
                ->select('link')
                ->from(PlayerShareLink::class, 'link')
                ->andWhere('link.trainer = :trainer')
                ->setParameter('trainer', $trainer)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$winner instanceof PlayerShareLink) {
                // The unique violation was on (trainer_id), so a winning row
                // must exist; a null read here means something else is
                // wrong and swallowing it would hide that.
                throw $e;
            }

            return $winner;
        }

        return $link;
    }

    /**
     * Guards, in order (AC-11, AC-12, AC-13, AC-20):
     * 1. `$player->getRole() === UserRole::PLAYER`, else
     *    {@see RoleNotEligibleForShareLinkException} -- refuses a signed-in
     *    Coach/Trainer/Super Admin outright (AC-20, and the "signed-in Coach
     *    follows a player link" edge case).
     * 2. `$player->isActive()`, else {@see AccountNotEligibleException} --
     *    the DEACTIVATED/DELETED edge case.
     * 3. the link's trainer must be `ACTIVE`, else
     *    {@see ShareLinkUnavailableException} -- belt-and-braces:
     *    `PlayerShareLinkResolver::resolve()` already filters this, but a
     *    caller could hand this method a `PlayerShareLink` obtained another
     *    way.
     *
     * Then one transaction: a pre-check via
     * {@see TrainerPlayerAssociationRepository::findOneFor()} returns an
     * existing row untouched (AC-13) -- the message-quality fast path, not
     * the authority. On a genuinely new row: insert it **and** issue an
     * atomic `UPDATE player_share_link SET usage_count = usage_count + 1
     * WHERE id = :id` (via DQL, in the same transaction) together, so the
     * tally and the row that justifies it can never drift apart. This is
     * deliberately NOT `$link->incrementUsage()` + `persist($link)`: that
     * shape hydrates `usage_count`, increments it in PHP, and lets the
     * UnitOfWork flush a fully-computed literal `UPDATE ... SET usage_count
     * = :value` -- two concurrent registrations against the same link both
     * read `usage_count = 0` and both flush a literal `1`, silently losing
     * one of the two increments (Task 32 hardening fix, AC-6). The
     * database-computed `x = x + 1` has no such window: both connections'
     * writes serialize at the row level and the final count is always the
     * true number of increments, regardless of interleaving. `UNIQUE
     * (trainer_id, player_id)` is the authority under concurrency for the
     * association row itself -- a caught `UniqueConstraintViolationException`
     * resets the manager and re-reads the existing row as the same
     * idempotent success, never a 500.
     *
     * `AccountEventRecorder::record(PLAYER_TRAINER_ASSOCIATED)` runs
     * post-commit, actor = subject = the player, and only on the
     * genuinely-new-row branch: an idempotent no-op increments nothing and
     * records nothing a second time (AC-6, AC-9).
     *
     * **Task 36: `findOneFor()` now matches only a currently-active row**
     * (`ended_at IS NULL`) -- a player who left this trainer and re-follows
     * the same link falls through to the genuinely-new-row branch below
     * instead of resurrecting the ended one, per the entity's own docblock.
     * That same branch also dispatches `TEMPLATE_PLAYER_TRAINER_CONNECTED`
     * to the player, post-commit, alongside the `AccountEvent` -- on every
     * genuinely new association, including the "existing account follows a
     * second trainer" path (AC-12), never on the idempotent branch above.
     *
     * **Task 10 (D2b): widened for the family flows.** `$trainer` now comes
     * in directly rather than only via `$link->getTrainer()`, `$link` itself
     * is optional (`ChildTrainerService::approveRequest()`/the parent's
     * "connect to an additional trainer" path have no ShareLink to hand
     * back -- the atomic `usage_count` increment below only runs when one
     * was actually used), and `$actor` names who is performing the action,
     * defaulting to `$player` for every existing S3 call site. `associate()`
     * below is the original two-argument entry point, now a one-line
     * wrapper, so every S3 call site and every S3 test is untouched.
     *
     * **New guard (AC-14):** if the acting user *is* the player and
     * {@see ChildAccountResolver::isChild()} says that player is a child,
     * this refuses with {@see ChildActionNotPermittedException} -- a child
     * cannot connect itself to a trainer through any route, forged or
     * otherwise. An adult acting on a child's behalf (a parent, `$actor !==
     * $player`) is exactly what the family flows need this guard to allow.
     *
     * **Task 10 (D3c):** the `TEMPLATE_PLAYER_TRAINER_CONNECTED` dispatch
     * now routes through {@see NotificationAddressResolver::forPlayer()}
     * instead of `$player->getEmail()` directly -- for a child player this
     * is the parent's address, not the child's undeliverable `.invalid`
     * placeholder; for every other player it is unchanged.
     */
    public function associateWithTrainer(User $player, User $trainer, ?PlayerShareLink $link, ?User $actor = null): TrainerPlayerAssociation
    {
        $actor ??= $player;

        if ($actor === $player && $this->childAccountResolver->isChild($player)) {
            throw new ChildActionNotPermittedException();
        }

        if (UserRole::PLAYER !== $player->getRole()) {
            throw new RoleNotEligibleForShareLinkException();
        }

        if (!$player->isActive()) {
            throw new AccountNotEligibleException();
        }

        if (!$trainer->isActive()) {
            throw new ShareLinkUnavailableException();
        }

        $existing = $this->associationRepository->findOneFor($trainer, $player);

        if (null !== $existing) {
            return $existing;
        }

        $entityManager = $this->openEntityManager();
        $association = new TrainerPlayerAssociation($trainer, $player, $link);

        try {
            $entityManager->wrapInTransaction(static function () use ($entityManager, $association, $link): void {
                $entityManager->persist($association);

                if (null === $link) {
                    return;
                }

                // Atomic, database-computed increment -- see this method's
                // docblock for why this is not `$link->incrementUsage()` +
                // `persist($link)`. Deliberately compares the entity itself
                // (`l = :link`), the same idiom `findOneByTrainer()`'s
                // sibling queries in this class use for `link.trainer =
                // :trainer`, rather than extracting the raw Uuid.
                $entityManager->createQueryBuilder()
                    ->update(PlayerShareLink::class, 'l')
                    ->set('l.usageCount', 'l.usageCount + 1')
                    ->where('l = :link')
                    ->setParameter('link', $link)
                    ->getQuery()
                    ->execute();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Same discipline as getOrCreateFor() above: do not touch
            // $entityManager again, reset via the registry, and re-read
            // directly against the fresh manager.
            $freshManager = $this->managerRegistry->resetManager();
            \assert($freshManager instanceof EntityManagerInterface);

            $winner = $freshManager->createQueryBuilder()
                ->select('association')
                ->from(TrainerPlayerAssociation::class, 'association')
                ->andWhere('association.trainer = :trainer')
                ->andWhere('association.player = :player')
                ->setParameter('trainer', $trainer)
                ->setParameter('player', $player)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$winner instanceof TrainerPlayerAssociation) {
                throw $e;
            }

            return $winner;
        }

        $this->accountEventRecorder->record(new AccountEventRecord(
            type: AccountEventType::PLAYER_TRAINER_ASSOCIATED,
            actorUserId: $player->getId(),
            subjectUserId: $player->getId(),
        ));

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $this->notificationAddressResolver->forPlayer($player),
            template: SendEmailMessage::TEMPLATE_PLAYER_TRAINER_CONNECTED,
            context: [
                'trainerName' => $trainer->getDisplayName(),
            ],
        ));

        return $association;
    }

    /**
     * S3's original two-argument entry point, unchanged for every existing
     * call site and test: the trainer is always the link's own trainer, and
     * the acting user is always the player themselves.
     */
    public function associate(User $player, PlayerShareLink $link): TrainerPlayerAssociation
    {
        return $this->associateWithTrainer($player, $link->getTrainer(), $link);
    }

    /**
     * Task 36 (AC-11 amendment): the player's "leave this trainer" action.
     * Only ever operates on the `$player`/`$trainer` pair the caller
     * supplies -- `Player\TrainerRosterController::leave()` always passes
     * `$this->getUser()` as `$player`, never a player id taken from the
     * request, the same rule `ProfileController` established for
     * self-service actions. Because of that, this method's caller is always
     * the player themselves -- unlike {@see self::associateWithTrainer()}
     * there is no separate `$actor` to name.
     *
     * **Task 10 (AC-14): same child-actor guard as
     * `associateWithTrainer()`** -- a child cannot end its own trainer
     * connection through any route, forged or otherwise, so this refuses
     * with {@see ChildActionNotPermittedException} before even looking up
     * an association.
     *
     * **Task 10 (D2c): now a thin wrapper over {@see self::endAssociation()}**
     * -- refuses with {@see NoActiveTrainerAssociationException} when that
     * conditional `UPDATE` affects no row (never connected, or already
     * left), the same "invalid state transition is a typed exception, not a
     * quiet success" convention this project follows throughout, and it
     * lets the controller tell a genuine double-submit apart from a bug.
     *
     * @throws NoActiveTrainerAssociationException
     */
    public function leave(User $player, User $trainer): void
    {
        if ($this->childAccountResolver->isChild($player)) {
            throw new ChildActionNotPermittedException();
        }

        if (!$this->endAssociation($trainer, $player)) {
            throw new NoActiveTrainerAssociationException();
        }
    }

    /**
     * D2c: ends the currently-active association between this trainer and
     * player, if one exists -- a single conditional `UPDATE
     * trainer_player_association SET ended_at = :now WHERE trainer_id = :t
     * AND player_id = :p AND ended_at IS NULL`, with the affected-row count
     * as the answer. Returns `true` when a row was ended, `false` when there
     * was no currently-active row to end.
     *
     * Deliberately **not** `leave()`'s old read-then-`end()`-then-flush
     * shape: that lets two concurrent callers for the same pairing both read
     * the row as active, both call `end()`, and both flush -- one success
     * silently overwriting the other's recorded `endedAt` moment, with
     * nothing to tell the two racers apart. This single statement's row
     * count *is* the distinction: exactly one caller's execution affects the
     * row (`true`), and the other's affects none (`false`), with the
     * database serializing the two writes at the row level rather than PHP
     * arbitrating them.
     *
     * Used by both `leave()` above and, in a later batch,
     * `ChildTrainerService::disconnect()` -- the one place this project ends
     * a `TrainerPlayerAssociation`. Because the statement names one
     * `(trainer, player)` pair, it can never affect any other trainer's
     * connection to this player, nor any other player's connection to this
     * trainer (AC-10), and it never touches `player_availability_slot` --
     * ending a connection preserves that history exactly as the entity's
     * "audit trail over hard delete" convention already requires.
     */
    public function endAssociation(User $trainer, User $player): bool
    {
        $manager = $this->managerRegistry->getManagerForClass(TrainerPlayerAssociation::class);
        \assert($manager instanceof EntityManagerInterface);

        $affectedRows = $manager->createQueryBuilder()
            ->update(TrainerPlayerAssociation::class, 'association')
            ->set('association.endedAt', ':now')
            ->where('association.trainer = :trainer')
            ->andWhere('association.player = :player')
            ->andWhere('association.endedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('trainer', $trainer)
            ->setParameter('player', $player)
            ->getQuery()
            ->execute();

        return $affectedRows > 0;
    }

    /**
     * Same recovery pattern as `UserAccountService::openEntityManager()`:
     * detect a manager a previous call left closed and ask the registry to
     * reset it, rather than ever reusing a closed instance.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(PlayerShareLink::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', PlayerShareLink::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
