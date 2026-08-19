<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\AuthEventRecorder;
use App\Service\PasswordResetService;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\Generator\ResetPasswordRandomGenerator;
use SymfonyCasts\Bundle\ResetPassword\Generator\ResetPasswordTokenGenerator;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\ResetPassword\Util\ResetPasswordCleaner;

/**
 * Targeted investigation of the readonly-identifier/lazy-proxy hydration bug
 * Task 28 found and fixed in `EmailVerificationTokenService::consume()`, for
 * the sibling shape `PasswordResetService::complete()` uses. Task 28 flagged
 * `ResetPasswordRequest` explicitly as "likely carries the same latent bug"
 * without exercising it -- this is that follow-up, done as this task's own
 * instructions require: verified empirically, not assumed either way.
 *
 * `ResetPasswordRequest` maps the identical `User` association shape
 * (`#[ORM\ManyToOne(targetEntity: User::class)]`, no `fetch: EAGER`) that
 * `EmailVerificationToken` does, and
 * `SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper::validateTokenAndFetchUser()`
 * -- read directly from the installed bundle source, not assumed -- calls
 * `$resetRequest->getUser()` on an entity it just hydrated via a plain
 * `findOneBy()`, exactly the pattern that produced an uninitialized proxy in
 * Task 28's bug. `$em->clear()` before `complete()` reproduces "a genuinely
 * fresh request" without a subprocess, the same technique Task 28's own
 * plan note used for its minimal repro.
 *
 * This test was run twice while building the fix, exactly as this task's
 * instructions required: once with
 * `ResetPasswordRequestRepository::findUserIdBySelector()`'s identity-map
 * warm-up removed from `PasswordResetService::complete()`, where it failed
 * with `LogicException: Attempting to change readonly property
 * App\Entity\User::$id` -- confirming the bug reproduces here exactly as
 * Task 28 predicted -- and once with the warm-up restored, where it passes.
 * Only the passing version is committed; the failure was not assumed away.
 */
final class PasswordResetServiceIdentityMapTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PasswordResetService $service;
    private ResetPasswordHelperInterface $resetPasswordHelper;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $resetPasswordRequestRepository = self::getContainer()->get(ResetPasswordRequestRepository::class);

        // Built by hand from the bundle's own lower-level collaborators
        // (vendor/symfonycasts/reset-password-bundle/src/Resources/config/reset_password_services.php),
        // not fetched via the container: nothing in the app consumes
        // ResetPasswordHelperInterface yet either (Task 31's controller,
        // not this task), so both it and PasswordResetService itself are
        // pruned entirely by RemoveUnusedDefinitionsPass -- the same
        // "private-services-locator only keeps what something else
        // references" gap UserAccountServiceConcurrentCreationTest already
        // documents for UserAccountService, just one level deeper here.
        // Lifetime/throttle mirror config/packages/reset_password.yaml
        // (3600, 0) exactly.
        $this->resetPasswordHelper = new ResetPasswordHelper(
            new ResetPasswordTokenGenerator(
                self::getContainer()->getParameter('kernel.secret'),
                new ResetPasswordRandomGenerator(),
            ),
            new ResetPasswordCleaner($resetPasswordRequestRepository, true),
            $resetPasswordRequestRepository,
            3600,
            0,
        );

        $this->service = new PasswordResetService(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(UserRepository::class),
            $resetPasswordRequestRepository,
            $this->resetPasswordHelper,
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get('limiter.password_reset_account'),
            self::getContainer()->get('limiter.password_reset_source'),
            self::getContainer()->get(RequestStack::class),
            self::getContainer()->get(AuthEventRecorder::class),
        );

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // AuthEventRecorder (Task 34) writes through its own, genuinely
        // separate physical connection -- see its class docblock -- and
        // this test's fixture user is committed (not left to the rollback
        // above) so that connection can see it. Neither is covered by the
        // rollback above, so both need their own explicit cleanup.
        $connection->executeStatement('DELETE FROM auth_event WHERE user_id IN (SELECT id FROM app_user WHERE email = :email)', [
            'email' => 'reset-identity-map@example.test',
        ]);
        $connection->executeStatement('DELETE FROM app_user WHERE email = :email', [
            'email' => 'reset-identity-map@example.test',
        ]);

        parent::tearDown();
    }

    /**
     * The security-critical case: a real, unauthenticated visit to
     * `/reset-password/reset/{token}` never has this user already loaded in
     * the identity map by anything else in the request. `$em->clear()`
     * reproduces exactly that "never independently loaded first" condition
     * on an otherwise fully-warm test process.
     */
    public function testCompletingAResetForAUserNotAlreadyInTheIdentityMapSucceeds(): void
    {
        $user = UserFactory::activeVerified(UserRole::PLAYER, 'reset-identity-map@example.test');
        $this->em->persist($user);
        $this->em->flush();
        $userId = $user->getId();

        // Commit this fixture, then reopen the transaction the rest of this
        // test still relies on for rollback-based cleanup (see tearDown()).
        // AuthEventRecorder (Task 34) writes its PASSWORD_RESET_COMPLETED
        // row through a genuinely separate physical connection -- see its
        // class docblock -- and complete() below now records one; an
        // uncommitted $user row would be invisible to that connection, and
        // `auth_event.user_id`'s foreign key would fail exactly the way it
        // did before this fix.
        $this->em->getConnection()->commit();
        $this->em->getConnection()->beginTransaction();

        // Bypass PasswordResetService::request() deliberately -- this test
        // is about complete()'s hydration path, not the rate limiter or mail
        // dispatch request() also exercises. generateResetToken() is the
        // exact bundle call request() itself makes.
        $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        $token = $resetToken->getToken();

        // Force the next query to rehydrate a fresh entity graph from the
        // rows just written, instead of reusing the $user object already
        // sitting in the identity map from persist() above -- otherwise
        // validateTokenAndFetchUser()'s association would resolve to that
        // already-fully-initialized object and this test would prove
        // nothing about the bug it targets.
        $this->em->clear();

        // Would throw `LogicException: Attempting to change readonly
        // property App\Entity\User::$id` without the identity-map warm-up
        // documented on PasswordResetService::complete().
        $this->service->complete($token, 'a-new-reset-password-77');

        $this->em->clear();
        $freshUser = $this->em->find(User::class, $userId);

        self::assertInstanceOf(User::class, $freshUser);
        self::assertTrue(
            password_verify('a-new-reset-password-77', $freshUser->getPasswordHash()),
            'complete() must actually persist the new password hash, not merely avoid throwing.',
        );
        self::assertNotNull($freshUser->getPasswordChangedAt());
    }
}
