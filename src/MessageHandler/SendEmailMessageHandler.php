<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendEmailMessage;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * The dev/prod consumer of `SendEmailMessage` (Task 26/28's DTO) -- the only
 * place this app renders a `templates/emails/*.twig` file or calls
 * `MailerInterface::send()`. `#[AsMessageHandler]` autoconfigures the
 * `messenger.message_handler` tag in every environment **except test** --
 * `config/services.yaml`'s `when@test:` block overrides this one service id
 * with `autoconfigure: false`, so the tag is never added there.
 *
 * **Reconciliation with Task 28's `RecordingEmailMessageHandler`,** decided
 * empirically (see Task 29's plan note for the `debug:messenger` output in
 * each environment), not assumed: the two handlers never coexist for
 * `SendEmailMessage` in the same environment. Test has only the recorder
 * (tagged, `App\Message\SendEmailMessage` has no `async` routing entry in
 * test, so the message stays on the default bus and is handled locally, by
 * the recorder alone since this class is untagged there). Dev/prod have only
 * this class (tagged; `App\Message\SendEmailMessage` routes to `async` there,
 * per `config/packages/messenger.yaml`'s `when@dev:`/`when@prod:` blocks --
 * once a message has a routing entry, Messenger sends it to that transport
 * instead of handling it locally, so this handler only ever runs when a
 * worker (`messenger:consume`) later processes that transport, never inline
 * during the request that dispatched it). This -- not Messenger's own
 * (real) support for multiple handlers per message class -- is what keeps
 * exactly one handler live per environment.
 *
 * **No live `User` entity ever reaches this class.** `SendEmailMessage`
 * carries only scalars (`to`, `template`, `context`) by design (see its own
 * docblock), so the Doctrine readonly-id/lazy-proxy hydration bug Task 28
 * found and fixed in `EmailVerificationTokenService::consume()` (a User
 * proxy re-hydrated without having been independently loaded first) cannot
 * recur here: this handler never loads anything from the database, let
 * alone a `User`.
 *
 * **`TEMPLATE_RESET_PASSWORD`, wired by Task 30.** `buildContext()`'s
 * `resetUrl` generates the `app_reset_password` route, which does not exist
 * until Task 31's `ResetPasswordController` lands. This is inert in exactly
 * the same forward-reference sense `SendEmailMessage` itself was between
 * Tasks 26 and 29 (dispatched with no handler at all): `Route` names are
 * resolved at `generate()` call time, not at container-compile time, so
 * `lint:container` cannot see the gap, and no test in this environment ever
 * reaches this branch (`when@test` never routes `SendEmailMessage` to this
 * handler -- `RecordingEmailMessageHandler` does -- see the class docblock
 * above). By the time a real worker ever consumes a queued
 * `TEMPLATE_RESET_PASSWORD` message, Task 31 will have shipped the route.
 */
#[AsMessageHandler]
final class SendEmailMessageHandler
{
    /** @var array<string, string> template identifier -> `templates/emails/*.twig` path */
    private const TEMPLATES = [
        SendEmailMessage::TEMPLATE_VERIFY_EMAIL => 'emails/verify_email.html.twig',
        SendEmailMessage::TEMPLATE_RESET_PASSWORD => 'emails/reset_password.html.twig',
        SendEmailMessage::TEMPLATE_TRAINER_INVITATION => 'emails/trainer_invitation.html.twig',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFromAddress,
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $templateName = self::TEMPLATES[$message->template] ?? throw new \InvalidArgumentException(
            \sprintf('Unknown SendEmailMessage template identifier "%s".', $message->template),
        );

        $context = $this->buildContext($message);

        // Loaded once so `subject` and `text` come from the exact same
        // compiled template `htmlTemplate()` below renders as the HTML body
        // -- see that file's own docblock for the single-file
        // subject/text/html convention this relies on.
        $loadedTemplate = $this->twig->load($templateName);
        $subject = trim($loadedTemplate->renderBlock('subject', $context));
        $text = $loadedTemplate->renderBlock('text', $context);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress))
            ->to($message->to)
            ->subject($subject)
            ->htmlTemplate($templateName)
            ->context($context)
            ->text($text);

        // Never wraps SMTP failures -- letting them propagate is what makes
        // Messenger's retry_strategy (config/packages/messenger.yaml) and
        // `failure_transport: failed` actually engage; swallowing the
        // exception here would silently drop the mail instead (AC-9, AC-13;
        // the delivery-failure edge case in the spec's table, which is about
        // the *caller's* response staying unaffected, not about this
        // handler -- by the time this runs, on a worker consuming the
        // `async` transport, the original HTTP response was already sent).
        $this->mailer->send($email);
    }

    /**
     * @return array<string, scalar>
     */
    private function buildContext(SendEmailMessage $message): array
    {
        return match ($message->template) {
            SendEmailMessage::TEMPLATE_VERIFY_EMAIL => [
                'verificationUrl' => $this->urlGenerator->generate(
                    'app_verify_email',
                    ['token' => $message->context['token']],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            SendEmailMessage::TEMPLATE_RESET_PASSWORD => [
                'resetUrl' => $this->urlGenerator->generate(
                    'app_reset_password',
                    ['token' => $message->context['token']],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            SendEmailMessage::TEMPLATE_TRAINER_INVITATION => [
                'invitationUrl' => $this->urlGenerator->generate(
                    'app_account_invitation',
                    ['token' => $message->context['token']],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            default => throw new \InvalidArgumentException(
                \sprintf('Unknown SendEmailMessage template identifier "%s".', $message->template),
            ),
        };
    }
}
