<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Service\Exception\InvalidVerificationTokenException;
use App\Service\Exception\VerificationTokenAlreadyConsumedException;
use App\Service\Exception\VerificationTokenExpiredException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Issues and consumes single-use email-verification tokens (AC-13, AC-14),
 * under the same selector/verifier discipline as
 * `symfonycasts/reset-password-bundle`, documented on
 * {@see EmailVerificationToken}.
 *
 * **The closed-EntityManager pitfall (same one `UserAccountService`
 * documents).** `EntityManagerInterface::wrapInTransaction()` closes its
 * EntityManager on *any* exception escaping the wrapped callback -- not just
 * a DBAL-level failure. `consume()` deliberately throws plain domain
 * exceptions (invalid/expired/already-consumed token) from inside that
 * callback for every expected rejection, so every one of those "normal"
 * outcomes leaves the shared EntityManager closed too. `openEntityManager()`
 * below is the same recovery this project's `UserAccountService::create()`
 * already uses: detect a closed manager and ask `ManagerRegistry` to reset
 * it, rather than ever reusing a closed instance.
 */
final class EmailVerificationTokenService
{
    /**
     * `random_bytes(9)`, base64url-encoded, is always exactly 12 characters
     * (9 is a multiple of 3, so base64 never pads it) -- not the 24 the
     * architecture doc's shorthand suggests, which conflates the *column's*
     * width (`varchar(24)`, generous headroom, see
     * `EmailVerificationToken::$selector`) with the *value's* actual length.
     * 12 fits comfortably inside that column. `consume()` splits on this
     * exact constant, so it must stay in lock-step with `issue()`.
     */
    private const SELECTOR_LENGTH = 12;

    private const SELECTOR_BYTES = 9;

    private const VERIFIER_BYTES = 32;

    private const TTL = 'PT24H';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly EmailVerificationTokenRepository $tokenRepository,
    ) {
    }

    /**
     * Invalidates every outstanding token for this user, then issues a fresh
     * one. Returns `selector.verifier` -- the raw string to embed in the
     * verification link; only `hash('sha256', $verifier)` is ever persisted.
     */
    public function issue(User $user): string
    {
        $entityManager = $this->openEntityManager();

        $selector = self::encodeBase64Url(random_bytes(self::SELECTOR_BYTES));
        $verifier = self::encodeBase64Url(random_bytes(self::VERIFIER_BYTES));
        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval(self::TTL));

        $entityManager->wrapInTransaction(function () use ($entityManager, $user, $selector, $verifier, $expiresAt, $now): void {
            // Must run first, in the same transaction as the insert below --
            // otherwise a request racing between the delete and the insert
            // could see two live tokens for the same user.
            $this->tokenRepository->deleteAllForUser($user);

            $entityManager->persist(new EmailVerificationToken(
                $user,
                $selector,
                hash('sha256', $verifier),
                $expiresAt,
                $now,
            ));
        });

        return $selector.$verifier;
    }

    /**
     * @throws InvalidVerificationTokenException          the selector is unknown, or the
     *                                                     verifier does not match
     * @throws VerificationTokenAlreadyConsumedException   the token was already spent
     * @throws VerificationTokenExpiredException           more than 24h have passed since issue
     */
    public function consume(string $token): User
    {
        if (\strlen($token) <= self::SELECTOR_LENGTH) {
            throw new InvalidVerificationTokenException();
        }

        $selector = substr($token, 0, self::SELECTOR_LENGTH);
        $verifier = substr($token, self::SELECTOR_LENGTH);

        $entityManager = $this->openEntityManager();

        return $entityManager->wrapInTransaction(function () use ($entityManager, $selector, $verifier): User {
            // Warm the identity map with a fully-hydrated User *before*
            // touching the token's `user` association below.
            //
            // Without this, on a genuinely fresh request (this User was
            // never independently loaded elsewhere in it -- the normal case,
            // since /verify-email/{token} is PUBLIC_ACCESS and never loads a
            // security-context user), Doctrine represents the associated
            // User as an uninitialized proxy the moment the token row is
            // hydrated. The first access that forces that proxy to fully
            // initialize (markEmailVerified() below, or even just
            // constructing one of the typed exceptions with it) makes
            // Doctrine's hydrator try to *re-set* every mapped field on it,
            // including the identifier -- and User::$id is a readonly,
            // object-typed (Uuid) property. Doctrine's own readonly-property
            // guard only special-cases a value that has never been
            // initialized, or one whose new value is `===` to the old one;
            // a proxy's pre-populated `$id` and the hydrator's freshly
            // constructed `Uuid` for the same row are two different Uuid
            // *instances*, so `!==` (object identity, not value equality)
            // trips every time and Doctrine throws "Attempting to change
            // readonly property App\Entity\User::$id" -- confirmed
            // reproducible even with the association mapped `fetch: EAGER`,
            // since eager-joined hydration goes through the same
            // stub-then-fill path for an already-referenced association
            // target. Loading the User directly here, as a plain top-level
            // query with no association involved, is the one path that
            // hydrates it in a single pass and avoids the conflict
            // entirely; once it is already fully-initialized and tracked in
            // the identity map, the token query below returns that same
            // object for `t.user` instead of creating a proxy for it at all.
            //
            // This is a Doctrine ORM limitation (readonly, object-typed
            // identifiers vs. lazy/joined association hydration), not a
            // defect in this schema -- `ResetPasswordRequest` and
            // `AuthEvent` map the identical `User` association shape and
            // likely carry the same latent bug wherever they load a User
            // that was not already independently loaded first; flagged for
            // a follow-up pass rather than fixed here, since neither is
            // exercised by this task.
            $userId = $this->tokenRepository->findUserIdBySelector($selector);

            if (null !== $userId) {
                $entityManager->find(User::class, $userId);
            }

            // FOR UPDATE: two simultaneous clicks on the same link both reach
            // this line, but only one gets the lock first; the second blocks
            // until the first's transaction commits, then sees the
            // now-updated consumedAt and is refused below -- exactly one
            // consumption succeeds (AC-13, AC-14).
            $tokenEntity = $this->tokenRepository->findOneBySelectorForUpdate($selector);

            if (null === $tokenEntity || !hash_equals($tokenEntity->getHashedVerifier(), hash('sha256', $verifier))) {
                throw new InvalidVerificationTokenException();
            }

            $now = new \DateTimeImmutable();

            if ($tokenEntity->isConsumed()) {
                throw new VerificationTokenAlreadyConsumedException($tokenEntity->getUser());
            }

            if ($tokenEntity->isExpired($now)) {
                throw new VerificationTokenExpiredException($tokenEntity->getUser());
            }

            $tokenEntity->consume($now);
            $tokenEntity->getUser()->markEmailVerified($now);
            $entityManager->persist($tokenEntity->getUser());

            return $tokenEntity->getUser();
        });
    }

    private static function encodeBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * Returns an open EntityManager for `EmailVerificationToken`,
     * transparently recovering from a previous call's close() (see the class
     * docblock). Identical pattern to `UserAccountService::openEntityManager()`.
     */
    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->managerRegistry->getManagerForClass(EmailVerificationToken::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No ORM EntityManager is registered for "%s".', EmailVerificationToken::class));
        }

        if (!$manager->isOpen()) {
            $manager = $this->managerRegistry->resetManager();
        }

        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
