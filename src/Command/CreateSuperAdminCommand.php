<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\AuthEventType;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Service\AuthEventRecord;
use App\Service\AuthEventRecorder;
use App\Service\Exception\EmailAlreadyInUseException;
use App\Service\UserAccountService;
use App\Validator\Constraints\NotBlocklistedPassword;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates the sole Super Admin account, or recovers from having lost it
 * (AC-25). No self-registration path exists in this system, so this
 * operator-run command is the only way a Super Admin comes into being.
 *
 * Interactive mode prompts for email and a hidden, confirmed password via
 * `SymfonyStyle`. Non-interactive mode (`--no-interaction`, or simply a
 * non-tty invocation) falls back to `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD`
 * read from the *real* process environment (`$_SERVER`/`getenv()`) -- never
 * from a value defaulted in the committed `.env` (architecture's Console
 * bootstrap section, and its Risks section note that `.env` is committed in
 * this repo). Operators supply the real value via the shell environment or
 * an uncommitted `.env.local`.
 *
 * The password policy is the exact same `Validator` constraints Task 25
 * built for the web flow (`Length`, `NotCompromisedPassword`,
 * `NotBlocklistedPassword`) -- reused here, not re-implemented, so the two
 * paths cannot silently drift apart.
 *
 * If a Super Admin already exists, proceeding requires an explicit
 * interactive confirmation or `--force` non-interactively: this command
 * doubles as the documented "every Super Admin was lost" recovery path, and
 * that path must not be triggerable by accident.
 *
 * **`email_verified_at` is set directly, after `UserAccountService::create()`
 * returns** -- the one sanctioned exception to "verification precedes
 * sign-in", confined to this one code path (architecture's Console bootstrap
 * section: an operator creating an account at a shell has already proven
 * possession out of band, and requiring mail infrastructure to work before
 * the first Super Admin can sign in would be a first-boot deadlock).
 * `UserAccountService::create()` already commits its own transaction
 * internally via `EntityManager::wrapInTransaction()` (Task 24) before
 * returning here, so this is necessarily a *second*, separate flush against
 * the same still-open `EntityManager` -- not literally "before create()'s
 * flush", since that one already happened. See `finalizeBootstrap()`.
 *
 * Exit codes: `0` success; `1` a caught business failure (invalid/mismatched
 * password, an existing Super Admin without confirmation/`--force`, a
 * colliding email); `2` an unexpected/unhandled condition. The plaintext
 * password is never echoed back to the console.
 */
