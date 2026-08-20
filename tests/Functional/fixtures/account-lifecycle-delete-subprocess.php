<?php

declare(strict_types=1);

/**
 * Standalone script -- NOT a PHPUnit test case, same reason and naming
 * convention as consume-email-verification-token-subprocess.php: this needs
 * a genuinely separate OS process with its own Postgres session, which one
 * PHPUnit process and one DBAL connection can never produce for itself.
 *
 * Usage: php account-lifecycle-delete-subprocess.php <subjectUserId> <actorUserId> <ready-file> <result-file>
 *
 * `AccountLifecycleService` is built by hand from its five collaborators,
 * exactly the manual-construction pattern
 * UserAccountServiceConcurrentCreationTest and the email-verification
 * subprocess already established: `AccountDeletionLogRepository` and
 * `AccountInvitationRepository` come from `ManagerRegistry::getRepository()`
 * rather than the container (their `repositoryClass` attribute is all either
 * needs), and `AccountEventRecorder`/`FileStorage` take only a
 * `ManagerRegistry`/a plain string, so no container lookup of a
 * possibly-private service is needed for any of the five.
 *
 * <ready-file> is created immediately before calling delete() -- the parent
 * process starts its own hold-then-release window only once this process is
 * genuinely about to race for the `account_deletion_log` unique key.
 * <result-file> is written once delete() returns or throws:
 * `RESULT=<Outcome>;ELAPSED_NS=<int>`. <Outcome> is "SUCCESS" or the short
 * class name of the thrown exception; ELAPSED_NS times only the delete()
 * call itself.
 */

use App\Entity\AccountDeletionLog;
use App\Entity\AccountInvitation;
use App\Entity\User;
use App\Kernel;
use App\Service\AccountEventRecorder;
use App\Service\AccountLifecycleService;
use App\Service\FileStorage;
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
// the timed delete() call below pays only for the row-lock wait -- not for
// connection setup that has nothing to do with the lock being proved.
$doctrine->getConnection()->executeQuery('SELECT 1');

// `app.uploads_dir` is not retrievable from this plain kernel container --
// only KernelTestCase::getContainer()'s special test.service_container
// wrapper exposes every parameter, and this script deliberately boots the
// real, unwrapped kernel (see the class docblock). FileStorage never
// actually touches this path in the race this script exercises (the target
// account has no photo, and delete() throws before ever reaching
// FileStorage::delete() here), so deriving it from kernel.project_dir the
// same way config/services.yaml's own definition does is sufficient.
$uploadsDir = $kernel->getProjectDir().'/var/uploads';

$service = new AccountLifecycleService(
    $doctrine,
    $doctrine->getRepository(AccountDeletionLog::class),
    $doctrine->getRepository(AccountInvitation::class),
    new AccountEventRecorder($doctrine),
    new FileStorage($uploadsDir),
);

[, $subjectUserId, $actorUserId, $readyFile, $resultFile] = $argv;

$em = $doctrine->getManagerForClass(User::class);
\assert($em instanceof \Doctrine\ORM\EntityManagerInterface);

$subject = $em->find(User::class, $subjectUserId);
$actor = $em->find(User::class, $actorUserId);
\assert($subject instanceof User && $actor instanceof User);

file_put_contents($readyFile, '');

$start = hrtime(true);

try {
    $service->delete($subject, $actor, null);
    $result = 'SUCCESS';
} catch (\Throwable $e) {
    $result = (new \ReflectionClass($e))->getShortName();
}

$elapsedNs = hrtime(true) - $start;

file_put_contents($resultFile, \sprintf('RESULT=%s;ELAPSED_NS=%d', $result, $elapsedNs));

$kernel->shutdown();
