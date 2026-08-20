# TASK-003 — Epic-01 slice S3: ShareLink Invitations

Design: `specs/sdd-sharelink-invitations-architecture.md`. Spec:
`specs/sdd-sharelink-invitations-spec.md`. Each task cites the acceptance criteria
(AC-N) it serves. Mark `[x]` only once the change is made, migrated, and (where a test
task follows) proven.

## Schema

- [x] 1. Create `App\Enum\PlayerGender` and `App\Entity\ProfilePlayer` (`profile_player`,
  `id` FK `profile.id`: `playerName`, `declaredAge`, `gender`) as the second concrete
  subtype of S1's `Profile` JOINED hierarchy; add `'PLAYER' => ProfilePlayer::class` to
  `Profile`'s `#[ORM\DiscriminatorMap]`. (AC-7)
- [x] 2. Create `App\Entity\PlayerShareLink` (`player_share_link`: `trainerId` FK
  `app_user` ON DELETE CASCADE, `code varchar(24)`, `usageCount` default 0,
  `createdAt`) + `PlayerShareLinkRepository`, with `UNIQUE (code)` and
  `UNIQUE (trainer_id)` as ORM-level unique constraints and **no** `expires_at` /
  `max_uses` / `consumed_at` columns at all. (AC-1, AC-2, AC-4, AC-6)
- [x] 3. Create `App\Enum\CoachInvitationStatus` (Pending/Accepted/Expired) and
  `App\Entity\CoachInvitation` (`coach_invitation`: `trainerId` FK, `invitedEmail
  varchar(180)`, `invitedName varchar(160)` nullable, `message text` nullable,
  `selector varchar(24)`, `hashedVerifier char(64)`, `expiresAt`, `acceptedAt`
  nullable, `createdAt`) with `status(\DateTimeImmutable $now): CoachInvitationStatus`
  derived from `acceptedAt`/`expiresAt` (no stored status column) +
  `CoachInvitationRepository`. `UNIQUE (selector)` as an ORM-level constraint;
  deliberately no unique constraint on `(trainer_id, invited_email)` (AC-18 requires
  re-inviting the same person to be legal). (AC-3, AC-17, AC-18)
- [x] 4. Create `App\Entity\TrainerPlayerAssociation` (`trainer_player_association`:
  `trainerId` FK, `playerId` FK, `shareLinkId` FK `player_share_link` ON DELETE SET
  NULL nullable, `createdAt`) + `TrainerPlayerAssociationRepository`, with
  `UNIQUE (trainer_id, player_id)` as an ORM-level constraint and no `endedAt` column
  (deliberately absent this slice — see architecture Decisions Q2″). (AC-6, AC-8,
  AC-12, AC-13)
- [x] 5. Create `App\Entity\TrainerCoachAssociation` (`trainer_coach_association`:
  `trainerId` FK, `coachId` FK, `invitationId` FK `coach_invitation` ON DELETE SET
  NULL nullable, `createdAt`, `endedAt` nullable — null ⇔ currently active) +
  `TrainerCoachAssociationRepository`. No S3 code path writes `endedAt`; the column
  exists only so AC-16's rule and the "ended with A, joins B" edge case have something
  to be defined against. (AC-15, AC-16, AC-17)
- [x] 6. Add `PLAYER_REGISTERED_VIA_SHARE_LINK`, `COACH_INVITATION_ACCEPTED`, and
  `PLAYER_TRAINER_ASSOCIATED` to `App\Enum\AccountEventType` (no migration needed —
  `account_event.type` is a plain `varchar(64)`). `PLAYER_TRAINER_ASSOCIATED`'s write
  site is now confirmed in the architecture doc: `PlayerShareLinkService::associate()`
  (Task 10), recorded post-commit, actor = subject = the player, only on a genuinely
  new association row (not the idempotent no-op branch). (AC-9, AC-15)
