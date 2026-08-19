<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AuthEventType;
use App\Message\SendEmailMessage;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\IpTruncator;
use App\Service\Exception\SourceRateLimitExceededException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * `App\Service\PasswordResetService`, over `symfonycasts/reset-password-bundle`
 * (AC-9, AC-10, AC-11, AC-12).
 *
 * **Ordering, not the bundle's default behaviour, is what makes "most
 * recently issued token valid, earlier ones refused" true.** `request()`
 * calls `ResetPasswordRequestRepository::removeRequests($user)` *before*
 * `ResetPasswordHelper::generateResetToken()` -- the bundle itself never
 * deletes a user's earlier outstanding requests when issuing a new one.
 *
 * **The bundle's own throttle is disabled** (`config/packages/reset_password.yaml`'s
 * `throttle_limit: 0`); every G-22 number for this flow lives in
 * `symfony/rate-limiter` instead (`password_reset_account` /
 * `password_reset_source`, the same shared pair
 * `EmailVerificationService::resend()` consumes, per the architecture's
 * "one pair of limiters, reused" decision). Because `request()` also
 * removes the user's prior requests before calling `generateResetToken()`,
 * `hasUserHitThrottling()` never even finds a prior request to throttle
 * against -- confirmed by reading
 * `vendor/symfonycasts/reset-password-bundle/src/ResetPasswordHelper.php`
 * directly: with no earlier request left for the account,
 * `TooManyPasswordRequestsException` cannot be thrown from this call site,
 * so `request()` does not catch it.
 *
 * **Identity-map bug investigation (Task 28's finding, re-verified here for
 * `ResetPasswordRequest`, not assumed).** `ResetPasswordRequest` maps the
 * identical `User` association shape `EmailVerificationToken` does
 * (`#[ORM\ManyToOne(targetEntity: User::class)]`, no `fetch: EAGER`), and
 * `ResetPasswordHelper::validateTokenAndFetchUser()` -- read directly from
 * the installed bundle, not assumed -- calls `$resetRequest->getUser()` on
 * an entity it just hydrated via a plain `findOneBy()` (never
 * independently pre-loading `User`). On a genuinely fresh request (the
 * normal case: `/reset-password/reset/{token}` is `PUBLIC_ACCESS` and never
 * loads a security-context user), that returns an *uninitialized* Doctrine
 * proxy. The first thing `complete()` does with it here --
 * `$user->setPasswordHash(...)` -- forces that proxy to initialize, which
 * makes Doctrine's hydrator try to re-set every mapped field on it,
 * including `User::$id` (readonly, object-typed `Uuid`). The proxy's
 * pre-populated `$id` and the hydrator's freshly constructed `Uuid` for the
 * same row are two different instances, so Doctrine's `!==` readonly guard
 * trips with `LogicException: Attempting to change readonly property
 * App\Entity\User::$id`, exactly as Task 28 found for
 * `EmailVerificationTokenService::consume()`. Confirmed reproducible with a
 * minimal `KernelTestCase` repro
 * ({@see \App\Tests\Service\PasswordResetServiceIdentityMapTest}, `$em->clear()`
 * before `complete()`, no subprocess needed -- same technique Task 28
 * documented). **Fix**, identical to Task 28's:
 * `ResetPasswordRequestRepository::findUserIdBySelector()` reads the raw
 * `user_id` FK via `IDENTITY()`, never touching the `user` association, so
 * `complete()` can warm the identity map with a plain, top-level,
 * fully-hydrated `User` *before* calling `validateTokenAndFetchUser()`. Once
 * that `User` is already tracked, Doctrine's `EntityManager::getReference()`
 * (which `validateTokenAndFetchUser()`'s hydration of the association goes
 * through) returns that same real instance instead of creating a proxy for
 * it at all, and the conflict never occurs.
 *
 * **The closed-EntityManager pitfall (same one `UserAccountService` and
 * `EmailVerificationTokenService` document).**
 * `EntityManagerInterface::wrapInTransaction()` closes its EntityManager on
 * *any* exception escaping the wrapped callback -- confirmed again here
 * against the installed `doctrine/orm` source
 * (`EntityManager::wrapInTransaction()`'s
 * `try { ... } finally { if (!$successful) { $this->close(); ... } }`).
 * `complete()`'s transaction runs `ResetPasswordHelper::validateTokenAndFetchUser()`
 * and `removeResetRequest()` inside that callback, both of which throw a
 * plain `ResetPasswordExceptionInterface` (invalid or expired token) for
 * every expected rejection -- not a unique-constraint violation, but the
 * same "domain exception escapes the transaction" shape Task 26 already
 * hit, so it closes the shared EntityManager exactly the same way. This
 * needs the recovery pattern, even though no unique constraint is involved:
 * `openEntityManager()` below is copied verbatim from
 * `EmailVerificationTokenService`. `request()` does not need it: it never
 * calls `persist()`/`flush()`/`wrapInTransaction()` itself -- every write it
 * triggers (`removeRequests()`'s bulk DQL delete, the bundle's
 * `persistResetPasswordRequest()`) happens inside
 * `ResetPasswordRequestRepository`'s own EntityManager reference, which this
 * service never touches directly.
 *
 * **Audit wiring (Task 34, AC-24).** `request()` records
 * `PASSWORD_RESET_REQUESTED` once a request actually proceeds (a known
 * account, neither limiter exhausted) -- not for the unknown-address or
 * rate-limited no-ops, which are silent by design (AC-11) and not audit
 * events in their own right. `complete()` records
 * `PASSWORD_RESET_COMPLETED` only *after* `wrapInTransaction()` returns,
 * i.e. only once the password change has actually committed -- recording it
 * from inside the closure would be observably wrong the moment anything
 * else in that transaction failed afterward, even though
 * `AuthEventRecorder` itself would still durably persist it (its whole
 * point is a transaction independent of this one). "Independent of the
 * business transaction" means the audit write cannot be *undone* by this
 * method's own rollback, not that it should be made before success is
 * certain.
 */