#[AsCommand(
    name: 'app:create-super-admin',
    description: 'Creates the Super Admin account, or a replacement if every Super Admin was lost (AC-25).',
)]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly UserAccountService $userAccountService,
        private readonly UserRepository $userRepository,
        private readonly AuthEventRecorder $authEventRecorder,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Skip the confirmation prompt when a Super Admin already exists. Required (in place of the interactive prompt) when run non-interactively.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            return $this->doExecute($io, $input);
        } catch (EmailAlreadyInUseException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(\sprintf('Unexpected error while creating the Super Admin account: %s', $e->getMessage()));

            return Command::INVALID;
        }
    }

    private function doExecute(SymfonyStyle $io, InputInterface $input): int
    {
        $interactive = $input->isInteractive();
        $force = (bool) $input->getOption('force');

        if ($this->userRepository->existsWithRole(UserRole::SUPER_ADMIN)
            && !$this->confirmedProceedDespiteExistingSuperAdmin($io, $interactive, $force)
        ) {
            return Command::FAILURE;
        }

        $credentials = $interactive
            ? $this->promptForCredentials($io)
            : $this->credentialsFromEnvironment($io);

        if (null === $credentials) {
            return Command::FAILURE;
        }

        [$email, $plainPassword] = $credentials;

        $violations = $this->validator->validate($plainPassword, $this->passwordConstraints());

        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }

            return Command::FAILURE;
        }

        // EmailAlreadyInUseException propagates to execute()'s catch above --
        // it must not be swallowed here, and $this->userAccountService's
        // EntityManager must not be touched again in this method if it is
        // thrown (see UserAccountService's own class docblock).
        $user = $this->userAccountService->create($email, $plainPassword, UserRole::SUPER_ADMIN);

        $this->finalizeBootstrap($user);

        $io->success(\sprintf('Super Admin account created and verified for "%s".', $user->getEmail()));

        return Command::SUCCESS;
    }

    private function confirmedProceedDespiteExistingSuperAdmin(SymfonyStyle $io, bool $interactive, bool $force): bool
    {
        if ($force) {
            return true;
        }

        if ($interactive) {
            if ($io->confirm('A Super Admin account already exists. Create another one anyway?', false)) {
                return true;
            }

            $io->warning('Aborted: a Super Admin already exists and creation was not confirmed.');

            return false;
        }

        $io->error('A Super Admin already exists. Re-run with --force to create another one non-interactively.');

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null null when the two password
     *                                           prompts did not match
     */
    private function promptForCredentials(SymfonyStyle $io): ?array
    {
        $email = $io->ask('Super Admin email address', null, static function (?string $value): string {
            if (null === $value || '' === trim($value)) {
                throw new \RuntimeException('The email address cannot be empty.');
            }

            return $value;
        });

        $plainPassword = $io->askHidden('Password (input is hidden)', static function (?string $value): string {
            if (null === $value || '' === $value) {
                throw new \RuntimeException('The password cannot be empty.');
            }

            return $value;
        });

        $confirmPassword = $io->askHidden('Confirm password (input is hidden)');

        if ($plainPassword !== $confirmPassword) {
            $io->error('The password and its confirmation did not match.');

            return null;
        }

        \assert(null !== $email && null !== $plainPassword);

        return [$email, $plainPassword];
    }

    /**
     * @return array{0: string, 1: string}|null null when either variable is
     *                                           missing from the real
     *                                           environment
     */
    private function credentialsFromEnvironment(SymfonyStyle $io): ?array
    {
        $email = $this->readEnv('SUPER_ADMIN_EMAIL');
        $plainPassword = $this->readEnv('SUPER_ADMIN_PASSWORD');

        if (null === $email || null === $plainPassword) {
            $io->error('Non-interactive mode requires SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD to be set in the real process environment (the shell environment or an uncommitted .env.local) -- never in the committed .env.');

            return null;
        }

        return [$email, $plainPassword];
    }

    /**
     * Reads the real process environment only -- `$_SERVER` first (where
     * Symfony's Dotenv component populates variables it loaded from
     * `.env.local`/the shell by default), falling back to `getenv()`. This
     * deliberately never goes through `%env(...)%` container parameters,
     * which would happily resolve to a value this project's own `.env`
     * defaulted -- exactly what AC-25 forbids for these two variables.
     */
    private function readEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        if (false === $value || !\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<Length|NotCompromisedPassword|NotBlocklistedPassword>
     *
     * The identical constraint set Task 25 built and `ChangePasswordFormType`
     * applies -- reused directly, not a second hand-rolled length check.
     */
    private function passwordConstraints(): array
    {
        return [
            new Length(min: 12, max: 4096, countUnit: Length::COUNT_BYTES),
            new NotCompromisedPassword(),
            new NotBlocklistedPassword(),
        ];
    }

    /**
     * `UserAccountService::create()` (Task 24) already committed its own
     * `wrapInTransaction()` call before returning $user here -- it did not
     * throw, so per that service's own contract the `EntityManager` it used
     * is still open and still manages $user (a successful flush does not
     * detach an entity). `ManagerRegistry::getManagerForClass()` therefore
     * returns that exact same cached instance, and setting the field then
     * flushing again is a second, small, independent transaction -- which is
     * fine; it is not "before create()'s flush" in the literal sense, since
     * that flush already happened, but it is the one sanctioned exception to
     * "verification precedes sign-in", confined to this command.
     */
    private function finalizeBootstrap(User $user): void
    {
        $entityManager = $this->managerRegistry->getManagerForClass(User::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $user->markEmailVerified();
        $entityManager->flush();

        $this->authEventRecorder->record(new AuthEventRecord(
            type: AuthEventType::SUPER_ADMIN_BOOTSTRAPPED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user->getId(),
        ));
    }
}
