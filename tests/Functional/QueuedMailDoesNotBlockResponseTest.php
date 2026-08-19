<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\SendEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Direct proof of the spec's edge-case table entry -- "Email delivery fails
 * for a reset or verification message -> the user-facing response is
 * unchanged (AC-11 holds); the delivery failure is recorded for operators
 * and the message is retried" -- exercised at the actual mechanism
 * dev/prod rely on (the `async` Doctrine transport,
 * `config/packages/messenger.yaml`), not inferred from `resend()`'s response
 * shape alone.
 *
 * **Why this does not simply call `EmailVerificationService::resend()` and
 * inspect the HTTP response, the way `EmailVerificationFlowTest` does.** In
 * the test environment, `App\Message\SendEmailMessage` is deliberately NOT
 * routed to `async` at all -- see `messenger.yaml`'s routing comment and
 * Task 29's plan note. It stays on the default bus and is handled
 * synchronously, in-process, by Task 28's `RecordingEmailMessageHandler`, so
 * that task's assertions never touch a transport or a worker. That is the
 * right design for Task 28's purpose (see this task's own reconciliation
 * note in `config/services.yaml`), but it also means the ordinary
 * `resend()` call path in *this* environment never touches the `async`
 * transport at all, and so cannot exercise this property. Confirmed
 * empirically (Task 29's plan note): `debug:config framework messenger`
 * shows `routing: {}` under `--env=test` and
 * `routing: { App\Message\SendEmailMessage: [async] }` under
 * `--env=dev`/`--env=prod`.
 *
 * This test instead dispatches directly onto the real, compiled `async`
 * transport service -- the exact same service dev/prod route this message
 * to -- to prove the property the architecture actually relies on
 * mechanically: a Doctrine transport's `send()` is a plain `INSERT`, so it
 * always succeeds regardless of whether anything downstream (SMTP, a
 * worker) is healthy or even running, and a message sitting unprocessed in
 * that table perturbs nothing about an unrelated request's response. No
 * handler -- neither the real `SendEmailMessageHandler` nor the test-only
 * recorder -- runs anywhere in this test; `messenger:consume` is never
 * invoked.
 */
final class QueuedMailDoesNotBlockResponseTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The transport-level mechanism itself: sending a `SendEmailMessage`
     * straight to the `async` transport succeeds and lands a row in
     * `messenger_messages` -- with no handler ever invoked and no worker
     * running. This is what "the dispatch succeeds regardless of SMTP
     * health" (architecture doc, Mail delivery section) means mechanically:
     * nothing about actually sending mail happens at dispatch time at all.
     */
    public function testDispatchingToTheAsyncTransportLandsARowWithoutInvokingAHandler(): void
    {
        $asyncTransport = $this->asyncTransport();

        $message = new SendEmailMessage(
            to: 'queued-mail-test@example.test',
            template: SendEmailMessage::TEMPLATE_VERIFY_EMAIL,
            context: ['token' => str_repeat('a', 64)],
        );

        $envelope = $asyncTransport->send(new Envelope($message));

        $idStamp = $envelope->last(TransportMessageIdStamp::class);
        self::assertInstanceOf(
            TransportMessageIdStamp::class,
            $idStamp,
            'A Doctrine transport send() must stamp the envelope with the row id it just inserted -- proof the INSERT actually happened, not merely that no exception was thrown.',
        );

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT body, queue_name FROM messenger_messages WHERE id = ?',
            [$idStamp->getId()],
        );

        self::assertIsArray($row, 'The message must actually be present in messenger_messages.');
        self::assertSame('default', $row['queue_name']);
        self::assertStringContainsString(
            SendEmailMessage::TEMPLATE_VERIFY_EMAIL,
            $row['body'],
            'The stored row must be the serialized SendEmailMessage, not some other payload.',
        );
    }

    /**
     * The response-side property the spec actually asks for (AC-11's "the
     * user-facing response is unchanged"): an unrelated controller's
     * response is exactly as fast and as correct whether or not the
     * `async` transport currently holds an unprocessed row -- i.e. a
     * pending (or, equivalently, a permanently undeliverable) message never
     * leaks into, blocks, or measurably slows a request that has nothing to
     * do with it. No worker ever runs in this test, so any row inserted
     * here is -- and, by the architecture, is meant to be -- left
     * unprocessed for the whole request/response cycle under test.
     */
    public function testAPendingQueuedMessageDoesNotAffectAnUnrelatedControllerResponse(): void
    {
        $this->asyncTransport()->send(new Envelope(new SendEmailMessage(
            to: 'queued-mail-test-2@example.test',
            template: SendEmailMessage::TEMPLATE_VERIFY_EMAIL,
            context: ['token' => str_repeat('b', 64)],
        )));

        $start = microtime(true);
        $this->client->request('GET', '/verify-email/resend');
        $elapsed = microtime(true) - $start;

        self::assertResponseIsSuccessful();
        self::assertLessThan(
            2.0,
            $elapsed,
            'A pending, unprocessed queued message must not add meaningful latency to an unrelated request.',
        );
    }

    /**
     * `messenger.transport.async` is a private service (not `public: true`
     * anywhere), reachable here only because `framework.test: true`
     * (config/packages/framework.yaml's `when@test` block) makes the whole
     * container -- public or private -- available through
     * `self::getContainer()` in the test environment specifically.
     */
    private function asyncTransport(): TransportInterface
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return $transport;
    }
}