final class PasswordResetService
{
    /**
     * The bundle's own selector length
     * (`SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper::SELECTOR_LENGTH`,
     * private, and `Generator\ResetPasswordRandomGenerator::getRandomAlphaNumStr()`'s
     * hardcoded 20-character string -- confirmed by reading both directly
     * rather than assumed). `complete()` needs this to read the selector back
     * out of the full token *before* calling into the bundle, so it can warm
     * the identity map first (see the class docblock).
     */
    private const SELECTOR_LENGTH = 20;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly UserRepository $userRepository,
        private readonly ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MessageBusInterface $messageBus,
        private readonly RateLimiterFactory $passwordResetAccountLimiter,
        private readonly RateLimiterFactory $passwordResetSourceLimiter,
        private readonly RequestStack $requestStack,
        private readonly AuthEventRecorder $authEventRecorder,
    ) {
    }

    /**
     * Keying matches `EmailVerificationService::resend()` exactly (the same
     * shared limiter pair, AC-20): `password_reset_account` on the
     * normalized email as-is, `password_reset_source` on the client IP
     * truncated via `IpTruncator`, read off the current request via
     * `RequestStack` since this method is not itself bound to a `Request`.
     *
     * @throws SourceRateLimitExceededException if the *source* limiter is exhausted --
     *                                            the controller may turn this into a 429
     *                                            (AC-19-shaped; source is independent of
     *                                            any one account, so this discloses nothing
     *                                            about which addresses are registered). An
     *                                            exhausted *account* limiter never throws:
     *                                            this method returns normally, and the
     *                                            caller renders the identical check-email
     *                                            outcome either way (AC-11).
     */
    public function request(string $emailInput): void
    {
        $normalizedEmail = User::normalizeEmail($emailInput);

        $accountLimit = $this->passwordResetAccountLimiter->create($normalizedEmail)->consume();

        $clientIp = $this->requestStack->getCurrentRequest()?->getClientIp() ?? '';
        $sourceLimit = $this->passwordResetSourceLimiter->create(IpTruncator::truncate($clientIp))->consume();

        if (!$sourceLimit->isAccepted()) {
            throw new SourceRateLimitExceededException($sourceLimit->getRetryAfter());
        }

        if (!$accountLimit->isAccepted()) {
            return;
        }

        $user = $this->userRepository->findOneBy(['email' => $normalizedEmail]);

        if (!$user instanceof User) {
            return;
        }

        // Must run before generateResetToken() below -- see the class
        // docblock for why this ordering (not the bundle's default) is what
        // makes "most recently issued token valid, earlier ones refused"
        // true, and why it also means the bundle's own throttle never
        // triggers from this call site.
        $this->resetPasswordRequestRepository->removeRequests($user);

        $resetToken = $this->resetPasswordHelper->generateResetToken($user);

        $this->messageBus->dispatch(new SendEmailMessage(
            to: $user->getEmail(),
            template: SendEmailMessage::TEMPLATE_RESET_PASSWORD,
            context: ['token' => $resetToken->getToken()],
        ));

        $currentRequest = $this->requestStack->getCurrentRequest();

        $this->authEventRecorder->record(new AuthEventRecord(
            type: AuthEventType::PASSWORD_RESET_REQUESTED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user->getId(),
            ip: $currentRequest?->getClientIp(),
            userAgent: $currentRequest?->headers->get('User-Agent'),
        ));
    }

    /**
     * @throws \SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface
     *                                                                                       if the token is invalid or expired (AC-10) -- propagated
     *                                                                                       unchanged from the bundle for the controller to map to an
     *                                                                                       error page
     */
    public function complete(string $token, string $plainPassword): void
    {
        $entityManager = $this->openEntityManager();

        $user = $entityManager->wrapInTransaction(function () use ($entityManager, $token, $plainPassword): User {
            // Warm the identity map with a fully-hydrated User *before*
            // validateTokenAndFetchUser() below touches the association --
            // see the class docblock for the full readonly-id/lazy-proxy
            // explanation this avoids.
            $selector = substr($token, 0, self::SELECTOR_LENGTH);
            $userId = $this->resetPasswordRequestRepository->findUserIdBySelector($selector);

            if (null !== $userId) {
                $entityManager->find(User::class, $userId);
            }

            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
            \assert($user instanceof User, 'ResetPasswordRequestRepository::createResetPasswordRequest() only ever associates an App\Entity\User.');

            // Invalidate the token that was just used before it can be
            // replayed (AC-10's "refused on second use even within the
            // hour").
            $this->resetPasswordHelper->removeResetRequest($token);

            // Also sets passwordChangedAt = now() (User::setPasswordHash()'s
            // own contract) -- both the hash and this timestamp are in
            // User::isEqualTo()'s EquatableInterface signature, so every
            // other live session for this account fails at its next request
            // (AC-12).
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

            // Kill siblings: any other outstanding reset token for this
            // account is invalidated too (AC-12).
            $this->resetPasswordRequestRepository->removeRequests($user);

            return $user;
        });

        // Recorded only now that wrapInTransaction() has returned, i.e. only
        // once the password change has actually committed -- see the class
        // docblock's "Audit wiring" note for why this call sits outside the
        // transaction rather than inside it.
        $currentRequest = $this->requestStack->getCurrentRequest();

        $this->authEventRecorder->record(new AuthEventRecord(
            type: AuthEventType::PASSWORD_RESET_COMPLETED,
            outcome: AuthEventRecord::OUTCOME_SUCCESS,
            userId: $user->getId(),
            ip: $currentRequest?->getClientIp(),
            userAgent: $currentRequest?->headers->get('User-Agent'),
        ));
    }

    /**
     * Returns an open EntityManager for `User`, transparently recovering
     * from a previous call's close() (see the class docblock). Identical
     * pattern to `UserAccountService`/`EmailVerificationTokenService`'s own
     * `openEntityManager()`.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(User::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', User::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
