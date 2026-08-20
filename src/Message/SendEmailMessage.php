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

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/player_welcome.html.twig` (S3, AC-9): the one email
     * a ShareLink registration sends, which *is* the verification email --
     * carrying the verification link and naming the trainer (architecture
     * Decisions Q5′). Dispatched by
     * `PlayerRegistrationService::registerViaShareLink()`. Same forward-
     * reference shape `TEMPLATE_RESET_PASSWORD` used between Tasks 26 and
     * 30 -- `SendEmailMessageHandler`'s `buildContext()` branch and the
     * Twig template are a later task's job; this constant only needs to
     * exist for this one to compile and dispatch onto the `async` transport.
     */
    public const TEMPLATE_PLAYER_WELCOME = 'player_welcome';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/coach_invitation.html.twig` (S3, AC-5): the
     * invitation link plus the trainer's optional personal message.
     * Dispatched by `CoachInvitationService::invite()`. Same forward-
     * reference shape as `TEMPLATE_PLAYER_WELCOME` above -- the handler's
     * `buildContext()` branch and the Twig template are a later task's job.
     */
    public const TEMPLATE_COACH_INVITATION = 'coach_invitation';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/coach_welcome.html.twig` (S3, AC-14, AC-15): the
     * verification link for a coach who just registered from an invitation.
     * Dispatched by `CoachRegistrationService::registerAndAccept()`. Same
     * forward-reference shape as `TEMPLATE_PLAYER_WELCOME` above.
     */
    public const TEMPLATE_COACH_WELCOME = 'coach_welcome';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/duplicate_registration_attempt.html.twig` (S3, AC-10
     * amendment -- enumeration resistance, Task 35). Sent to the *existing*
     * account when someone submits its email address on a player
     * ShareLink's public registration form, instead of the controller
     * naming the address in a field-level form error (a definitive
     * enumeration oracle on that permanently-broadcastable, unauthenticated
     * endpoint). Dispatched by `PlayerShareLinkController::register()`'s
     * `EmailAlreadyInUseException` catch block -- the one `SendEmailMessage`
     * dispatch in this app that does not originate from a service, since no
     * data changes and nothing here is a use case a service needs to own.
     */
    public const TEMPLATE_DUPLICATE_REGISTRATION_ATTEMPT = 'duplicate_registration_attempt';

    /**
     * Consumed by `SendEmailMessageHandler` to select
     * `templates/emails/player_trainer_connected.html.twig` (Task 36, AC-11
     * amendment). Dispatched by `PlayerShareLinkService::associate()` on
     * every genuinely new `TrainerPlayerAssociation` row -- including the
     * existing-account-follows-a-second-trainer path (AC-12) -- never on the
     * idempotent "already actively associated" branch. Distinct from
     * `TEMPLATE_PLAYER_WELCOME`: that one only fires once, on brand-new
     * registration; this one fires on every new connection this player ever
     * makes.
     */
    public const TEMPLATE_PLAYER_TRAINER_CONNECTED = 'player_trainer_connected';

    public function __construct(
        public readonly string $to,
        public readonly string $template,
        public readonly array $context = [],
    ) {
    }
}
