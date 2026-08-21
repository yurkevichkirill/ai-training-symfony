<?php

declare(strict_types=1);

namespace App\Command;

use App\Availability\TimeRange;
use App\Availability\WeeklyAvailability;
use App\Entity\User;
use App\Enum\PlayerGender;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use App\Service\ChildAccountService;
use App\Service\ChildTrainerService;
use App\Service\CoachAvailabilityService;
use App\Service\CoachInvitationRequest;
use App\Service\CoachInvitationService;
use App\Service\CoachRegistrationRequest;
use App\Service\CoachRegistrationService;
use App\Service\CreateChildRequest;
use App\Service\CreateTrainerRequest;
use App\Service\PlayerRegistrationRequest;
use App\Service\PlayerRegistrationService;
use App\Service\PlayerShareLinkService;
use App\Service\ProfileCoachRequest;
use App\Service\ProfileService;
use App\Service\TrainerBrandingRequest;
use App\Service\TrainerBrandingService;
use App\Service\TrainerOnboardingService;
use App\Service\UserAccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dev/demo-only seeder: populates the database with a realistic, lived-in
 * dataset of trainers, coaches, players, and families, going through the
 * real application services (`TrainerOnboardingService`,
 * `CoachInvitationService`/`CoachRegistrationService`,
 * `PlayerRegistrationService`/`PlayerShareLinkService`,
 * `ChildAccountService`/`ChildTrainerService`, `AvailabilityService`/
 * `CoachAvailabilityService`, `TrainerBrandingService`, `ProfileService`)
 * exactly as a real trainer/coach/player/parent would produce them by hand
 * -- so password hashing, ShareLink codes, association rows, and audit
 * `AccountEvent`s are all genuinely correct, not raw SQL inserts.
 *
 * Every account this command creates shares one known password (see
 * `DEMO_PASSWORD` below) since these are demo/dev fixtures, not
 * security-sensitive accounts. Existing seed accounts
 * (`admin@example.test`, `trainer@example.test`, `coach@example.test`,
 * `player@example.test`, and anything already in `credentials.md`) are
 * never touched -- every email this command mints is a fresh
 * `demo-*@example.test` address, so a re-run only ever adds a second,
 * differently-numbered batch alongside the first rather than colliding.
 *
 * The full HTTP round trip (invitation emails, `/verify-email/{token}`
 * links) is deliberately short-circuited here: this command calls
 * `User::markEmailVerified()` directly on every account it creates
 * immediately after the owning service call, so every seeded account can
 * sign in right away. That is the one place this command reaches past a
 * service's own public contract -- appropriate for a seed command, per the
 * task's own instructions, not something a controller would ever do.
 *
 * Requires `--force` to actually write, as a guard against an accidental
 * run against the wrong database.
 */
#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Seeds the database with a realistic demo dataset (trainers, coaches, players, families) via the real application services. Every account created shares one demo password (see --help). Requires --force. Dev/demo use only.',
)]
final class SeedDemoDataCommand extends Command
{
    public const DEMO_PASSWORD = 'DemoPass!2026';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserAccountService $userAccountService,
        private readonly TrainerOnboardingService $trainerOnboardingService,
        private readonly CoachInvitationService $coachInvitationService,
        private readonly CoachRegistrationService $coachRegistrationService,
        private readonly PlayerRegistrationService $playerRegistrationService,
        private readonly PlayerShareLinkService $playerShareLinkService,
        private readonly ChildAccountService $childAccountService,
        private readonly ChildTrainerService $childTrainerService,
        private readonly CoachAvailabilityService $coachAvailabilityService,
        private readonly \App\Service\AvailabilityService $availabilityService,
        private readonly TrainerBrandingService $trainerBrandingService,
        private readonly ProfileService $profileService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually write the demo data. Without this flag, the command refuses to run.')
        ;
        $this->setHelp(
            'Populates the database with a realistic demo dataset: several trainers (each with '
            .'distinct branding), coaches, players (some connected to more than one trainer), and '
            .'parent/child family accounts, plus availability grids and ShareLinks -- all created '
            .'through the real application services. Every seeded account shares the password "'
            .self::DEMO_PASSWORD.'". Existing seed/demo accounts are left untouched; every email this '
            .'command creates is a fresh demo-*@example.test address, so it is safe to run more than '
            .'once (each run adds a new, differently-numbered batch rather than colliding). Pass '
            .'--force to actually write.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->error('Refusing to run without --force (this writes real rows to the configured database).');

            return Command::FAILURE;
        }

        $superAdmin = $this->userRepository->findOneBy(['role' => UserRole::SUPER_ADMIN]);

        if (!$superAdmin instanceof User) {
            $io->error('No Super Admin account exists yet -- run app:create-super-admin first.');

            return Command::FAILURE;
        }

        $io->title('Seeding demo data');
        $batch = substr(bin2hex(random_bytes(3)), 0, 5);
        $io->text(\sprintf('Batch suffix: %s (keeps emails unique across repeated runs).', $batch));