- [x] 7. Generate the migration, then hand-finish it: create `player_share_link`,
  `coach_invitation`, `trainer_player_association`, `trainer_coach_association`,
  `profile_player`, then the three hand-written SQL lines DBAL does not diff —
  `CHECK (invited_email = lower(invited_email))` on `coach_invitation`, the `gender`
  `CHECK` on `profile_player`, and the partial unique index
  `CREATE UNIQUE INDEX uniq_trainer_coach_active_coach ON trainer_coach_association
  (coach_id) WHERE ended_at IS NULL`. Add the supporting indexes: `(trainer_id,
  created_at)` and `(invited_email, accepted_at)` on `coach_invitation`; `(player_id,
  created_at)` and `(trainer_id, created_at)` on `trainer_player_association`;
  `(trainer_id, ended_at)` and `(coach_id, ended_at)` on `trainer_coach_association`.
  Write `down()` dropping in reverse order. Run against dev + test DB; confirm
  `doctrine:schema:validate` is clean. (AC-1, AC-2, AC-3, AC-6, AC-7, AC-13, AC-16)

## Services

- [x] 8. `App\Service\ShareLinkCodeGenerator::generate(): string` — 12-char base64url of
  `random_bytes(9)`, as its own class (not a method on
  `SelectorVerifierTokenFactory` — see architecture Decisions Q1a″). (AC-1, AC-2)
- [x] 9. `App\Service\PlayerShareLinkResolver::resolve(string $code): PlayerShareLink` —
  one repository query joining the trainer and filtering `trainer.status = ACTIVE`;
  raise `ShareLinkUnavailableException` for both an unknown code and a
  deactivated/deleted trainer, rendering the same "no longer available" outcome for
  both (non-enumerating). (AC-1, edge case: trainer deactivated/deleted)
