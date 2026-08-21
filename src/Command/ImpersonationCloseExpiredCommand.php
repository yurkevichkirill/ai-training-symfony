<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ImpersonationSessionRepository;
use App\Service\ImpersonationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * S6 (AC-8, AC-9, D4): bookkeeping only, for an impersonation session whose
 * browser never comes back for the request that would otherwise close it
 * (the admin closed the tab, lost connectivity, etc). It never writes a
 * *new* session and never picks a target -- it only closes rows that are
 * already past `expires_at` via `ImpersonationService::expire()`, which
 * is the same conditional close every other end path uses (D4b), so a row
 * this command races against a live request or `ImpersonationExpirySubscriber`
 * is closed exactly once either way.
 *
 * Its only effect on a live browser is indirect, through the invariant in
 * the architecture's Approach #2: once the row is closed, the *next*
 * request from that browser is force-exited by `ImpersonationExpirySubscriber`
 * (its "no open row" branch). Safe to run repeatedly -- an already-closed
 * row is simply not returned by `findExpiredOpen()` again.
 *
 * **No scheduler exists in this repo yet.** This command is not wired to
 * any cron or Symfony Scheduler recipe -- name it in deployment notes; do
 * not invent one here (architecture Risks: "no scheduler for the sweep
 * command").
 */
#[AsCommand(
    name: 'app:impersonation:close-expired',
    description: 'Closes impersonation sessions past their expiry that no live request has closed yet (bookkeeping only; run on a schedule -- none exists in this repo yet).',
)]
final class ImpersonationCloseExpiredCommand extends Command
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly ImpersonationSessionRepository $repository,
        private readonly ImpersonationService $impersonationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of expired sessions to close in one run.',
            (string) self::BATCH_SIZE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1) {
            $io->error('--limit must be a positive integer.');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $closed = 0;

        while ([] !== $batch = $this->repository->findExpiredOpen($now, $limit)) {
            foreach ($batch as $session) {
                $this->impersonationService->expire($session, $now);
                ++$closed;
            }
        }

        if (0 === $closed) {
            $io->success('No abandoned impersonation sessions to close.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Closed %d abandoned impersonation session(s).', $closed));

        return Command::SUCCESS;
    }
}
