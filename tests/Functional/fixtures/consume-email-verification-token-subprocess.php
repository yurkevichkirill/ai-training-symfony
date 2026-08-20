<?php

declare(strict_types=1);

/**
 * Standalone script -- NOT a PHPUnit test case (deliberately not named
 * `*Test.php`, so PHPUnit's default `tests/` suite discovery never picks it
 * up). `EmailVerificationFlowTest`'s concurrent-consume case spawns this via
 * `proc_open()` as a genuinely separate OS process, which gets its own PHP
 * interpreter and, critically, its own Postgres session/connection -- the one
 * thing a single PHPUnit process cannot give itself no matter how cleverly it
 * interleaves calls, since Doctrine's identity map and one DBAL connection
 * per test process can never produce two backends racing for the same row
 * lock.
 *
 * Usage: php consume-email-verification-token-subprocess.php <raw-token> <ready-file> <result-file>
 *
 * Boots the real kernel in APP_ENV=test (the same environment and database
 * the test suite itself runs against) and builds
 * `EmailVerificationTokenService` by hand from its two collaborators --
 * `doctrine` (public) and the repository it hands out -- the same
 * manual-construction pattern `UserAccountServiceConcurrentCreationTest`
 * (Task 24) already established for a service that is not itself public in
 * the container.
 *
 * Signalling deliberately goes through two plain files, not stdout/stdin
 * pipes: `stream_select()` on a proc_open() pipe proved unreliable from
 * inside a PHPUnit-booted process in practice (it can block indefinitely
 * even with an explicit timeout), where the exact same code worked from a
 * plain CLI script. Polling for a file's existence has no such failure mode.
 *
 *   <ready-file>  is created empty once the kernel is booted and the DB
 *                 connection is warm, immediately before calling consume().
 *                 The parent waits for this file to start its own
 *                 deliberate lock-hold window, so that window begins only
 *                 once this process is genuinely about to race for the row
 *                 -- decoupling the timing proof from kernel boot-time
 *                 jitter.
 *   <result-file> is written once, after consume() returns or throws:
 *                 `RESULT=<Outcome>;ELAPSED_NS=<int>`. <Outcome> is
 *                 "SUCCESS" or the short class name of the thrown
 *                 exception. ELAPSED_NS times only the consume() call
 *                 itself, so the parent can assert this process was
 *                 genuinely blocked on the row lock rather than merely slow
 *                 to start.
 */

use App\Entity\EmailVerificationToken;
use App\Kernel;
use App\Service\EmailVerificationTokenService;
use App\Service\SelectorVerifierTokenFactory;
use Symfony\Component\Dotenv\Dotenv;

$projectDir = \dirname(__DIR__, 3);

require $projectDir.'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($projectDir.'/.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();

/** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
$doctrine = $container->get('doctrine');

// Force this process's own connection open now, before signalling READY, so
// the timed consume() call below pays only for the row-lock wait -- not for
// connection setup that has nothing to do with the lock being proved.
// Connection::connect() itself is not public, so a trivial round trip
// achieves the same warm-up.
$doctrine->getConnection()->executeQuery('SELECT 1');

$tokenRepository = $doctrine->getRepository(EmailVerificationToken::class);
$service = new EmailVerificationTokenService($doctrine, $tokenRepository, new SelectorVerifierTokenFactory());

[, $token, $readyFile, $resultFile] = $argv;

file_put_contents($readyFile, '');

$start = hrtime(true);

try {
    $service->consume($token);
    $result = 'SUCCESS';
} catch (\Throwable $e) {
    $result = (new \ReflectionClass($e))->getShortName();
}

$elapsedNs = hrtime(true) - $start;

file_put_contents($resultFile, \sprintf('RESULT=%s;ELAPSED_NS=%d', $result, $elapsedNs));

$kernel->shutdown();