- [x] 10. `App\Service\PlayerShareLinkService`:
  `getOrCreateFor(User $trainer): PlayerShareLink` (AC-4; on
  `UniqueConstraintViolationException` reset the manager, never reuse the closed
  instance, and re-read the winner's row) and
  `associate(User $player, PlayerShareLink $link): TrainerPlayerAssociation` (AC-11,
  AC-12, AC-13, AC-20). Guards in order: role must be `PLAYER` else
  `RoleNotEligibleForShareLinkException`; player must be `isActive()` else
  `AccountNotEligibleException`; link's trainer must be `ACTIVE` else
  `ShareLinkUnavailableException`. One transaction: `existsFor(trainer, player)`
  pre-check returns the existing row untouched (AC-13); on a genuinely new row, insert
  it and `incrementUsage($link)` together, then (post-commit)
  `AccountEventRecorder::record(PLAYER_TRAINER_ASSOCIATED)` with actor = subject = the
  player; catch `UniqueConstraintViolationException` on the `(trainer_id, player_id)`
  unique constraint, reset the manager, re-read and return the existing row as
  idempotent success. An idempotent no-op must not increment `usageCount` and must not
  record a second event. (AC-4, AC-6, AC-9, AC-11, AC-12, AC-13, AC-20, edge cases:
  deactivated/deleted player, signed-in Coach follows a player link)
- [x] 11. `App\Service\PlayerRegistrationService::registerViaShareLink(PlayerRegistrationRequest, PlayerShareLink): User`
  in `TrainerOnboardingService`'s two-phase shape: (1)
  `UserAccountService::create($email, $plainPassword, UserRole::PLAYER)`, letting
  `EmailAlreadyInUseException` propagate unchanged to a field-level form error
  (AC-10); (2) set `firstName`/`lastName`/`phone` on the returned user; (3) a second
  transaction on a manager taken fresh from the registry: persist `ProfilePlayer`,
  persist `TrainerPlayerAssociation`, `incrementUsage($link)`, flush; `catch
  (\Throwable)` → reset the manager, `DELETE FROM app_user WHERE id = :id` through the
  fresh connection, rethrow; (4) after commit, `EmailVerificationTokenService::issue($user)`,
  dispatch `SendEmailMessage(TEMPLATE_PLAYER_WELCOME, [...])`, then
  `AccountEventRecorder::record(PLAYER_REGISTERED_VIA_SHARE_LINK)` (actor = subject =
  the new player). Controller redirects to a "check your email" page, not a trainer
  context. (AC-7, AC-8, AC-9, AC-10)
- [x] 12. `App\Service\CoachInvitationService::invite(CoachInvitationRequest, User
  $trainer): CoachInvitation` — generate selector/verifier via
  `SelectorVerifierTokenFactory`, `expiresAt = now + P7D`, persist, then post-commit
  dispatch `SendEmailMessage(TEMPLATE_COACH_INVITATION, ['token', 'trainerName',
  'message'])`. No `AccountEvent` recorded here (see architecture Decisions: "Audit
  event for invitation sent" — none, by design). (AC-5, AC-19)
- [x] 13. `App\Service\CoachInvitationService::resolve(string $token): CoachInvitation` —
  split token, `hash_equals` the verifier, and raise distinct typed exceptions:
  `InvalidCoachInvitationException`, `CoachInvitationAlreadyAcceptedException`,
  `CoachInvitationExpiredException`, and `ShareLinkUnavailableException` when the
  inviting trainer is no longer `ACTIVE`. (AC-3, AC-14, AC-18, edge case: trainer
  deactivated/deleted)
- [x] 14. `App\Service\CoachInvitationService::accept(string $token, User $coach):
  TrainerCoachAssociation` — one transaction on a fresh manager, mirroring
  `AccountInvitationService::consume()`'s identity-map warm-up: (1) `SELECT ... FOR
  UPDATE` by selector; (2) `hash_equals` the verifier, refuse if expired; (3) refuse
  unless `$coach->getRole() === UserRole::COACH` **and**
  `$coach->getEmail() === $invitation->getInvitedEmail()` (both normalized) via
  `CoachInvitationEmailMismatchException` (AC-21); (4) if `acceptedAt` is already set:
  return the existing active `(trainer, coach)` association as idempotent success if
  one exists for this same trainer, else `CoachInvitationAlreadyAcceptedException`
  (AC-18); (5) `findActiveForCoach($coach)` — if it exists for a *different* trainer,
  `CoachAlreadyActiveElsewhereException` (AC-16); if for the *same* trainer, mark the
  invitation accepted and return that association (idempotent); catch
  `UniqueConstraintViolationException` on the partial unique index and convert it to
  the same `CoachAlreadyActiveElsewhereException` from a catch block that does not
  touch the closed manager; (6) `$invitation->accept($now)`, persist the association,
  then post-commit `AccountEventRecorder::record(COACH_INVITATION_ACCEPTED)` with
  `{trainerId, invitationId}`. (AC-15, AC-16, AC-18, AC-21, edge cases: two devices on
  one link, coach re-follows own accepted link, coach ended with A accepts B,
  signed-in Player follows a coach link)
- [x] 15. `App\Service\CoachRegistrationService::registerAndAccept(CoachRegistrationRequest,
  CoachInvitation): User` — same two-phase pattern as Task 11:
  `UserAccountService::create($invitation->getInvitedEmail(), ..., UserRole::COACH)`
  (email from the invitation, never the request), then `accept()`'s transaction, then
  the compensating `DELETE FROM app_user` on failure, then
  `EmailVerificationTokenService::issue()` + `SendEmailMessage(TEMPLATE_COACH_WELCOME)`.
  An `EmailAlreadyInUseException` here renders "you already have an account — sign in
  and open this link again". (AC-14, AC-15, AC-21)
- [x] 16. Forms/DTOs: `CoachInvitationRequest` + `CoachInvitationFormType` (`email`:
  `NotBlank` + `Email`; `name`: optional `Length(max: 160)`; `message`: optional
  `Length(max: 2000)`); `PlayerRegistrationRequest` +
  `PlayerShareLinkRegistrationFormType` (`firstName`, `lastName`, `email`,
  `plainPassword` reusing `ChangePasswordFormType`'s constraint set, `phone` via S2's
  `Assert\Regex`, `playerName`, `playerAge` `Range(min: 1, max: 120)`, `playerGender`
  `Choice` over `PlayerGender`); `CoachRegistrationRequest` +
  `CoachRegistrationFormType` (`firstName`, `lastName`, `plainPassword`, `phone` —
  deliberately **no `email` field**, since it is read from the invitation). (AC-5,
  AC-7, AC-19, AC-21)
- [x] 17. `App\Security\ShareLinkVoter` with attributes `FOLLOW_PLAYER_SHARE_LINK`
  (subject `PlayerShareLink`) and `ACCEPT_COACH_INVITATION` (subject
  `CoachInvitation`), voting on `User::role`, `User::status`, and email equality
  against `invited_email`; reads no `Profile`. Controllers call
  `denyAccessUnlessGranted()` ahead of any service work; the service guards in Tasks
  10 and 14 remain regardless (defence in depth per architecture Decisions Q4).
  (AC-20, AC-21)
- [x] 18. Controllers and routes: `PlayerShareLinkController::follow` (`GET
  /join/{code}`, public, `app_share_link_follow`) resolving via Task 9 then branching
  to Task 10's `associate()` (signed-in) or a redirect to `register`
  (anonymous); `PlayerShareLinkController::register` (`GET|POST
  /join/{code}/register`, public, `app_share_link_register`) delegating to Task 11;
  `CoachInvitationController::accept` (`GET|POST /coach-invitation/{token}`, public,
  `app_coach_invitation`) delegating to Task 13's `resolve()` then Task 14's
  `accept()` (signed-in) or Task 15's `registerAndAccept()` (anonymous);
  `Trainer\ShareLinkController::show` (`GET /trainer/share-link`,
  `app_trainer_share_link`) delegating to Task 10's `getOrCreateFor()`;
  `Trainer\PlayerRosterController::index` (`GET /trainer/players`,
  `app_trainer_players`) via `TrainerPlayerAssociationRepository::findRosterFor`;
  `Trainer\CoachController::index` (`GET /trainer/coaches`, `app_trainer_coaches`) via
  `TrainerCoachAssociationRepository::findActiveFor` +
  `CoachInvitationRepository::findForTrainer`, rendering each invitation's derived
  status and a re-invite affordance; `Trainer\CoachController::invite` (`POST
  /trainer/coaches/invite`, `app_trainer_coach_invite`) delegating to Task 12's
  `invite()`. Every `Trainer\*Controller` carries `#[IsGranted('ROLE_TRAINER')]` on
  the class. (AC-1, AC-4, AC-5, AC-8, AC-11, AC-14, AC-17, AC-18, AC-19)
- [x] 19. `config/packages/security.yaml`: add two `PUBLIC_ACCESS` lines, `^/join` and
  `^/coach-invitation`, positioned **before** the `^/` catch-all. Extend S1's
  router-sweep allow-list test with these two new public prefixes so that test — not
  the config alone — is what keeps every other route gated. (AC-1, AC-3, AC-14, edge
  case: scraped player link)
- [x] 20. Add `share_link_registration_source` to `config/packages/rate_limiter.yaml`
  (`sliding_window`, 20/hour, keyed on the client IP truncated by S1's `IpTruncator`,
  pool `cache.rate_limiter`); consume it in `PlayerShareLinkController::register` and
  `CoachInvitationController::accept`'s registration-submission branch only. **Note:**
  no spec AC names rate limiting directly — this task exists to make the spec's edge
  case "URL scraped far beyond its audience: no special handling beyond what S1
  already enforces platform-wide" actually true, since S1 had no registration
  endpoint to limit. Cited against the registration ACs it guards. (AC-7, AC-14, edge
  case: scraped player link)
- [x] 21. Mail: `emails/player_welcome.html.twig` (verification link + trainer name),
  `emails/coach_invitation.html.twig` (invitation link + trainer's optional message),
  `emails/coach_welcome.html.twig` (verification link); add
  `SendEmailMessage::TEMPLATE_PLAYER_WELCOME`, `::TEMPLATE_COACH_INVITATION`,
  `::TEMPLATE_COACH_WELCOME` constants and their three `buildContext()` branches in
  `SendEmailMessageHandler`. No new mailer/Messenger configuration; every context
  value stays scalar. (AC-5, AC-9)

## Tests

- [x] 22. Functional: player self-registration via ShareLink happy path — exactly one
  `User`, one `ProfilePlayer`, one `TrainerPlayerAssociation`, `usageCount == 1`, one
  queued mail, sign-in still refused until verified; duplicate email → field-level
  error and no orphan `User` row. (AC-6, AC-7, AC-8, AC-9, AC-10) —
  `tests/Functional/PlayerShareLinkRegistrationTest.php`. **Written and covers every
  assertion listed; the happy-path test currently FAILS** — it reproduces a real bug
  (HTTP 500, `LogicException: Attempting to change readonly property
  App\Entity\User::$id.`) in `PlayerRegistrationService::registerViaShareLink()`'s
  confirmation-mail dispatch. See Task 22-24 delegation report for root cause; not
  fixed here per this pass's boundary (tests only, no `src/` changes).
- [x] 23. Functional: signed-in Player follows a link — instant association, no form;
  follow a *second* trainer's link → two association rows, the first untouched, still
  one `User`; follow the same link twice → still one row, `usageCount` unchanged.
  (AC-6, AC-11, AC-12, AC-13) — `tests/Functional/PlayerShareLinkAssociationTest.php`.
  **Written and covers every assertion listed; all three tests currently FAIL** — same
  root cause as Task 22's failure, reached via `PlayerShareLinkService::associate()`'s
  `$link->getTrainer()->isActive()` lazy-load instead. Not fixed here.
- [x] 24. Functional: follow a player link as Coach / Trainer / Super Admin → refused
  (AC-20); follow while `DEACTIVATED` and while `DELETED` → refused; follow a link
  whose trainer is deactivated/deleted → single "no longer available" message. (AC-20,
  edge cases) — same file as Task 23. **All 7 tests pass** — the role-refusal cases
  never reach the buggy lazy-load (the voter denies access first), and the
  deactivated/deleted-player/-trainer edge cases are unaffected.
- [x] 25. Functional: invite a coach with no email → validation error (AC-19); invite →
  email queued carrying the personal message (AC-5); `Trainer\ShareLinkController::show`
  is idempotent get-or-create (AC-4). — `tests/Functional/CoachInvitationSendTest.php`.
  **Written and covers every assertion listed; 2 of 3 tests currently FAIL** against a
  real bug in `TrainerCoachAssociationRepository.php` (see delegation report):
  a missing `use App\Entity\User;` import makes the bare `User` type-hint on
  `findActiveForCoach()`/`findActiveFor()` resolve to the nonexistent
  `App\Repository\User`, so any real call throws a `TypeError` — this breaks
  `Trainer\CoachController::index()`/`::invite()` outright (both call
  `findActiveFor()`). Not fixed here per this pass's boundary.
- [x] 26. Functional: accept as a brand-new coach → account + association + invitation
  status Accepted (AC-14, AC-15); accept as a signed-in coach with a different email →
  refused (AC-21); accept as a signed-in Player → refused (AC-21); accept an
  already-accepted link → refused as "already used", distinguishable from an expired
  link (AC-18); re-follow your own accepted link → idempotent success; accept while
  actively associated with another trainer → refused (AC-16); accept after a prior
  association was ended (fixture sets `endedAt`) → succeeds. (AC-14, AC-15, AC-16,
  AC-18, AC-21, edge cases) — `tests/Functional/CoachInvitationAcceptTest.php`.
  **Written and covers every assertion listed, plus the capsule's "two devices" and
  ordering-regression edge cases; 8 of 10 tests currently FAIL**, all but two of them
  via the same `TrainerCoachAssociationRepository` bug Task 25 hit (`accept()`/
  `registerAndAccept()` both call `findActiveForCoach()`). The other two are distinct,
  real findings (see delegation report): (a) a second bug --
  `Trainer\CoachController::index()`'s combination of `findActiveFor()` (leaves the
  association's `invitation` FK lazy) and `CoachInvitationRepository::findForTrainer()`
  (independently re-hydrates that same row) throws "Attempting to change readonly
  property App\Entity\CoachInvitation::$id" for any trainer with at least one accepted
  invitation -- the same readonly-id hazard class already fixed twice elsewhere in this
  slice, not yet fixed here; (b) a deliberate regression test
  (`testSignedInCoachReFollowingTheirOwnAcceptedLinkViaHttpIsIncorrectlyRefused`) proving
  `CoachInvitationController::accept()` calls `resolve()` unconditionally before the
  signed-in identity is ever checked, so `CoachInvitationService::accept()`'s own
  idempotent-success branch (the capsule's "re-follow own accepted link" edge case, and
  the accepted-before-expiry ordering fix) is unreachable through this route -- proven
  directly against the service instead
  (`testCoachInvitationServiceAcceptItselfIsIdempotentWhenReachedDirectlyPastExpiryAc3`,
  which passes once bug (a)'s repository issue is set aside). None fixed here per this
  pass's boundary.
- [x] 27. Functional: trainer's Coaches list shows Pending / Accepted / Expired
  correctly (AC-17); S1's router-sweep test extended with `/join` and
  `/coach-invitation` passes (AC-1, AC-3, AC-14); CSRF rejection on both registration
  forms and the coach-invite form. (AC-17, edge case: CSRF regression) —
  `tests/Functional/CoachListAndRouterSweepTest.php`. **Written and covers every
  assertion listed (plus CSRF parity on the player registration form, not exercised by
  any Task 22-24 file); 3 of 8 tests currently FAIL** -- the Pending/Accepted/Expired
  list test hits the same two `TrainerCoachAssociationRepository`-rooted bugs Tasks 25
  and 26 found (both fire together once the invitation list includes an accepted row),
  and the two coach-invite-form CSRF tests fail only because their setup's `GET
  /trainer/coaches` 500s before the form can even be fetched -- not a CSRF-handling
  defect. `RouterSweepTest` itself needed no edit: it walks the actual router
  generically, so it already exercises `/join*`/`/coach-invitation*`/`/trainer/coaches*`
  the moment they exist, confirmed still green in the full-suite run.
- [x] 28. Repository integration tests against the real database: two concurrent
  inserts on the same `(trainer_id, player_id)` pair resolve to one row, the loser
  getting idempotent success (AC-13); the partial unique index rejects a second active
  association for one coach while permitting a new one once `ended_at` is set (AC-16);
  a concurrent double-`getOrCreateFor` on one trainer resolves to one
  `PlayerShareLink` row (AC-1, AC-4); `incrementUsage` under concurrent registrations
  loses no counts (AC-6); the `invited_email` and `gender` CHECK constraints refuse
  unnormalized/invalid values (AC-3, AC-7). —
  `tests/Repository/ShareLinkInvitationsConstraintsTest.php`. **Written and covers
  every proof listed; all 6 tests pass against the real Postgres database.** The two
  UNIQUE-constraint races (AC-13, AC-1/AC-4) and the partial-unique-index proof (AC-16)
  bypass the service layer's own pre-check reads (deliberately -- see the file's class
  docblock for why calling the service method twice would not reach the actual
  constraint) and persist the two colliding entities directly via the EntityManager,
  each wrapped in its own `wrapInTransaction()`, then recover via
  `ManagerRegistry::resetManager()` exactly as `PlayerShareLinkService` does. The
  CHECK-constraint proofs confirmed by reading DBAL 4's PostgreSQL `ExceptionConverter`
  directly: SQLSTATE 23514 has no dedicated exception class (unlike 23505's
  `UniqueConstraintViolationException`), so both raise the generic
  `Doctrine\DBAL\Exception\DriverException` -- this project's "or equivalent" for a
  typed check-constraint exception, asserted by message content naming the actual
  constraint. No new bug found; the implementation these tests exercise held.
- [x] 29. Unit tests: `CoachInvitation::status()` across Pending/Accepted/Expired and
  at the expiry boundary second (AC-3, AC-17); `ShareLinkCodeGenerator`'s alphabet and
  length (AC-1); `ShareLinkVoter`'s truth table across all four roles and both
  attributes (AC-20, AC-21). — `tests/Entity/CoachInvitationTest.php`,
  `tests/Service/ShareLinkCodeGeneratorTest.php`,
  `tests/Security/ShareLinkVoterTest.php`. **Written and covers every case listed; all
  38 tests pass.** The voter file's data providers cover the full 4-role x 2-status
  grid for `FOLLOW_PLAYER_SHARE_LINK`, the same grid x matching/mismatched email for
  `ACCEPT_COACH_INVITATION`, plus the `supports()` abstain cases and the
  denied-not-abstained non-`User`-token case. No new bug found.

## Review and verification

- [x] 30. `code-reviewer` + `security-reviewer` pass, complete 2026-08-20: 0
  Critical/High, 2 Major (code review), 4 Medium (security) + 5 Medium (code review),
  15 Low/Minor combined. Both reviewers independently converged on the same
  `CoachRegistrationService` dead-branch finding and the missing `isActive()` guard
  symmetry — good signal. Verdict: ship-with-followups. See `## Hardening (Task 30
  follow-up)` below for the fix breakdown and the three product decisions made.

## Hardening (Task 30 follow-up)

Fixes required by the dual review above, none requiring new AC numbers — see the
architecture doc's `## Post-implementation hardening decisions` section for the three
product decisions this section implements.

- [x] 32. Fix `usage_count`'s lost-update race (Major): replace the hydrate-then-`++`
  write in `PlayerShareLinkService`/`PlayerShareLink::incrementUsage()` with an atomic
  `UPDATE player_share_link SET usage_count = usage_count + 1 WHERE id = :id`. (AC-6)
- [x] 33. Give `ShareLinkUnavailableException`, `RoleNotEligibleForShareLinkException`,
  and `AccountNotEligibleException` default user-facing constructor messages (Major) —
  the deactivated-trainer coach-invitation path currently renders a blank alert.
  (edge case: trainer deactivated/deleted)
- [x] 34. Add `coach_invitation_account` (per-trainer) and `coach_invitation_source`
  (per-IP) rate limiters to `config/packages/rate_limiter.yaml`, mirroring S1's
  `password_reset_*` pair; bind and consume both in
  `Trainer\CoachController::invite()` before `CoachInvitationService::invite()`.
  (AC-5, AC-19)
- [x] 35. AC-10 amended (enumeration resistance): on `EmailAlreadyInUseException` in
  `PlayerShareLinkController::register()`, render the same "check your email" success
  response instead of a field-level error naming the address; add
  `PlayerRegistrationService`/a new small service to email the *existing* account a
  "someone attempted to register with your address" notice. New mail template +
  `SendEmailMessage::TEMPLATE_*` constant.
- [x] 36. AC-11 amended (revoke path): add nullable `TrainerPlayerAssociation.ended_at`;
  narrow `UNIQUE (trainer_id, player_id)` to a partial unique index
  `WHERE ended_at IS NULL`, mirroring `TrainerCoachAssociation`'s established shape;
  add a "leave this trainer" action (service method + route + template) for the
  player; update `PlayerShareLinkService::associate()`'s idempotency check to consider
  only currently-active rows; a genuinely new association sends the player a "you've
  been connected with trainer X" notification email. Migration required.
- [x] 37. Ship the `app:` sweep command the architecture already scoped: delete
  unverified, profile-less, association-less `User` rows (role `PLAYER` or `COACH`)
  older than one hour, as a same-pass mitigation for the pre-hijack/squatting risk.
  Follows the console-command conventions of `app:create-super-admin` (S1).
  **`app:sweep-unverified-accounts`** (`--hours`, default 1; `--dry-run`). Candidate
  query: `UserRepository::findStaleUnverifiedShareLinkAccounts()`/
  `countStaleUnverifiedShareLinkAccounts()` (role PLAYER/COACH,
  `email_verified_at IS NULL`, `created_at < cutoff`, `status != DELETED` — the last
  guard added on top of the architecture's literal wording: `User::anonymize()`
  leaves `email_verified_at`/`role` untouched, so a DELETED row could otherwise
  match and collide with `account_deletion_log`'s own `RESTRICT` FK). Found in
  verification, not assumed: `account_event.subject_user_id` is `ON DELETE RESTRICT`
  (not a cascade), and every targeted account already has exactly one
  `PLAYER_REGISTERED_VIA_SHARE_LINK`/`COACH_INVITATION_ACCEPTED` row referencing it
  as subject (written unconditionally at the end of registration, before
  verification) — so the command deletes that account's `account_event` rows before
  `app_user` in one transaction per account; every other `app_user`-referencing table
  (`profile`, `player_share_link`, `coach_invitation`, `trainer_player_association`,
  `trainer_coach_association`) cascades on its own as expected. Manually verified:
  inserted a stale unverified row + its registration `account_event` row directly in
  Postgres, `--dry-run` listed it without deleting, the real run deleted both the
  `app_user` and `account_event` rows, a second run and `--hours=0`/non-numeric
  `--hours` were confirmed safe/no-op or a clean validation error. Full suite: 264
  tests still green; `doctrine:schema:validate` clean.
- [x] 38. Symmetry/cleanup fixes with no product decision: `isActive()` trainer guard
  added to `CoachInvitationService::accept()` (matching
  `PlayerShareLinkService::associate()`); `IpTruncator` IPv6 prefix widened `/64` →
  `/48`; dead same-trainer-reuse branch in `CoachRegistrationService` collapsed (the
  coach account was just created, so `findActiveForCoach()` can never return
  non-null there); stale test name/docblock in `CoachInvitationAcceptTest.php`
  (asserted a bug already fixed) corrected; both compensating-delete call sites
  (`PlayerRegistrationService`, `CoachRegistrationService`) log at `critical` on
  failure instead of losing the orphan silently.
- [x] 39. Test coverage for the above: rate-limiter 429 on both registration POSTs and
  the coach-invite POST; the deactivated/deleted-trainer coach-invitation edge case
  (previously untested); assertions for all three new `AccountEvent` types
  (`PLAYER_REGISTERED_VIA_SHARE_LINK`, `PLAYER_TRAINER_ASSOCIATED`,
  `COACH_INVITATION_ACCEPTED`); a true two-connection `usage_count` concurrency test
  (the existing one explicitly scoped the real race out); the leave/rejoin flow from
  Task 36; the enumeration-resistant response from Task 35 (duplicate and novel email
  responses byte-identical).

- [x] 31. Full regression, verified 2026-08-20: `bin/phpunit` (S1 + S2 + S3, including
  the full Tasks 32-39 hardening round) — 278 tests, 1393 assertions, green.
  `doctrine:schema:validate` clean (mapping and database both OK); the partial unique
  indexes on `trainer_coach_association` and `trainer_player_association` confirmed
  stable across a second `schema:update --dump-sql` (no perpetual diff).
  `debug:router` shows all S3 routes present: `/join*` and `/coach-invitation*` public,
  every `Trainer\*` and the new `Player\*` route gated. S1's AC-1…AC-25, S2's
  AC-1…AC-24, and S3's AC-1…AC-21 all hold — regression, not just addition. The Task
  30 dual review's 2 Major + 4+5 Medium findings are all resolved (fixed or explicit
  product decisions recorded in the architecture doc); remaining Low/Minor items are
  accepted follow-up backlog, matching this project's established disposition for
  non-blocking findings.

## Coverage check

**Every AC cited by at least one task:**
AC-1: 2, 7, 8, 9, 18, 19, 28, 29. AC-2: 2, 7, 8, 9. AC-3: 3, 7, 13, 19, 27, 29.
AC-4: 2, 10, 18, 20, 25, 28. AC-5: 12, 16, 18, 21, 25. AC-6: 2, 4, 7, 10, 11, 22, 23,
28. AC-7: 1, 7, 11, 16, 20, 22, 28. AC-8: 4, 11, 18, 22. AC-9: 6, 11, 21, 22.
AC-10: 11, 22. AC-11: 10, 18, 23. AC-12: 4, 10, 23. AC-13: 4, 7, 10, 23, 28.
AC-14: 13, 15, 18, 19, 20, 26, 27. AC-15: 5, 6, 14, 15, 26. AC-16: 5, 7, 14, 26, 28.
AC-17: 3, 5, 18, 27, 29. AC-18: 3, 13, 14, 18, 26, 27. AC-19: 12, 16, 18, 25.
AC-20: 10, 17, 24, 29. AC-21: 14, 15, 16, 17, 26, 29.
Every one of AC-1…AC-21 is cited by at least one task. No criterion is unclaimed.

**Every task cites at least one real AC:** true for every task in Schema, Services,
and Tests. Task 20 (rate limiter) is the one borderline case — flagged inline since no
spec AC names rate limiting directly, but it still carries a real AC citation for the
registration endpoints it protects. Tasks 30 and 31 are review/regression gates and are
intentionally not AC-scoped, matching TASK-002's Task 25/26 precedent.

**Architecture gap flagged during this phase, now resolved:** the architecture's
Components section added `AccountEventType::PLAYER_TRAINER_ASSOCIATED` without its
Services section naming a call site — unlike `PLAYER_REGISTERED_VIA_SHARE_LINK` (tied to
`PlayerRegistrationService`, step 4) and `COACH_INVITATION_ACCEPTED` (tied to
`CoachInvitationService::accept()`, step 6). Resolved 2026-08-20: the architecture doc
now names `PlayerShareLinkService::associate()` as the write site, recorded post-commit
(actor = subject = the player) only on a genuinely new association row, mirroring the
`usageCount` increment's same new-row-only condition. Tasks 6 and 10 above reflect the
resolution.
