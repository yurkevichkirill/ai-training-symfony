<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The `app:` sweep the architecture's Risks section already scoped as the
 * same-pass mitigation for the AC-19 pre-hijack/squatting risk: a public
 * `/join/{code}` or `/coach-invitation/{token}` submission creates a real
 * `User` row -- with an attacker-chosen password, at an attacker-chosen
 * email -- before that address is ever proven reachable, and sign-in stays
 * refused only until verification, not the address itself. Left unswept, an
 * attacker can permanently squat any address they can guess or enumerate. A
 * full registration-flow redesign (holding the credential until verified) is
 * deliberately deferred; this command is the shipped mitigation for now (see
 * the architecture doc's "Post-implementation hardening decisions" section).
 *
 * Candidate rows: `UserRepository::findStaleUnverifiedShareLinkAccounts()`
 * -- role `PLAYER` or `COACH`, `email_verified_at IS NULL`, `created_at`
 * older than `--hours` (default 1, matching the architecture's "older than
 * an hour" wording), `status != DELETED`. `TRAINER`/`SUPER_ADMIN` accounts
 * are never created through an anonymous ShareLink path and are excluded by
 * construction (the role filter), not by a runtime check -- deleting one
 * would be a completely different mistake than this command guards against.
 *
 * **Why a bulk hard delete is not "just `DELETE FROM app_user WHERE ...`".**
 * `profile`, `player_share_link`, `coach_invitation`,
 * `trainer_player_association`, and `trainer_coach_association` all cascade
 * from `app_user` (`ON DELETE CASCADE`/`SET NULL`, confirmed against
 * `migrations/Version20260820081527.php` and
 * `migrations/Version20260820095413.php`), so those never need an explicit
 * statement here. `account_event.subject_user_id`, however, is `ON DELETE
 * RESTRICT` (`AccountEvent`'s own class docblock: "deletion anonymizes the
 * app_user row in place rather than removing it"), and every account this
 * command targets already has exactly one `account_event` row recording its
 * own registration (`PLAYER_REGISTERED_VIA_SHARE_LINK` /
 * `COACH_INVITATION_ACCEPTED`, written unconditionally at the end of
 * `PlayerRegistrationService`/`CoachRegistrationService`, regardless of
 * whether the address is ever verified) -- so a naive `DELETE FROM app_user`
 * would hit that FK on effectively every real row. This command deletes that
 * account's own `account_event` rows first, in the same per-account
 * transaction, before deleting the `app_user` row itself. This is a
 * deliberate purge, not the audited `AccountLifecycleService::delete()` GDPR
 * lifecycle: there is nothing worth retaining an audit trail for on an
 * account that squatted an address for under an hour and never came back to
 * verify it, and `status != DELETED` above guarantees this sweep never goes
 * near an account that *did* go through that audited lifecycle (its
 * `AccountDeletionLog` row carries the same `RESTRICT` FK and is never
 * touched here).
 *
 * Raw `Doctrine\DBAL\Connection` statements, not the ORM, drive the actual
 * delete -- this is a maintenance sweep over rows nothing else references by
 * the time it runs, not a service-layer business operation, so there is no
 * `AccountLifecycleService` call to make here.
 *
 * `--dry-run` reports the true total (`UserRepository::
 * countStaleUnverifiedShareLinkAccounts()`) plus a bounded preview list,
 * without deleting anything. Safe to run repeatedly and safe against nothing
 * matching -- both are no-ops, not errors.
 *
 * Exit codes: `0` success (including "nothing matched"); `1` an invalid
 * `--hours` value; `2` an unexpected/unhandled condition.
 */
#[AsCommand(
    name: 'app:sweep-unverified-accounts',
    description: 'Deletes stale, never-verified Player/Coach accounts created via a public ShareLink/coach-invitation flow (AC-19 squatting mitigation).',
)]
final class SweepUnverifiedAccountsCommand extends Command
{
    private const int BATCH_SIZE = 200;
    private const int PREVIEW_LIMIT = 50;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Age threshold in hours: accounts created longer ago than this, and still unverified, are swept.',
                '1',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List what would be deleted, and how many rows in total match, without deleting anything.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            return $this->doExecute($io, $input);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Unexpected error while sweeping unverified accounts: %s', $e->getMessage()));

            return Command::INVALID;
        }
    }

    private function doExecute(SymfonyStyle $io, InputInterface $input): int
    {
        $hours = $this->parseHours($input->getOption('hours'), $io);

        if (null === $hours) {
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = (new \DateTimeImmutable())->modify(\sprintf('-%d hours', $hours));

        $io->title('Sweep unverified accounts');
        $io->text(\sprintf(
            'Target: role PLAYER or COACH, never verified, created before %s (older than %d hour%s).',
            $cutoff->format(\DateTimeInterface::ATOM),
            $hours,
            1 === $hours ? '' : 's',
        ));

        return $dryRun ? $this->reportDryRun($io, $cutoff) : $this->sweep($io, $cutoff);
    }

    private function parseHours(mixed $rawHours, SymfonyStyle $io): ?int
    {
        if (!\is_string($rawHours) && !\is_int($rawHours)) {
            $io->error('--hours must be a positive integer.');

            return null;
        }

        $hours = filter_var($rawHours, \FILTER_VALIDATE_INT);

        if (false === $hours || $hours < 1) {
            $io->error('--hours must be a positive integer.');

            return null;
        }

        return $hours;
    }

    private function reportDryRun(SymfonyStyle $io, \DateTimeImmutable $cutoff): int
    {
        $total = $this->userRepository->countStaleUnverifiedShareLinkAccounts($cutoff);

        if (0 === $total) {
            $io->success('Dry run: no accounts match -- nothing would be deleted.');

            return Command::SUCCESS;
        }

        $preview = $this->userRepository->findStaleUnverifiedShareLinkAccounts($cutoff, self::PREVIEW_LIMIT);

        $io->table(
            ['id', 'email', 'role', 'created at'],
            array_map($this->toRow(...), $preview),
        );

        if ($total > \count($preview)) {
            $io->text(\sprintf('... and %d more not shown.', $total - \count($preview)));
        }

        $io->warning(\sprintf('Dry run: %d account(s) would be deleted. Re-run without --dry-run to delete them.', $total));

        return Command::SUCCESS;
    }

    private function sweep(SymfonyStyle $io, \DateTimeImmutable $cutoff): int
    {
        $totalDeleted = 0;

        while ([] !== $batch = $this->userRepository->findStaleUnverifiedShareLinkAccounts($cutoff, self::BATCH_SIZE)) {
            foreach ($batch as $user) {
                $io->text(\sprintf(' - deleting %s', implode(' | ', $this->toRow($user))));
                $this->deleteAccount($user);
                ++$totalDeleted;
            }

            // The rows just deleted via raw SQL are still in the
            // EntityManager's identity map from the query above; clearing it
            // is what makes the next loop iteration's DQL query -- and its
            // hydration -- see a consistent, un-stale state.
            $this->entityManager->clear();
        }

        if (0 === $totalDeleted) {
            $io->success('No accounts matched -- nothing deleted.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Deleted %d stale unverified account(s).', $totalDeleted));

        return Command::SUCCESS;
    }

    /**
     * Deletes one account and its own `account_event` rows in a single
     * transaction -- see this class's docblock for why the `account_event`
     * delete must come first (`subject_user_id` is `ON DELETE RESTRICT`).
     * Every other `app_user`-referencing table cascades on its own.
     */
    private function deleteAccount(User $user): void
    {
        $id = (string) $user->getId();

        $this->connection->transactional(static function (Connection $connection) use ($id): void {
            $connection->executeStatement(
                'DELETE FROM account_event WHERE subject_user_id = :id',
                ['id' => $id],
            );
            $connection->executeStatement(
                'DELETE FROM app_user WHERE id = :id',
                ['id' => $id],
            );
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function toRow(User $user): array
    {
        return [
            (string) $user->getId(),
            $user->getEmail(),
            $user->getRole()->value,
            $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
