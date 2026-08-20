<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountEvent;
use App\Entity\CoachInvitation;
use App\Entity\TrainerCoachAssociation;
use App\Entity\User;
use App\Enum\AccountEventType;
use App\Enum\UserRole;
use App\Message\SendEmailMessage;
use App\Repository\CoachInvitationRepository;
use App\Repository\TrainerCoachAssociationRepository;
use App\Service\AccountLifecycleService;
use App\Service\CoachInvitationRequest;
use App\Service\CoachInvitationService;
use App\Tests\Support\RecordingEmailMessageHandler;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepting a coach invitation (AC-14, AC-15, AC-16, AC-18, AC-21), through
 * the real public `/coach-invitation/{token}` route --
 * `CoachInvitationController::accept()`, `CoachInvitationService::accept()`/
 * `::resolve()`, and `CoachRegistrationService::registerAndAccept()`'s
 * correctness proven end to end, the same way `PlayerShareLinkAssociationTest`/
 * `PlayerShareLinkRegistrationTest` prove their own services.
 *
 * **A reachability finding from an earlier pass, fixed by Task 26 (Task 38
 * hardening pass corrected this docblock and the test below to match):**
 * `CoachInvitationController::accept()` calls `CoachInvitationService::
 * resolve($token)` *unconditionally first*, for every request, signed in or
 * not -- and `resolve()` throws `CoachInvitationAlreadyAcceptedException`
 * the moment `isAccepted()` is true, with no exception for the coach who is
 * themselves the one who accepted it. Task 26 fixed this by giving the
 * controller a second branch: on catching that exception from `resolve()`,
 * a signed-in visitor is routed into `accept()` directly instead of being
 * refused outright, so `accept()`'s own idempotent-success branch (step 4
 * of its docblock: "an active association for this exact pair is an
 * idempotent success") is reached after all, and only a genuine stranger
 * (or a mismatched coach) sees the "already used" refusal.
 * `testSignedInCoachReFollowingTheirOwnAcceptedLinkViaHttpSucceedsIdempotently()`
 * below proves this two-branch structure directly against the real route;
 * the paired
 * `testCoachInvitationServiceAcceptItselfIsIdempotentWhenReachedDirectlyPastExpiryAc3()`
 * proves the same idempotency one layer down, calling
 * `CoachInvitationService::accept()` directly.
 */