        $counts = [
            'trainers' => 0, 'coaches' => 0, 'players' => 0,
            'parents' => 0, 'children' => 0, 'shareLinks' => 0,
            'pendingRequests' => 0,
        ];

        $trainerSpecs = [
            ['Ironclad Fitness', '#1d4ed8'],
            ['Summit Peak Athletics', '#059669'],
            ['Riverside Youth Soccer', '#dc2626'],
            ['Blackhawk Training Academy', '#7c3aed'],
        ];

        $trainers = [];
        foreach ($trainerSpecs as $i => [$businessName, $colorHex]) {
            $n = $i + 1;
            $email = \sprintf('demo-trainer%d-%s@example.test', $n, $batch);
            $request = new CreateTrainerRequest($email, $businessName, self::firstName($n), self::lastName($n), self::phone($n));
            $user = $this->trainerOnboardingService->createTrainer($request, $superAdmin);
            $this->setDemoPasswordAndVerify($user);
            $this->trainerBrandingService->updateColor($user, new TrainerBrandingRequest($colorHex), $user);
            $trainers[] = $user;
            ++$counts['trainers'];
            $io->text(\sprintf(' - trainer %s (%s)', $email, $businessName));
        }

        foreach ($trainers as $ti => $trainer) {
            $coachCount = 2 + ($ti % 2); // 2 or 3 coaches per trainer

            for ($c = 1; $c <= $coachCount; ++$c) {
                $n = $ti * 10 + $c;
                $email = \sprintf('demo-coach%d-%s@example.test', $n, $batch);
                $invitation = $this->coachInvitationService->invite(
                    new CoachInvitationRequest($email, self::firstName($n).' '.self::lastName($n)),
                    $trainer,
                );
                $regRequest = new CoachRegistrationRequest(
                    self::DEMO_PASSWORD,
                    self::firstName($n),
                    self::lastName($n),
                    self::phone($n),
                );
                $coach = $this->coachRegistrationService->registerAndAccept($regRequest, $invitation);
                $this->markVerified($coach);

                if (1 === $c) {
                    // At least one coach per trainer gets a public profile
                    // and an availability grid.
                    $this->profileService->updateCoachDetails(
                        $coach,
                        new ProfileCoachRequest(
                            \sprintf('Experienced coach specializing in youth development, with %d+ years on the field.', 3 + $c),
                            'CPR/AED Certified, USSF License',
                            'Level 2 Coaching Certificate',
                            true,
                        ),
                        $coach,
                    );
                    $this->coachAvailabilityService->replaceWeek($coach, self::sampleCoachWeek(), $coach);
                }

                ++$counts['coaches'];
            }
            $io->text(\sprintf(' - %d coach(es) for %s', $coachCount, $trainer->getDisplayName()));
        }

        $shareLinks = [];
        foreach ($trainers as $ti => $trainer) {
            $link = $this->playerShareLinkService->getOrCreateFor($trainer);
            $shareLinks[$ti] = $link;
            ++$counts['shareLinks'];
        }

        $playersByTrainer = [];
        foreach ($trainers as $ti => $trainer) {
            $playerCount = 6 + ($ti % 5); // 6..10 players
            $playersByTrainer[$ti] = [];

            for ($p = 1; $p <= $playerCount; ++$p) {
                $n = $ti * 100 + $p;
                $email = \sprintf('demo-player%d-%s@example.test', $n, $batch);
                $request = new PlayerRegistrationRequest(
                    $email,
                    self::DEMO_PASSWORD,
                    self::firstName($n),
                    self::lastName($n),
                    self::phone($n),
                    self::firstName($n).' '.self::lastName($n),
                    8 + ($n % 11),
                    self::gender($n),
                );
                $player = $this->playerRegistrationService->registerViaShareLink($request, $shareLinks[$ti]);
                $this->markVerified($player);

                if (0 === $p % 3) {
                    $this->availabilityService->replaceWeek($player, self::samplePlayerWeek(), $player);
                }

                $playersByTrainer[$ti][] = $player;
                ++$counts['players'];
            }
            $io->text(\sprintf(' - %d player(s) registered via %s\'s ShareLink', $playerCount, $trainer->getDisplayName()));
        }

        // A few players show off multi-trainer support: connect the first
        // player of trainer N to trainer N+1 as well.
        foreach ($trainers as $ti => $trainer) {
            $nextTi = ($ti + 1) % \count($trainers);
            if ($nextTi === $ti || [] === $playersByTrainer[$ti]) {
                continue;
            }

            $player = $playersByTrainer[$ti][0];
            $this->playerShareLinkService->associateWithTrainer($player, $trainers[$nextTi], $shareLinks[$nextTi]);
            $io->text(\sprintf(' - %s also connected to %s', $player->getEmail(), $trainers[$nextTi]->getDisplayName()));
        }

