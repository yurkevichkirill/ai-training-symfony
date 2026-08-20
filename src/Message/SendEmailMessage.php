<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched via the autowired `MessageBusInterface` whenever this app needs
 * to send a templated email without making the caller's response wait on
 * SMTP (spec's "Email delivery fails for a reset or verification message ->
 * the user-facing response is unchanged" edge case).
 *
 * Deliberately a small, plain, fully serializable DTO -- a recipient, a
 * string identifier for which template to render, and a flat context array
 * -- rather than a `Symfony\Component\Mime\Email`/`TemplatedEmail` object.
 * Mime objects are not reliably serializable across a Messenger transport
 * (they can carry closures/resources), which is exactly what Task 29's
 * Doctrine `async` transport needs to do with this message.
 *
 * **Scope note (Task 26 vs. Task 29).** This class is only the message
 * shape. Nothing yet consumes it: there is no `#[AsMessageHandler]` handler,
 * no `async` transport routing in `config/packages/messenger.yaml`, and no
 * `templates/emails/*.twig` template. Task 29 builds all three. Until then,
 * dispatching this message goes to the default (`sync`) bus with no
 * registered handler and fails at dispatch time with
 * `Symfony\Component\Messenger\Exception\NoHandlerForMessageException` --
 * expected and out of this task's scope; `EmailVerificationService::resend()`
 * still calls `dispatch()` so the call site is correct and ready for Task 29
 * to make it actually deliver mail.
 *
 * @param string                $to       the recipient's email address (already normalized)
 * @param string                $template a template identifier the eventual handler switches
 *                                         on to pick a `templates/emails/*.twig` file -- e.g.
 *                                         {@see self::TEMPLATE_VERIFY_EMAIL} -- not a file path
 *                                         or a raw subject line
 * @param array<string, scalar> $context  template variables (e.g. the raw `selector.verifier`
 *                                         token string for building the verification link);
 *                                         deliberately flat and scalar-only so the message stays
 *                                         trivially serializable across any transport
 */
final class SendEmailMessage
{
    /** Consumed by Task 29's handler to select `templates/emails/verify_email.html.twig`. */
    public const TEMPLATE_VERIFY_EMAIL = 'verify_email';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/reset_password.html.twig`. Added by Task 30
     * (`PasswordResetService::request()`), the dispatch Task 29 anticipated
     * but deliberately left unwired -- see that task's plan note.
     */
    public const TEMPLATE_RESET_PASSWORD = 'reset_password';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/trainer_invitation.html.twig` (S2, AC-5). Dispatched
     * by `TrainerOnboardingService::createTrainer()`.
     */
    public const TEMPLATE_TRAINER_INVITATION = 'trainer_invitation';

    public function __construct(
        public readonly string $to,
        public readonly string $template,
        public readonly array $context = [],
    ) {
    }
}