final class CoachInvitationAcceptTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingEmailMessageHandler $mailHandler;
    private CoachInvitationService $invitationService;

    /** @var list<string> */
    private array $persistedIds = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->mailHandler = self::getContainer()->get(RecordingEmailMessageHandler::class);
        $this->invitationService = self::getContainer()->get(CoachInvitationService::class);
    }

    /**
     * Deliberately no wrapping transaction -- same reason as
     * `PlayerShareLinkRegistrationTest`/`PlayerShareLinkAssociationTest`:
     * `AccountEventRecorder` writes through its own independent physical
     * connection. `coach_invitation`/`trainer_coach_association` both
     * cascade from `app_user` on delete for the `trainer`/`coach` FKs; only
     * `account_event` needs an explicit delete first.
     */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        foreach ($this->persistedIds as $id) {
            // account_deletion_log is only ever populated by the
            // deactivated/deleted-trainer edge-case tests below (Task 39),
            // which call AccountLifecycleService::delete() -- harmless
            // no-op for every other test in this file.
            $connection->executeStatement('DELETE FROM account_deletion_log WHERE subject_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM account_event WHERE subject_user_id = :id OR actor_user_id = :id', ['id' => $id]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = :id', ['id' => $id]);
        }

        parent::tearDown();
    }

    /**
     * @see AC-14 following a valid, unused, unexpired coach ShareLink leads
     *      to a registration flow addressed to the invited email
     * @see AC-15 completing it creates the account, associates it with the
     *      inviting trainer, flips the invitation to Accepted, and the
     *      coach becomes visible in the trainer's Coaches list
     */
    public function testAcceptingAsABrandNewCoachCreatesAccountAssociationAndMarksInvitationAcceptedAc14Ac15(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $email = UserFactory::email('brand-new-coach');
        $token = $this->inviteCoach($trainer, $email);

        $crawler = $this->client->request('GET', '/coach-invitation/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $email);

        $this->client->submit($crawler->selectButton('Create account')->form([
            'coach_registration_form[firstName]' => 'Casey',
            'coach_registration_form[lastName]' => 'Coach',
            'coach_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'coach_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
            'coach_registration_form[phone]' => '+1 555-000-3333',
        ]));

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $coach) {
            $this->persistedIds[] = (string) $coach->getId();
        }

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Check your email');

        self::assertInstanceOf(User::class, $coach, 'Exactly one User row must exist for the invited email.');
        self::assertSame(UserRole::COACH, $coach->getRole());

        $associationRepository = self::getContainer()->get(TrainerCoachAssociationRepository::class);
        $association = $associationRepository->findActiveForCoach($coach);
        self::assertInstanceOf(TrainerCoachAssociation::class, $association);
        self::assertSame($trainer->getId()->toRfc4122(), $association->getTrainer()->getId()->toRfc4122());

        $this->em->clear();
        $invitation = $this->em->getRepository(CoachInvitation::class)->findOneBy(['invitedEmail' => $email]);
        self::assertInstanceOf(CoachInvitation::class, $invitation);
        self::assertTrue($invitation->isAccepted());

        // AC-15: the coach now appears in the trainer's Coaches list.
        $this->client->getCookieJar()->clear();
        $this->signIn($trainer);
        $this->client->request('GET', '/trainer/coaches');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $coach->getEmail());

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail);
        self::assertSame(SendEmailMessage::TEMPLATE_COACH_WELCOME, $mail->template);
        self::assertSame($email, $mail->to);

        // Task 39 coverage gap: COACH_INVITATION_ACCEPTED was never asserted
        // anywhere in this slice's tests. CoachInvitationService::accept()
        // records it post-commit, actor = subject = the coach, carrying
        // {trainerId, invitationId} in its context -- only when this call is
        // the one that actually flipped acceptedAt from null (see that
        // method's own docblock, step 6).
        $accountEvents = $this->em->getRepository(AccountEvent::class)->findBy(['subjectUser' => $coach]);
        self::assertCount(1, $accountEvents, 'Exactly one AccountEvent must be recorded for the accepting coach.');
        $accountEvent = $accountEvents[0];
        self::assertSame(AccountEventType::COACH_INVITATION_ACCEPTED->value, $accountEvent->getType());
        self::assertSame($coach->getId()->toRfc4122(), $accountEvent->getActorUser()?->getId()->toRfc4122());
        self::assertSame($coach->getId()->toRfc4122(), $accountEvent->getSubjectUser()->getId()->toRfc4122());
        self::assertSame($trainer->getId()->toRfc4122(), $accountEvent->getContext()['trainerId'] ?? null);
        self::assertSame($invitation->getId()->toRfc4122(), $accountEvent->getContext()['invitationId'] ?? null);
    }

    /**
     * @see AC-21 a coach ShareLink only ever completes for the invited
     *      email -- a signed-in account with a different email is refused,
     *      never silently reattributed
     */
    public function testAcceptingAsASignedInCoachWithADifferentEmailIsRefusedAc21(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $token = $this->inviteCoach($trainer, UserFactory::email('invited-coach'));

        $otherCoach = $this->persist(UserFactory::activeVerified(UserRole::COACH, UserFactory::email('other-coach')));
        $this->signIn($otherCoach);

        $this->client->request('GET', '/coach-invitation/'.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $associationRepository = self::getContainer()->get(TrainerCoachAssociationRepository::class);
        self::assertNull($associationRepository->findActiveForCoach($otherCoach));
    }

    /**
     * @see AC-21 a signed-in Player can never use someone else's coach
     *      invitation, regardless of email
     */
    public function testSignedInPlayerFollowingACoachInvitationIsRefusedAc21(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $token = $this->inviteCoach($trainer, UserFactory::email('invited-coach'));

        $player = $this->persist(UserFactory::activeVerified(UserRole::PLAYER));
        $this->signIn($player);

        $this->client->request('GET', '/coach-invitation/'.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @see AC-18 a coach ShareLink already used is refused with a message
     *      distinguishable from an expired one -- proven here by actually
     *      completing the link once (anonymous registration), then showing
     *      any second visit to the exact same token, from anywhere, is
     *      refused as "already used", never silently re-processed
     */
    public function testAcceptingAnAlreadyAcceptedLinkIsRefusedAsAlreadyUsedDistinctFromExpiredAc18(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $email = UserFactory::email('once-only-coach');
        $token = $this->inviteCoach($trainer, $email);

        $crawler = $this->client->request('GET', '/coach-invitation/'.$token);
        $this->client->submit($crawler->selectButton('Create account')->form([
            'coach_registration_form[plainPassword][first]' => UserFactory::PASSWORD,
            'coach_registration_form[plainPassword][second]' => UserFactory::PASSWORD,
        ]));
        self::assertSelectorTextContains('body', 'Check your email');

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $coach);
        $this->persistedIds[] = (string) $coach->getId();

        // A second, later visit to the exact same token -- anonymous, same
        // as the first, so this isolates AC-18's own "already used" outcome
        // without also exercising the sign-in identity checks covered
        // elsewhere in this file.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/coach-invitation/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('.alert-message', 'already been used');
    }

    /**
     * @see AC-18 the other half of the distinguishable pair: a coach
     *      ShareLink more than seven days old is refused as "expired",
     *      never confused with "already used"
     */
    public function testAcceptingAnExpiredInvitationIsRefusedAsExpiredDistinctFromAlreadyUsedAc18(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $token = $this->inviteCoach($trainer, UserFactory::email('expired-coach'));

        $this->expireInvitationFor($token);

        $this->client->request('GET', '/coach-invitation/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('.alert-message', 'expired');
    }

    /**
     * Edge case (Task 39 coverage gap): the trainer who sent a coach
     * invitation is DEACTIVATED after sending it but before it is accepted --
     * previously the one spec edge-case row with zero test coverage, and the
     * exact path that exposed Task 33's blank-refusal-message bug
     * (`ShareLinkUnavailableException` used to carry no default message).
     * `CoachInvitationService::resolve()` checks `trainer.isActive()` right
     * after the token/hash check, ahead of accepted/expired, so this renders
     * the same non-enumerating "no longer available" outcome
     * `PlayerShareLinkAssociationTest`'s equivalent trainer-deactivated test
     * proves for the player-link side -- and, per Task 33, with a real,
     * non-empty message, not just a 404 status code.
     */
    public function testAcceptingACoachInvitationWhoseTrainerHasBeenDeactivatedIsRefusedWithARealMessage(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $token = $this->inviteCoach($trainer, UserFactory::email('deactivated-trainer-coach'));

        self::getContainer()->get(AccountLifecycleService::class)->deactivate($trainer, $admin);

        $this->client->request('GET', '/coach-invitation/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains(
            '.alert-message',
            'This invitation link is no longer available.',
            'Task 33: the refusal must render a real, non-empty message -- not a blank alert.',
        );
    }

    /**
     * Same edge case as above, for a GDPR-DELETED (anonymized) trainer
     * rather than a merely deactivated one -- both must render the identical
     * non-enumerating outcome.
     */
    public function testAcceptingACoachInvitationWhoseTrainerHasBeenDeletedIsRefusedWithARealMessage(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $admin = $this->persist(UserFactory::activeVerified(UserRole::SUPER_ADMIN));
        $token = $this->inviteCoach($trainer, UserFactory::email('deleted-trainer-coach'));

        self::getContainer()->get(AccountLifecycleService::class)->delete($trainer, $admin, null);

        $this->client->request('GET', '/coach-invitation/'.$token);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains(
            '.alert-message',
            'This invitation link is no longer available.',
            'Task 33: the refusal must render a real, non-empty message -- not a blank alert.',
        );
    }

    /**
     * Edge case (capsule): a coach re-follows their own already-accepted
     * link -- `CoachInvitationService::accept()`'s own docblock documents
     * this as a deliberate idempotent-success branch, and the exact
     * ordering bug (accepted-state checked *before* expiry) the capsule
     * flags as having been fixed there. Proven directly against the
     * service, which is where that fix actually lives, well past the
     * 7-day mark -- this is the regression test for that specific
     * ordering, matching the delegation capsule's ask.
     */
    public function testCoachInvitationServiceAcceptItselfIsIdempotentWhenReachedDirectlyPastExpiryAc3(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $token = $this->inviteCoach($trainer, $coach->getEmail());

        $first = $this->invitationService->accept($token, $coach);

        // Push the invitation's expiry ten days into the past -- well
        // beyond the 7-day TTL -- *after* it was already accepted, exactly
        // the "accepted on day 2, re-followed on day 10" scenario the
        // service's own docblock names.
        $this->expireInvitationFor($token, '-10 days');

        $second = $this->invitationService->accept($token, $coach);

        self::assertSame(
            $first->getId()->toRfc4122(),
            $second->getId()->toRfc4122(),
            'Accepted-before-expiry ordering: re-accepting past the 7-day mark must return the same association, not throw CoachInvitationExpiredException.',
        );

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_coach_association WHERE trainer_id = :trainer AND coach_id = :coach',
            ['trainer' => (string) $trainer->getId(), 'coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'The idempotent re-accept must never create a second association row.');
    }

    /**
     * Same edge case as above, but driven through the real HTTP route
     * rather than the service directly -- the regression this test guards
     * (Task 38 hardening pass: renamed and rewritten to describe the
     * current, correct behaviour; see the class docblock for the Task 26
     * fix this proves). `CoachInvitationController::accept()` calls
     * `resolve()` first, which throws `CoachInvitationAlreadyAcceptedException`
     * once `isAccepted()` is true; the controller's second branch catches
     * exactly that exception and, for a signed-in visitor, calls `accept()`
     * directly instead of refusing outright. For the coach who is
     * themselves the one who accepted it, that inner `accept()` call hits
     * its own idempotent-success branch (an active association already
     * exists for this exact trainer/coach pair) and returns it rather than
     * throwing -- so the controller redirects with a success flash, exactly
     * like a fresh acceptance, even past the invitation's 7-day mark.
     */
    public function testSignedInCoachReFollowingTheirOwnAcceptedLinkViaHttpSucceedsIdempotently(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $token = $this->inviteCoach($trainer, $coach->getEmail());

        $this->invitationService->accept($token, $coach);
        $this->expireInvitationFor($token, '-10 days');

        $this->signIn($coach);
        $this->client->request('GET', '/coach-invitation/'.$token);

        self::assertResponseRedirects(
            '/',
            null,
            \sprintf(
                'AC-3/edge case: re-following your own already-accepted coach link must succeed idempotently (even past the 7-day mark), matching CoachInvitationService::accept()\'s documented behaviour -- got %d. CoachInvitationController::accept()\'s second branch (catching CoachInvitationAlreadyAcceptedException from resolve() and calling accept() directly for a signed-in visitor) is what makes this reachable.',
                $this->client->getResponse()->getStatusCode(),
            ),
        );
    }

    /**
     * @see AC-16 a coach currently, actively associated with one trainer
     *      cannot also become active under a different trainer
     */
    public function testAcceptingWhileActivelyAssociatedWithAnotherTrainerIsRefusedAc16(): void
    {
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));

        $tokenA = $this->inviteCoach($trainerA, $coach->getEmail());
        $this->invitationService->accept($tokenA, $coach);

        $tokenB = $this->inviteCoach($trainerB, $coach->getEmail());

        $this->signIn($coach);
        $this->client->request('GET', '/coach-invitation/'.$tokenB);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSelectorTextContains('.alert-message', 'already actively associated with another trainer');

        $associationRepository = self::getContainer()->get(TrainerCoachAssociationRepository::class);
        $active = $associationRepository->findActiveForCoach($coach);
        self::assertInstanceOf(TrainerCoachAssociation::class, $active);
        self::assertSame($trainerA->getId()->toRfc4122(), $active->getTrainer()->getId()->toRfc4122(), 'The original active association must be untouched.');

        $invitationRepository = self::getContainer()->get(CoachInvitationRepository::class);
        $invitationB = $invitationRepository->findOneBySelectorWithTrainer(substr($tokenB, 0, 12));
        self::assertInstanceOf(CoachInvitation::class, $invitationB);
        self::assertFalse($invitationB->isAccepted(), 'A refused acceptance attempt must never mark the invitation accepted.');
    }

    /**
     * Edge case: a coach whose association with Trainer A has ended (fixture
     * sets `endedAt` directly, per the delegation capsule -- no S3 service
     * call writes it yet) accepts a fresh invitation from Trainer B, and
     * succeeds.
     */
    public function testAcceptingAfterAPriorAssociationWasEndedSucceeds(): void
    {
        $trainerA = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-a')));
        $trainerB = $this->persist(UserFactory::activeVerified(UserRole::TRAINER, UserFactory::email('trainer-b')));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));

        $tokenA = $this->inviteCoach($trainerA, $coach->getEmail());
        $associationA = $this->invitationService->accept($tokenA, $coach);

        $this->em->getConnection()->executeStatement(
            'UPDATE trainer_coach_association SET ended_at = NOW() WHERE id = :id',
            ['id' => $associationA->getId()->toRfc4122()],
        );

        $tokenB = $this->inviteCoach($trainerB, $coach->getEmail());

        $this->signIn($coach);
        $this->client->request('GET', '/coach-invitation/'.$tokenB);

        self::assertResponseRedirects('/');

        $associationRepository = self::getContainer()->get(TrainerCoachAssociationRepository::class);
        $active = $associationRepository->findActiveForCoach($coach);
        self::assertInstanceOf(TrainerCoachAssociation::class, $active);
        self::assertSame($trainerB->getId()->toRfc4122(), $active->getTrainer()->getId()->toRfc4122());

        $totalCount = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_coach_association WHERE coach_id = :coach',
            ['coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(2, (int) $totalCount, 'The ended association with Trainer A must still exist, alongside the new one with Trainer B.');
    }

    /**
     * Edge case (capsule): two devices accepting the exact same still-
     * pending invitation at effectively the same moment. Reproduced the
     * same way `UserAccountServiceConcurrentCreationTest` reproduces its own
     * race: issuing the two `accept()` calls one after another against the
     * same open connection reproduces the real `SELECT ... FOR UPDATE`
     * serialization a genuine concurrent pair would hit -- the second call
     * only proceeds once the first's transaction has already committed.
     *
     * **Actual outcome, and why it is not "the other refused as already
     * used":** for the *same* coach identity racing on the *same*
     * still-pending invitation, `accept()`'s own idempotency branch (step 4)
     * is what the second call hits -- the first call's committed
     * association is visible to the second once its lock is granted, and it
     * matches this invitation's trainer, so the second call returns the
     * identical association rather than throwing. This is a stronger
     * guarantee than a plain refusal (no user-visible error on a double-
     * click), and is exactly what `CoachInvitationService::accept()`'s
     * docblock documents for "the coach re-follows their own accepted link"
     * -- concurrent or sequential, it is the same code path. Reported as a
     * spec-table wording nuance (not a code defect) in the delegation
     * report: the row lock genuinely makes the DB effect happen exactly
     * once, which is what this test proves.
     */
    public function testTwoDevicesAcceptingTheSameStillPendingInvitationAtOnceResultInExactlyOneAssociationRow(): void
    {
        $trainer = $this->persist(UserFactory::activeVerified(UserRole::TRAINER));
        $coach = $this->persist(UserFactory::activeVerified(UserRole::COACH));
        $token = $this->inviteCoach($trainer, $coach->getEmail());

        $deviceOne = $this->invitationService->accept($token, $coach);
        $deviceTwo = $this->invitationService->accept($token, $coach);

        self::assertSame($deviceOne->getId()->toRfc4122(), $deviceTwo->getId()->toRfc4122());

        $count = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM trainer_coach_association WHERE trainer_id = :trainer AND coach_id = :coach',
            ['trainer' => (string) $trainer->getId(), 'coach' => (string) $coach->getId()],
        )->fetchOne();
        self::assertSame(1, (int) $count, 'Exactly one association row must ever be created, however many times the same still-pending invitation is accepted.');
    }

    private function inviteCoach(User $trainer, string $email): string
    {
        $this->invitationService->invite(new CoachInvitationRequest($email), $trainer);

        $mail = $this->mailHandler->last();
        self::assertInstanceOf(SendEmailMessage::class, $mail, 'Precondition failed: invite() did not queue a mail.');
        $token = $mail->context['token'];
        self::assertIsString($token);

        return $token;
    }

    /**
     * Directly rewrites `expires_at` for the invitation identified by
     * `$token`'s selector -- there is no service call that sets an
     * arbitrary expiry, so this mirrors the capsule's own instruction to
     * manipulate fixture state directly at the storage layer.
     */
    private function expireInvitationFor(string $token, string $offset = '-1 day'): void
    {
        $selector = substr($token, 0, 12);

        $this->em->getConnection()->executeStatement(
            'UPDATE coach_invitation SET expires_at = :expiresAt WHERE selector = :selector',
            ['expiresAt' => (new \DateTimeImmutable($offset))->format('Y-m-d H:i:sO'), 'selector' => $selector],
        );

        // Written through the raw connection, bypassing the ORM -- the
        // already-loaded CoachInvitation (from inviteCoach()'s own read,
        // still resident in this EntityManager's identity map thanks to
        // disableReboot()) would otherwise keep serving its stale,
        // not-yet-expired `expiresAt` to every query in this same process.
        $this->em->clear();
    }

    private function signIn(User $user): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => UserFactory::PASSWORD,
        ]));

        self::assertResponseRedirects();
    }

    private function persist(User $user): User
    {
        $this->em->persist($user);
        $this->em->flush();
        $this->persistedIds[] = (string) $user->getId();

        return $user;
    }
}