        // Parents with one or two children each.
        for ($pa = 1; $pa <= 4; ++$pa) {
            $parentEmail = \sprintf('demo-parent%d-%s@example.test', $pa, $batch);
            $parent = $this->userAccountService->create($parentEmail, self::DEMO_PASSWORD, UserRole::PLAYER);
            $parent->setName(self::firstName(200 + $pa), self::lastName(200 + $pa));
            $this->entityManager->flush();
            $this->markVerified($parent);
            ++$counts['parents'];

            $childCount = 1 + ($pa % 2);
            for ($ch = 1; $ch <= $childCount; ++$ch) {
                $n = $pa * 10 + $ch;
                $trainerForChild = $trainers[$n % \count($trainers)];
                $connectAtCreation = 0 === $ch % 2;

                $childRequest = new CreateChildRequest(
                    self::firstName(300 + $n).' '.self::lastName(300 + $n),
                    6 + ($n % 10),
                    self::gender($n),
                    null,
                    null,
                    $connectAtCreation ? [(string) $trainerForChild->getId()] : [],
                );
                $childAccount = $this->childAccountService->createChild($parent, $childRequest);
                $this->markVerified($childAccount->getChildUser());
                ++$counts['children'];

                if (!$connectAtCreation) {
                    $this->childTrainerService->connect($parent, $childAccount, $trainerForChild, null);
                }
            }
            $io->text(\sprintf(' - parent %s with %d child(ren)', $parentEmail, $childCount));
        }

        // At least one pending ChildTrainerRequest, simulating a blocked
        // ShareLink click by a child who is not yet connected to that
        // trainer.
        $blockedParentEmail = \sprintf('demo-parent-blocked-%s@example.test', $batch);
        $blockedParent = $this->userAccountService->create($blockedParentEmail, self::DEMO_PASSWORD, UserRole::PLAYER);
        $blockedParent->setName('Priya', 'Nandagopal');
        $this->entityManager->flush();
        $this->markVerified($blockedParent);
        ++$counts['parents'];

        $blockedChildRequest = new CreateChildRequest('Arun Nandagopal', 11, PlayerGender::MALE);
        $blockedChildAccount = $this->childAccountService->createChild($blockedParent, $blockedChildRequest);
        $this->markVerified($blockedChildAccount->getChildUser());
        ++$counts['children'];

        $blockedLinkTrainer = $trainers[0];
        $this->childTrainerService->recordBlockedClick($blockedChildAccount, $shareLinks[array_search($blockedLinkTrainer, $trainers, true)]);
        ++$counts['pendingRequests'];
        $io->text(\sprintf(' - pending ChildTrainerRequest: %s blocked against %s', $blockedChildAccount->getChildUser()->getDisplayName(), $blockedLinkTrainer->getDisplayName()));

        $io->success('Demo data seeded.');
        $io->table(['what', 'count'], array_map(static fn (string $k, int $v): array => [$k, (string) $v], array_keys($counts), $counts));
        $io->note(\sprintf('Shared password for every seeded account: %s', self::DEMO_PASSWORD));

        return Command::SUCCESS;
    }

    /**
     * Short-circuits the invitation/verify-email HTTP round trip: sets the
     * real demo password directly (bypassing the placeholder the owning
     * service issued) and marks the account verified, so it can sign in
     * immediately. Appropriate here only because this is a seed command --
     * no controller does this.
     */
    private function setDemoPasswordAndVerify(User $user): void
    {
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        $this->markVerified($user);
    }

    private function markVerified(User $user): void
    {
        if (!$user->isEmailVerified()) {
            $user->markEmailVerified(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
    }

    private static function firstName(int $n): string
    {
        $names = ['Alex', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Avery', 'Quinn', 'Skyler', 'Reese', 'Dakota', 'Emerson'];

        return $names[$n % \count($names)];
    }

    private static function lastName(int $n): string
    {
        $names = ['Nguyen', 'Garcia', 'Smith', 'Patel', 'Kowalski', 'Johnson', 'Martinez', 'Okafor', 'Silva', 'Andersen', 'Larsen', 'Kim'];

        return $names[($n * 7) % \count($names)];
    }

    private static function phone(int $n): string
    {
        return \sprintf('+1555%07d', 1000000 + $n);
    }

    private static function gender(int $n): PlayerGender
    {
        $genders = [PlayerGender::MALE, PlayerGender::FEMALE, PlayerGender::OTHER, PlayerGender::PREFER_NOT_TO_SAY];

        return $genders[$n % \count($genders)];
    }

    private static function samplePlayerWeek(): WeeklyAvailability
    {
        return new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(16 * 60, 18 * 60)],
            WeeklyAvailability::WEDNESDAY => [new TimeRange(16 * 60, 19 * 60)],
            WeeklyAvailability::SATURDAY => [new TimeRange(9 * 60, 12 * 60)],
        ]);
    }

    private static function sampleCoachWeek(): WeeklyAvailability
    {
        return new WeeklyAvailability([
            WeeklyAvailability::MONDAY => [new TimeRange(15 * 60, 20 * 60)],
            WeeklyAvailability::TUESDAY => [new TimeRange(15 * 60, 20 * 60)],
            WeeklyAvailability::THURSDAY => [new TimeRange(15 * 60, 20 * 60)],
            WeeklyAvailability::SATURDAY => [new TimeRange(8 * 60, 14 * 60)],
        ]);
    }
}
