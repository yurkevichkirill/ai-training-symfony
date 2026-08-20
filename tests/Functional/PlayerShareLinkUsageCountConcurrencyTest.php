<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PlayerShareLink;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ShareLinkCodeGenerator;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Task 39 coverage gap: `ShareLinkInvitationsConstraintsTest::
 * testIncrementUsageAcrossTwoIndependentlyLoadedReadsAccumulatesWithoutLosingACountAc6()`
 * explicitly scopes out the real lost-update race -- it drives
 * `PlayerShareLink::incrementUsage()`, an entity method **no production
 * code path calls any more** (see that method's own docblock, Task 32).
 * This file proves the fix that actually shipped: `PlayerShareLinkService::
 * associate()`/`PlayerRegistrationService::registerViaShareLink()`'s atomic,
 * database-computed `UPDATE player_share_link SET usage_count = usage_count
 * + 1 WHERE id = :id`, under two *genuinely* concurrent physical
 * connections that overlap in time -- not two sequential reads on one
 * connection.
 *
 * **Why two live connections, and how genuine overlap is forced without
 * needing true OS-level parallelism in this test process.** A single PHP
 * process/connection cannot issue a blocking call and unblock it at the same
 * time, so this reproduces the same technique
 * `AccountLifecycleFlowTest::testTwoConcurrentDeletesForTheSameAccountYieldExactlyOneSuccess()`
 * uses for its own two-connection race: this process opens a raw second
 * connection (`$connectionA`), begins a transaction, and issues the exact
 * atomic UPDATE, deliberately without committing -- taking Postgres's
 * row-level lock and holding it open. A genuinely separate OS process
 * (`fixtures/player-share-link-usage-increment-subprocess.php`, its own
 * physical connection) then issues the identical production DQL UPDATE
 * against the same row and blocks, waiting for that lock, exactly as a real
 * second concurrent request would. Only once this process commits
 * (releasing the lock after a fixed hold window) does the subprocess's
 * UPDATE proceed -- against the now-committed value, which is what the
 * atomic `x = x + 1` form is for: neither connection ever reads a
 * PHP-side counter to go stale, so the final count reflects both increments
 * regardless of how the two transactions actually interleaved. The
 * subprocess's own elapsed time is asserted to be at least the hold window,
 * proving it was genuinely blocked rather than coincidentally lucky about
 * ordering.
 */
final class PlayerShareLinkUsageCountConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $persistedUserIds = [];

    private ?string $linkId = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Deliberately no wrapping transaction -- the whole point is that the
     * fixture link must be durably committed so a genuinely separate
     * physical connection (the subprocess) can see it. Cleanup is therefore
     * explicit.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if (null !== $this->linkId) {
            $connection->executeStatement('DELETE FROM player_share_link WHERE id = :id', ['id' => $this->linkId]);
        }

        foreach ($this->persistedUserIds as $id) {
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testTwoGenuinelyConcurrentConnectionsBothIncrementingUsageCountLoseNoIncrementAc6(): void
    {
        $trainer = new User(UserFactory::email('concurrency-trainer'), UserFactory::passwordHash(), UserRole::TRAINER);
        $this->em->persist($trainer);
        $this->em->flush();
        $this->persistedUserIds[] = (string) $trainer->getId();

        $link = new PlayerShareLink($trainer, (new ShareLinkCodeGenerator())->generate());
        $this->em->persist($link);
        $this->em->flush();
        $this->linkId = (string) $link->getId();

        self::assertSame(0, $link->getUsageCount(), 'Precondition failed: a freshly created link must start at 0.');

        $connectionA = DriverManager::getConnection($this->em->getConnection()->getParams());
        $process = null;

        $readyFile = tempnam(sys_get_temp_dir(), 'plsl-usage-ready-');
        $resultFile = tempnam(sys_get_temp_dir(), 'plsl-usage-result-');
        unlink($readyFile); // the subprocess re-creates it -- its *existence* is the signal
        unlink($resultFile);

        try {
            $connectionA->beginTransaction();
            $connectionA->executeStatement(
                'UPDATE player_share_link SET usage_count = usage_count + 1 WHERE id = :id',
                ['id' => $this->linkId],
            );

            $script = __DIR__.'/fixtures/player-share-link-usage-increment-subprocess.php';
            $process = proc_open(
                ['php', $script, $this->linkId, $readyFile, $resultFile],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                \dirname(__DIR__, 2),
            );
            self::assertIsResource($process, 'Failed to spawn the concurrent-usage-increment subprocess.');
            fclose($pipes[0]);

            if (!$this->waitForFile($readyFile, 15.0)) {
                self::fail(\sprintf(
                    "Subprocess did not signal readiness within 15s. stderr:\n%s",
                    stream_get_contents($pipes[2]),
                ));
            }

            $holdMicroseconds = 300_000; // 300ms
            usleep($holdMicroseconds);

            $connectionA->commit();
        } finally {
            $connectionA->close();
        }

        if (!$this->waitForFile($resultFile, 15.0)) {
            self::fail(\sprintf(
                "Subprocess did not report a result within 15s. stderr:\n%s",
                stream_get_contents($pipes[2]),
            ));
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $output = trim((string) file_get_contents($resultFile));
        @unlink($readyFile);
        @unlink($resultFile);

        self::assertSame(0, $exitCode, \sprintf("Subprocess exited abnormally. stderr:\n%s", $stderr));
        self::assertMatchesRegularExpression(
            '/^RESULT=(\w+);ELAPSED_NS=(\d+)$/',
            $output,
            'Unexpected subprocess output: '.$output,
        );
        preg_match('/^RESULT=(\w+);ELAPSED_NS=(\d+)$/', $output, $matches);
        [, $result, $elapsedNs] = $matches;
        $elapsedNs = (int) $elapsedNs;

        self::assertSame(
            'SUCCESS',
            $result,
            'The subprocess\'s atomic increment must succeed once this process\'s lock is released, never throw.',
        );
        self::assertGreaterThanOrEqual(
            200_000_000,
            $elapsedNs,
            \sprintf(
                'The subprocess\'s UPDATE completed in %dns, too fast to have been genuinely blocked by the %dus lock this process held on the same row.',
                $elapsedNs,
                $holdMicroseconds,
            ),
        );

        $this->em->clear();
        $final = $this->em->getRepository(PlayerShareLink::class)->find($link->getId());
        self::assertInstanceOf(PlayerShareLink::class, $final);
        self::assertSame(
            2,
            $final->getUsageCount(),
            'Two genuinely concurrent connections both incrementing usageCount must never lose an increment -- the atomic UPDATE is what guarantees this, not connection ordering luck.',
        );
    }

    /**
     * Polls for a file's existence -- same pattern as
     * `AccountLifecycleFlowTest`'s identical helper. `clearstatcache()` is
     * required: PHP caches `file_exists()` results per path for the life of
     * the process, so without it this would only ever see the
     * (non-existent) pre-creation state.
     */
    private function waitForFile(string $path, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            clearstatcache(true, $path);

            if (file_exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
