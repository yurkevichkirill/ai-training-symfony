<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Message\SendEmailMessage;

/**
 * Test-only stand-in for Task 29's real `SendEmailMessageHandler`, which does
 * not exist yet. Registered only under `when@test` in `config/services.yaml`
 * so `EmailVerificationService::resend()`'s
 * `$this->messageBus->dispatch(new SendEmailMessage(...))` call has a handler
 * to reach on the default `sync` transport, instead of throwing
 * `Symfony\Component\Messenger\Exception\NoHandlerForMessageException` --
 * exactly the gap `SendEmailMessage`'s own docblock (Task 26/27) flagged this
 * task (28) would need to close to exercise `resend()` at all.
 *
 * Records every dispatched message so a test can recover the raw
 * `selector.verifier` token embedded in `context['token']` -- the only place
 * it exists once `resend()` returns, since `resend()` itself is `void` by
 * design (AC-11-shaped non-enumeration).
 */
final class RecordingEmailMessageHandler
{
    /** @var list<SendEmailMessage> */
    private array $messages = [];

    public function __invoke(SendEmailMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function last(): ?SendEmailMessage
    {
        return $this->messages[array_key_last($this->messages)] ?? null;
    }

    /**
     * @return list<SendEmailMessage>
     */
    public function all(): array
    {
        return $this->messages;
    }
}
