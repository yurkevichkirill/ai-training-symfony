<?php

declare(strict_types=1);

/**
 * Standalone script -- NOT a PHPUnit test case, same reason and naming
 * convention as account-lifecycle-delete-subprocess.php /
 * consume-email-verification-token-subprocess.php: this needs a genuinely
 * separate OS process with its own Postgres session, which one PHPUnit
 * process and one DBAL connection can never produce for itself.
 *
 * Usage: php player-share-link-usage-increment-subprocess.php <linkId> <ready-file> <result-file>
 *
 * Issues the exact same atomic, database-computed increment
 * `PlayerShareLinkService::associate()`/`PlayerRegistrationService::
 * registerViaShareLink()` issue in production (Task 32 hardening fix) --
 * `UPDATE player_share_link SET usage_count = usage_count + 1 WHERE id =
 * :id`, via the ORM's DQL `update()` builder, so this is a genuine second
 * physical connection running the real production statement, not a
 * hand-rolled substitute for it.
 *
 * <ready-file> is created immediately after this process's own connection
 * begins its transaction and issues that UPDATE -- so the parent process
 * learns "this process is now attempting the increment" at the earliest
 * point that is true, the same convention account-lifecycle-delete-
 * subprocess.php's docblock documents for its own ready-file timing.
 * <result-file> is written once the transaction commits or an exception is
 * caught: `RESULT=<Outcome>;ELAPSED_NS=<int>`. <Outcome> is "SUCCESS" or the
 * short class name of the thrown exception; ELAPSED_NS times only the
 * UPDATE-plus-commit portion, so the parent can confirm this process was
 * genuinely blocked waiting for its own row lock rather than racing ahead of
 * the parent's hold window by luck.
 */

use App\Entity\PlayerShareLink;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

$projectDir = \dirname(__DIR__, 3);

require $projectDir.'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($projectDir.'/.env');

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();

/** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
$doctrine = $container->get('doctrine');

/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = $doctrine->getManagerForClass(PlayerShareLink::class);

// Force this process's own connection open now, before signalling READY, so
// the timed section below pays only for the row-lock wait -- not for
// connection setup that has nothing to do with the lock being proved (same
// rationale as account-lifecycle-delete-subprocess.php).
$em->getConnection()->executeQuery('SELECT 1');

[, $linkId, $readyFile, $resultFile] = $argv;

$link = $em->getReference(PlayerShareLink::class, Uuid::fromString($linkId));

$start = hrtime(true);

try {
    $em->getConnection()->beginTransaction();

    file_put_contents($readyFile, '');

    // The exact same DQL this project's production code issues -- see this
    // file's own docblock.
    $em->createQueryBuilder()
        ->update(PlayerShareLink::class, 'l')
        ->set('l.usageCount', 'l.usageCount + 1')
        ->where('l = :link')
        ->setParameter('link', $link)
        ->getQuery()
        ->execute();

    $em->getConnection()->commit();
    $result = 'SUCCESS';
} catch (\Throwable $e) {
    if ($em->getConnection()->isTransactionActive()) {
        $em->getConnection()->rollBack();
    }

    $result = (new \ReflectionClass($e))->getShortName();
}

$elapsedNs = hrtime(true) - $start;

file_put_contents($resultFile, \sprintf('RESULT=%s;ELAPSED_NS=%d', $result, $elapsedNs));

$kernel->shutdown();
