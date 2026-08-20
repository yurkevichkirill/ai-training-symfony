# Design: ShareLink Invitations (Epic-01, slice S3)

> Answers *how*. The *what* and *why* live in `specs/sdd-sharelink-invitations-spec.md`
> (AC-1…AC-21); this file does not restate them. Its four resolved open questions
> (Parent/Child deferred; coach account status is invitation-only; coach exclusivity
> evaluates currently-active associations only; no email-verification exception) are
> binding inputs here, not choices reopened below.
> Governed task: TASK-003. Feature slug: `sharelink-invitations`.
> Builds on `specs/auth-foundation-architecture.md` (S1, shipped) and
> `specs/sdd-user-management-architecture.md` (S2, shipped). Written against the repo as
> S2 left it: `User` (scalar `role`/`status`, UUIDv7, common name/phone/photo columns,
> `anonymize()`), the `Profile`/`ProfileTrainer` JOINED hierarchy, `AccountInvitation` +
> `SelectorVerifierTokenFactory`, `AccountEvent`/`AccountEventRecorder`,
> `EmailVerificationTokenService`, `SendEmailMessage` → `SendEmailMessageHandler`, and the
> closed-EntityManager transactional discipline documented in `UserAccountService`,
> `AccountLifecycleService::delete()` and `TrainerOnboardingService::createTrainer()`.

## Approach

Five shaping choices carry the slice.

1. **Two link entities, not one.** A player ShareLink and a coach invitation share a
   sentence in the epic ("ShareLink") and almost nothing else: every column that would be
   nullable in a merged table (`invited_email`, `expires_at`, `accepted_at`) is *mandatory*
   for one type and *meaningless* for the other, and the one column the player link needs
   (`usage_count`) is meaningless for a single-use one. So `PlayerShareLink` and
   `CoachInvitation` are separate tables whose invariants are schema facts rather than
   runtime conditionals: a player link has **no expiry column and no max-uses column at
   all**, so AC-2's "no expiry, no maximum-use count" is unrepresentable rather than
   merely unimplemented — the same move S1 made for "one role" by refusing a `roles json`
   column.

2. **The coach invitation is `AccountInvitation`'s discipline, one target down.** It reuses
   `SelectorVerifierTokenFactory` verbatim (selector indexed, verifier SHA-256 at rest,
   `hash_equals`, `SELECT … FOR UPDATE` by selector) because it *is* a single-use secret
   whose compromise grants a role association. What it cannot reuse is
   `AccountInvitation`'s subject: it is addressed to an **email address that has no `User`
   row yet**, which is precisely why S2's entity could not be relabelled. The player
   ShareLink deliberately takes none of that crypto — see Decisions.

3. **Two association entities, not one polymorphic one**, for the same reason as (1): the
   coach association needs `ended_at` (the resolved exclusivity question defines
   "currently active" against it, and the "ended with Trainer A, joins Trainer B" edge case
   requires ended rows to stay queryable), while the player association must *not* have it
   in S3 — nothing ends one, AC-12 forbids altering existing ones, and its absence is what
   lets `UNIQUE (trainer_id, player_id)` make AC-13's idempotency a database fact instead
   of a check that can race.

4. **Every uniqueness rule in this slice is a database constraint first and a service
   guard second.** `UNIQUE (trainer_id, player_id)` for AC-13; a **partial** unique index
   `(coach_id) WHERE ended_at IS NULL` for AC-16; `UNIQUE (trainer_id)` on
   `player_share_link` for AC-1/AC-4. Each has a service-level pre-check for the clean
   message and a `UniqueConstraintViolationException` catch that converts the losing racer
   into the *same* typed outcome — idempotent success for AC-13, a typed refusal for AC-16
   — following the pattern `AccountLifecycleService::delete()` was just fixed into, and
   obeying its rule: after that violation the EntityManager is closed, so the catch block
   resets the manager or throws, and never touches the closed instance.

5. **Registration composes with S1's verification, it does not bypass it.** Both
   registration paths (player self-registration, coach-from-invitation) call
   `UserAccountService::create()` for the `User` row — inheriting AC-10's unique-email
   mapping for free — then do their second write (profile, association, counter) in a
   *separate* transaction with the compensating-delete cleanup `TrainerOnboardingService`
   now uses, then issue an `EmailVerificationToken` and queue exactly one email. That one
   email *is* AC-9's confirmation email: the resolved open question makes verification
   mandatory, so a second "welcome, go sign in" mail would only invite the user to do
   something S1 will refuse. The trainer association exists immediately — that is what
   US-01.02's "instant access" survives as — but the first *sign-in* still waits on
   verification.

## Components

### Entities and schema

**`App\Entity\PlayerShareLink`** → `player_share_link`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7, generated in the constructor (S1 convention) |
| `trainer_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | AC-1 |
| `code` | `varchar(24)` NOT NULL | the `/join/{code}` segment; 12-char base64url of `random_bytes(9)` (72 bits) |
| `usage_count` | `integer` NOT NULL DEFAULT 0 | AC-6 |
| `created_at` | `timestamptz` NOT NULL | |

Constraints: `UNIQUE (code)` (the lookup index), `UNIQUE (trainer_id)` — **one player
ShareLink per trainer**, which is what makes AC-1's "never an ambiguous or different
trainer" and AC-2's "works indefinitely" simultaneously true, and makes the AC-6 counter
mean something. **No `expires_at`, no `max_uses`, no `consumed_at`** (AC-2, by absence).

**`App\Entity\CoachInvitation`** → `coach_invitation`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `trainer_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | AC-1 |
| `invited_email` | `varchar(180)` NOT NULL | stored through `User::normalizeEmail()` — the single normalization point, reused, not re-implemented |
| `invited_name` | `varchar(160)` NULL | AC-5, optional |
| `message` | `text` NULL | AC-5's personal message, carried into the email |
| `selector` | `varchar(24)` NOT NULL | `SelectorVerifierTokenFactory` |
| `hashed_verifier` | `char(64)` NOT NULL | SHA-256 of the verifier |
| `expires_at` | `timestamptz` NOT NULL | `created_at + P7D` (AC-3 pins this; not a tunable) |
| `accepted_at` | `timestamptz` NULL | the single-use marker (AC-3) and AC-17's "Accepted" |
| `created_at` | `timestamptz` NOT NULL | |

Constraints: `UNIQUE (selector)`; `CHECK (invited_email = lower(invited_email))`
(hand-written in the migration, exactly as S1 does for `app_user.email`, so an
unnormalized value is unstorable). Indexes: `(trainer_id, created_at)` for AC-17's list,
`(invited_email, accepted_at)`. Deliberately **no** unique constraint on
`(trainer_id, invited_email)`: AC-18 requires re-inviting the same person to be legal.

`CoachInvitation::status(\DateTimeImmutable $now): CoachInvitationStatus` **derives**
AC-17's Pending / Accepted / Expired from `accepted_at` and `expires_at`. No stored status
column — see Decisions.

**`App\Entity\TrainerPlayerAssociation`** → `trainer_player_association`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `trainer_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | |
| `player_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | |
| `share_link_id` | `uuid` NULL FK `player_share_link` ON DELETE SET NULL | AC-6's "attributable to the specific link"; nullable because Epic-08's camp conversion and any future admin-created association have no link |
| `created_at` | `timestamptz` NOT NULL | epic §8's "when they connected" |

Constraint: `UNIQUE (trainer_id, player_id)` — AC-13's idempotency, and AC-12's "never
duplicated", as one index. Index `(player_id, created_at)` (a player's trainers) and
`(trainer_id, created_at)` (a trainer's roster, AC-8).

**`App\Entity\TrainerCoachAssociation`** → `trainer_coach_association`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `trainer_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | |
| `coach_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | |
| `invitation_id` | `uuid` NULL FK `coach_invitation` ON DELETE SET NULL | which invitation produced it |
| `created_at` | `timestamptz` NOT NULL | |
| `ended_at` | `timestamptz` NULL | null ⇔ currently active |

Constraint: **partial unique index**
`CREATE UNIQUE INDEX uniq_trainer_coach_active_coach ON trainer_coach_association (coach_id) WHERE ended_at IS NULL`
— AC-16's exclusivity rule, stated once, in the only place that cannot be raced past. Its
predicate is exactly the resolved open question's wording: an ended row is invisible to the
constraint, so the "ended with Trainer A, accepts Trainer B" edge case succeeds *because*
of the index, not despite it. Indexes `(trainer_id, ended_at)` (AC-15/AC-17's Coaches
list) and `(coach_id, ended_at)`.

**No S3 code path writes `ended_at`.** The column exists because AC-16's rule is defined
against it and the edge case requires ended rows to remain queryable and auditable; the
action that sets it belongs to the later roster-management slice (see Risks).

**`App\Entity\ProfilePlayer`** → `profile_player` (`id` FK `profile.id`), the second
concrete subtype of S1's frozen hierarchy, added exactly as S2 said the remaining subtypes
would be — "additive migrations for S3/S4/S5 to write when they have real columns":
`player_name varchar(160)` NOT NULL, `declared_age smallint` NOT NULL,
`gender varchar(32)` NOT NULL (backed by a new `App\Enum\PlayerGender`, with a
hand-written `CHECK (gender IN (…))` mirroring `app_user.role`'s). `Profile`'s
`#[ORM\DiscriminatorMap]` gains `'PLAYER' => ProfilePlayer::class` — a PHP-only change,
since `profile.type` is a plain `varchar(32)` with no CHECK constraint. **No
`profile_coach` table this slice**: the spec's Out of scope keeps coach profile fields
(bio, credentials, certifications) for US-01.11, so it would be an empty table.

**`App\Enum\AccountEventType`** gains `PLAYER_REGISTERED_VIA_SHARE_LINK`,
`PLAYER_TRAINER_ASSOCIATED`, `COACH_INVITATION_ACCEPTED`. `account_event.type` is a plain
`varchar(64)`, so no migration is needed for these.

**Migration.** One migration, `Version…ShareLinkInvitations`: create `player_share_link`,
`coach_invitation`, `trainer_player_association`, `trainer_coach_association`,
`profile_player`; then the three hand-written SQL lines DBAL does not diff (the
`invited_email` CHECK, the `gender` CHECK, the partial unique index). Down-migration drops
in reverse order. No data backfill — every table is new and empty.

### Controllers → services

| Route | Controller | Delegates to |
|---|---|---|
| `GET /join/{code}` (`app_share_link_follow`, public) | `PlayerShareLinkController::follow` | `PlayerShareLinkResolver::resolve`, then `PlayerShareLinkService::associate` (signed-in) or a redirect to the registration form (anonymous) |
| `GET\|POST /join/{code}/register` (`app_share_link_register`, public) | `PlayerShareLinkController::register` | `PlayerRegistrationService::registerViaShareLink` |
| `GET\|POST /coach-invitation/{token}` (`app_coach_invitation`, public) | `CoachInvitationController::accept` | `CoachInvitationService::resolve`, then `CoachInvitationService::accept` (signed-in) or `CoachRegistrationService::registerAndAccept` (anonymous) |
| `GET /trainer/share-link` (`app_trainer_share_link`) | `Trainer\ShareLinkController::show` | `PlayerShareLinkService::getOrCreateFor` (AC-4) |
| `GET /trainer/players` (`app_trainer_players`) | `Trainer\PlayerRosterController::index` | `TrainerPlayerAssociationRepository::findRosterFor` (AC-8) |
| `GET /trainer/coaches` (`app_trainer_coaches`) | `Trainer\CoachController::index` | `TrainerCoachAssociationRepository::findActiveFor` + `CoachInvitationRepository::findForTrainer` (AC-15, AC-17, AC-18's re-invite affordance) |
| `POST /trainer/coaches/invite` (`app_trainer_coach_invite`) | `Trainer\CoachController::invite` | `CoachInvitationService::invite` (AC-5, AC-19) |

`security.yaml` gains two `PUBLIC_ACCESS` lines, **before** the `^/` catch-all:
`^/join` and `^/coach-invitation`. Every `Trainer\*Controller` carries
`#[IsGranted('ROLE_TRAINER')]` on the class (S1's belt-and-braces rule). S1's router-sweep
test allow-list is extended with the two new public prefixes — that test, not the config,
is what keeps AC-18 true across this slice.

### Services

**`PlayerShareLinkService`**
- `getOrCreateFor(User $trainer): PlayerShareLink` — AC-4. `UNIQUE (trainer_id)` makes a
  concurrent double-generate resolve to one row: on
  `UniqueConstraintViolationException` the service resets the manager (never reusing the
  closed one) and re-reads the winner's row.
- `associate(User $player, PlayerShareLink $link): TrainerPlayerAssociation` — AC-11,
  AC-12, AC-13, AC-20. Guards, in order: `$player->getRole() === UserRole::PLAYER` else
  `RoleNotEligibleForShareLinkException` (AC-20 and the "signed-in Coach follows a player
  link" edge case); `$player->isActive()` else `AccountNotEligibleException` (the
  DEACTIVATED/DELETED edge case — belt-and-braces, since `EquatableInterface` already ends
  such a session at its next request); the link's trainer must be `ACTIVE` else
  `ShareLinkUnavailableException`. Then one transaction: a pre-check via
  `existsFor(trainer, player)` returns the existing row untouched (AC-13), and the
  `UNIQUE (trainer_id, player_id)` violation is caught, the manager reset, and the existing
  row re-read — so the loser of a double-submit race gets the same idempotent success, not
  a 500. On a genuinely new row: insert it **and** `incrementUsage($link)` in the same
  transaction, then (post-commit) `AccountEventRecorder::record(PLAYER_TRAINER_ASSOCIATED)`
  with actor = subject = the player — a self-action, the same shape as
  `PLAYER_REGISTERED_VIA_SHARE_LINK`. An idempotent no-op does **not** increment the
  counter and does **not** record a second event: both count uses that created
  something, not repeat visits.

**`PlayerShareLinkResolver`** — `resolve(string $code): PlayerShareLink`, one repository
query joining the trainer and filtering `trainer.status = ACTIVE`. An unknown code and a
deactivated/deleted trainer both raise `ShareLinkUnavailableException` and render the same
"this invitation is no longer available" page — which is both what the edge case asks for
and non-enumerating.

**`PlayerRegistrationService::registerViaShareLink(PlayerRegistrationRequest, PlayerShareLink): User`**
— AC-7…AC-10, in `TrainerOnboardingService`'s exact two-phase shape:
1. `UserAccountService::create($email, $plainPassword, UserRole::PLAYER)` — commits in its
   own transaction. `EmailAlreadyInUseException` propagates unchanged to a field-level form
   error (AC-10); per that service's contract its EntityManager is not touched again.
2. Set `first_name`/`last_name`/`phone` on the returned user.
3. A **second** transaction on a manager taken fresh from the registry: persist
   `ProfilePlayer`, persist `TrainerPlayerAssociation`, `incrementUsage`, flush the user's
   new common fields. `catch (\Throwable)` → `resetManager()`, then
   `DELETE FROM app_user WHERE id = :id` through the fresh connection, then rethrow — the
   compensating cleanup, because step 1 already committed and cannot be rolled back.
4. After commit: `EmailVerificationTokenService::issue($user)`, then dispatch one
   `SendEmailMessage(TEMPLATE_PLAYER_WELCOME, ['token' => …, 'trainerName' => …])`, then
   `AccountEventRecorder::record(PLAYER_REGISTERED_VIA_SHARE_LINK)` (actor = subject = the
   new player; a self-action, the same shape S2 uses for a profile edit).

The controller then redirects to a "check your email" page, not to a trainer context: the
association exists, but S1 will refuse the first sign-in until the address is verified.

**`CoachInvitationService`**
- `invite(CoachInvitationRequest, User $trainer): CoachInvitation` — AC-5, AC-19. Generate
  via `SelectorVerifierTokenFactory`, `expires_at = now + P7D`, persist, then (post-commit)
  dispatch `SendEmailMessage(TEMPLATE_COACH_INVITATION, ['token', 'trainerName', 'message'])`.
  **No `AccountEvent` is recorded here** — `account_event.subject_user_id` is NOT NULL and
  no `User` exists for the invited address yet; see Decisions.
- `resolve(string $token): CoachInvitation` — split, `hash_equals`, and typed refusals that
  let the page distinguish AC-18's two cases: `InvalidCoachInvitationException`,
  `CoachInvitationAlreadyAcceptedException`, `CoachInvitationExpiredException`, plus
  `ShareLinkUnavailableException` when the inviting trainer is no longer `ACTIVE`.
- `accept(string $token, User $coach): TrainerCoachAssociation` — AC-15, AC-16, AC-21. One
  transaction on a fresh manager, mirroring `AccountInvitationService::consume()` including
  its identity-map warm-up:
  1. `SELECT … FOR UPDATE` by selector (two devices, same link → one winner: the edge case).
  2. `hash_equals` the verifier; refuse if expired.
  3. AC-21: refuse unless `$coach->getRole() === UserRole::COACH` **and**
     `$coach->getEmail() === $invitation->getInvitedEmail()` (both already normalized) —
     `CoachInvitationEmailMismatchException`. This is what refuses the "signs in as a
     different email" and "signed-in Player follows a coach link" edge cases.
  4. If `accepted_at` is already set: if an active association already exists for *this*
     `(trainer, coach)` pair, return it as success (the "coach re-follows their own
     accepted link" edge case — idempotent, no duplicate, no error); otherwise
     `CoachInvitationAlreadyAcceptedException` (AC-18's "already used").
  5. AC-16: `findActiveForCoach($coach)` — if it exists and its trainer is a *different*
     trainer, `CoachAlreadyActiveElsewhereException`; if it is the *same* trainer, mark the
     invitation accepted and return that association (idempotent). The partial unique index
     is the authority under concurrency: a caught `UniqueConstraintViolationException`
     becomes the same `CoachAlreadyActiveElsewhereException`, thrown from a catch block that
     does not touch the now-closed manager.
  6. `$invitation->accept($now)`, persist the association, then (post-commit)
     `AccountEventRecorder::record(COACH_INVITATION_ACCEPTED)` with context
     `{trainerId, invitationId}`.

**`CoachRegistrationService::registerAndAccept(CoachRegistrationRequest, CoachInvitation): User`**
— AC-14, AC-15 for a coach with no account. Same two-phase pattern as the player path:
`UserAccountService::create($invitation->getInvitedEmail(), …, UserRole::COACH)` — the
email comes **from the invitation, never from the request** — then `accept()`'s transaction,
then the compensating `DELETE FROM app_user` on failure, then
`EmailVerificationTokenService::issue()` + `SendEmailMessage(TEMPLATE_COACH_WELCOME)`. An
`EmailAlreadyInUseException` here renders "you already have an account — sign in and open
this link again", after which AC-21 guarantees the signed-in path only completes for the
matching address.

**`ShareLinkCodeGenerator`** — `generate(): string`, 12-char base64url of
`random_bytes(9)`. Ten lines, deliberately *not* a method on
`SelectorVerifierTokenFactory` (see Decisions).

### Forms, validation, authorization

- `CoachInvitationFormType` over a `CoachInvitationRequest` DTO: `email`
  (`NotBlank` + `Email` — AC-19), `name` (optional, `Length(max: 160)`), `message`
  (optional, `Length(max: 2000)`).
- `PlayerShareLinkRegistrationFormType` over `PlayerRegistrationRequest`: `firstName`,
  `lastName`, `email`, `plainPassword` (S1's constraint set reused from
  `ChangePasswordFormType` — `RepeatedType`, `Length(min: 12, COUNT_BYTES)`,
  `NotCompromisedPassword`, `NotBlocklistedPassword`), `phone` (S2's `Assert\Regex`),
  `playerName`, `playerAge` (`Range(min: 1, max: 120)` — the epic's 1–18 rule belongs to
  the out-of-scope Child model), `playerGender` (`Choice` over `PlayerGender`). AC-7.
- `CoachRegistrationFormType` over `CoachRegistrationRequest`: `firstName`, `lastName`,
  `plainPassword`, `phone`. **`email` is not a field at all** — it is read from the
  invitation. That is the same "never take the target from the request" move that made S2's
  AC-13 true by construction, and it is what makes AC-21 structural on the registration
  branch rather than a check that could be missed.
- `App\Security\ShareLinkVoter` — attributes `FOLLOW_PLAYER_SHARE_LINK` (subject
  `PlayerShareLink`) and `ACCEPT_COACH_INVITATION` (subject `CoachInvitation`), voting on
  `User::role`, `User::status`, and email equality with `invited_email`. It reads no
  `Profile` — S1's frozen invariant holds unchanged. Controllers call
  `denyAccessUnlessGranted()` so an ineligible visitor gets a refusal before any service
  work; the services re-check regardless (see Decisions).
- All templates extend S1's `templates/form/theme.html.twig`, so the accessibility
  guarantees of AC-22/AC-23 in S1 are inherited, not re-implemented.

### Rate limiting

One new limiter in `config/packages/rate_limiter.yaml`:
`share_link_registration_source` — `sliding_window`, 20 / hour, keyed on the client IP
truncated by S1's `IpTruncator`, cache pool `cache.rate_limiter`. Consumed by the
registration `POST` actions only. This is what makes the spec's "URL scraped far beyond its
audience" row true: S1 rate-limits sign-in and reset, but had no registration endpoint to
limit, so "no special handling beyond what S1 already enforces platform-wide" requires
adding the equivalent here. Per S1's rule only a *source* limiter may surface a 429; there
is no account limiter on registration (there is no account yet to enumerate).

### Mail

Three new templates, all through S1's existing `SendEmailMessage` →
`SendEmailMessageHandler` → `async` Doctrine transport, dispatched **after** the
surrounding transaction commits:
`emails/player_welcome.html.twig` (carries the verification link and names the trainer —
AC-9), `emails/coach_invitation.html.twig` (the invitation link plus the trainer's optional
message — AC-5), `emails/coach_welcome.html.twig` (verification link). Three new
`SendEmailMessage::TEMPLATE_*` constants and three new `buildContext()` branches. No new
mailer or Messenger configuration. `SendEmailMessage`'s `array<string, scalar>` context is
unchanged — every value here is a scalar, so the "cannot smuggle an entity or a closure
across the transport" property holds.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| Public link entry, branch on signed-in vs anonymous | Controller | `PlayerShareLinkController`, `CoachInvitationController` |
| Trainer-facing link, roster, coaches | Controller | `Trainer\ShareLinkController`, `Trainer\PlayerRosterController`, `Trainer\CoachController` |
| Link issue + player association workflow | Service | `PlayerShareLinkService` |
| Code → link + trainer-availability resolution | Service | `PlayerShareLinkResolver` |
| New-player registration workflow | Service | `PlayerRegistrationService` |
| Coach invite / resolve / accept workflow | Service | `CoachInvitationService` |
| New-coach registration workflow | Service | `CoachRegistrationService` |
| Opaque public code generation | Service | `ShareLinkCodeGenerator` |
| Paired-secret crypto | Service | `SelectorVerifierTokenFactory` (S2, reused unchanged) |
| Account creation, unique-email mapping | Service | `UserAccountService` (S1, reused unchanged) |
| Verification token issue | Service | `EmailVerificationTokenService` (S1, reused unchanged) |
| Audit write | Service | `AccountEventRecorder` (S2, reused unchanged) |
| Role/email eligibility at the HTTP edge | Security | `ShareLinkVoter` |
| Queries and persistence | Repository | `PlayerShareLinkRepository`, `CoachInvitationRepository`, `TrainerPlayerAssociationRepository`, `TrainerCoachAssociationRepository`, `ProfileRepository` (extended) |

Transaction boundary, controller/service/repository responsibilities: unchanged from S1's
rules (one transaction per service method, controllers never `flush()`, services never
return a `Response`, repositories never authorize).

### Tests this slice must produce

Functional: follow a player link anonymous → register → exactly one `User`, one
`ProfilePlayer`, one association, `usage_count == 1`, one queued mail, sign-in still
refused until verified; duplicate email → field error, no orphan `User` row; follow signed
in as a Player → instant association, no form; follow a *second* trainer's link → two
association rows, first untouched, one `User`; follow the same link twice → still one row,
`usage_count` unchanged; follow as Coach / Trainer / Super Admin → refused (AC-20); follow
while DEACTIVATED and while DELETED → refused; follow a link whose trainer is
deactivated/deleted → "no longer available"; invite a coach with no email → validation
error; invite → email queued with the personal message; accept as a new coach → account +
association + status Accepted; accept as a signed-in coach with a different email → refused
(AC-21); accept as a signed-in Player → refused; accept an already-accepted link → refused
as *used*, and distinguishably from an expired one; re-follow your own accepted link →
idempotent success; accept while actively associated with another trainer → refused
(AC-16); accept after your prior association was ended (fixture sets `ended_at`) →
succeeds; trainer's Coaches list shows Pending / Accepted / Expired; the router sweep
extended with the two new public prefixes; CSRF rejection on both registration forms and
the invite form.
Repository integration, against the real database: the `UNIQUE (trainer_id, player_id)`
violation caught as idempotent success (two concurrent inserts); the partial unique index
rejecting a second active association for one coach while permitting one after `ended_at`
is set; `UNIQUE (trainer_id)` on a concurrent double-generate; `incrementUsage` under
concurrent registrations losing no counts; the `invited_email` and `gender` CHECK
constraints refusing bad values.
Unit: `CoachInvitation::status()` across the three states and the boundary second;
`ShareLinkCodeGenerator` alphabet and length; the `ShareLinkVoter` truth table.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| PostgreSQL partial unique index (`… WHERE ended_at IS NULL`) | built-in | Over an application-level "at most one active" check or a `is_active boolean` + full unique index: only the index makes AC-16 unraceable, and the partial predicate is the resolved open question's wording *verbatim* — an ended row is invisible to the constraint, so the "ended with A, joins B" edge case works because of the constraint rather than around it. A boolean would need a trigger to stay consistent with `ended_at`. |
| No new Composer package | — | Every mechanism this slice needs already exists in the repo: `SelectorVerifierTokenFactory` for the single-use secret, `UserAccountService` for account creation, `EmailVerificationTokenService` for verification, `SendEmailMessage` for queued mail, `IpTruncator` + `symfony/rate-limiter` for the new limiter. |

Not added: a CAPTCHA/anti-bot bundle (the spec's scrape edge case explicitly settles for
S1's platform-wide protections); `symfony/lock` (every race in this slice is settled by a
unique index, not by application locking); any analytics package (AC-6 keeps the raw count;
Epic-06 owns the reporting).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| **Q1a.** Player ShareLink entity | Own table `player_share_link`, one row per trainer (`UNIQUE (trainer_id)`), with a `usage_count` and **no** expiry/max-uses/consumed columns | Reusing/extending S2's `AccountInvitation`; one merged `share_link` table with a `type` discriminator and nullable expiry/target-email/consumed columns; JOINED inheritance under a shared `ShareLink` base | `AccountInvitation` is single-use and carries a `user_id` from the moment it is issued — neither is true here. A merged table makes every distinguishing column nullable and turns AC-2 ("no expiry, no maximum-use count") into a runtime conditional; separate tables make it an absence, which is unrepresentable. JOINED inheritance would buy "all links of this trainer as one query", which nothing in this slice or the named later slices asks for. |
| **Q1a′.** Player link code storage | Plaintext `code`, unique-indexed, looked up directly; unguessable (72 bits) but not hashed | Selector/verifier with a hashed verifier, as `AccountInvitation` does | The trainer must be able to *re-display and broadcast* this code indefinitely (that is the whole point of a static link), so hashing it at rest would hide it from its own owner. Verifier hashing exists so that a database read cannot yield a usable **single-use** secret; a code designed to be posted publicly has nothing to protect at rest. The security property that matters here is unguessability, which `random_bytes(9)` gives. |
| **Q1a″.** Code generation | New 10-line `ShareLinkCodeGenerator` | A `generateCode()` method added to `SelectorVerifierTokenFactory` | That class's documented purpose is paired secrets whose two halves must stay in lock-step (`SELECTOR_LENGTH` ↔ `SELECTOR_BYTES`); adding an unrelated single-value generator to a proven, security-reviewed file invites a future edit that breaks the pairing invariant. |
| **Q1a‴.** One link per trainer | `UNIQUE (trainer_id)`, get-or-create; "generate" is idempotent | Many links per trainer (campaign-tagged); rotate the code on each generate | AC-2 says an issued link works indefinitely, so rotation would break links already shared — the two cannot both hold. With many links, nothing in the spec says which one is current, and AC-1's "never ambiguous" gets harder rather than easier. Per-campaign links are Epic-06 analytics territory; AC-6 needs only attributability, which the association's `share_link_id` already gives. |
| **Q1a⁗.** Usage tracking | Both: `share_link_id` on the association (attribution) **and** a monotonic `usage_count` on the link (tally), written in one transaction | Counter only; derived `COUNT(*)` over associations only | AC-6 asks for two different things. "Attributable to the specific link" is the FK; "the number of times a given link has been used" is a lifetime tally that must **not** decrease when an association later ends — which a derived `COUNT(*)` would, silently turning "times used" into "currently connected". A counter alone loses the per-registration attribution AC-6 also requires. |
| **Q1b.** Coach invitation entity | Own table `coach_invitation`, targeting an **email address**, reusing `SelectorVerifierTokenFactory` verbatim | Extending `AccountInvitation` with a nullable `user_id` + an `invited_email` | `AccountInvitation.user_id` is NOT NULL and its whole flow ("set the password of an account that already exists") assumes it; making it nullable would retrofit a second, differently-shaped lifecycle onto a table S2 already shipped and tested. The crypto — the part worth reusing — is already extracted into a shared factory, so nothing is duplicated by having a second table. |
| **Q1b′.** Invitation status (AC-17) | Derived from `accepted_at` / `expires_at` by `CoachInvitation::status(now)` | A stored `status varchar` column | Expired is purely a function of the clock. A stored column needs a scheduled sweep to stay truthful and creates two sources of truth that can disagree — the failure mode being a trainer shown "Pending" for a link that stopped working days ago. |
| **Q2.** Association shape | **Two** entities: `trainer_player_association` and `trainer_coach_association` | One polymorphic `trainer_user_association` with a `kind` discriminator | The two need different columns and, decisively, different *constraints*: the player side needs a full `UNIQUE (trainer_id, player_id)` (AC-13) and the coach side needs a partial unique index on `coach_id` alone (AC-16). A single table can carry at most one of those cleanly, and a polymorphic `share_link_id` would have to point at two different link tables. |
| **Q2′.** "Currently active", coach side | `ended_at timestamptz NULL`, null ⇔ active, plus the partial unique index | Deleting the row when an association ends; an `is_active boolean` | The resolved exclusivity question requires a historical, ended association to stay queryable and auditable, which deletion destroys. A boolean would duplicate the timestamp's information and need a trigger to stay consistent with it; the timestamp answers "since when" for free. |
| **Q2″.** `ended_at` on the *player* association | Deliberately absent in S3 | Adding it now for symmetry, with a partial unique index instead of a full one | No S3 path ends a player association and AC-12 forbids altering existing ones, so the column would have no producer — and its presence would force `UNIQUE (trainer_id, player_id)` down to a partial index, weakening the one constraint that makes AC-13 a database fact. The roster-management slice that ships "remove a player" writes that migration when it has a caller (see Risks). |
| **Q3.** Idempotency (AC-13 + the coach re-follow edge case) | DB constraint first: `UNIQUE (trainer_id, player_id)`, with a service pre-check for the message and the `UniqueConstraintViolationException` converted to *idempotent success* (manager reset, existing row re-read). Coach re-follow is settled by `accepted_at` + the existing active association, not by a pair constraint | Check-then-insert only; a `SELECT … FOR UPDATE` on the association pair | Check-then-insert loses a double-submitted form — two tabs, one impatient user — and would surface as a 500, which is exactly the S2 delete-guard bug that was just fixed. `FOR UPDATE` has nothing to lock before the first row exists. |
| **Q4.** Role/email integrity (AC-20, AC-21) | Both: `ShareLinkVoter` at the HTTP edge **and** a hard guard inside every service method, with the coach registration form having no `email` field at all | Voter only; service guard only | A voter alone cannot cover a future console/import caller and cannot express AC-16's DB-level race. A service guard alone gives up the clean 403 and pushes role logic into templates. And the strongest form of AC-21 is not a check: taking the email from the invitation rather than the request makes a mismatch unrepresentable on the registration branch — the same move S2 used for AC-13. |
| **Q5.** Registration composition | Two transactions — `UserAccountService::create()` commits the `User`, then a second transaction on a **fresh** manager writes profile + association + counter, with a compensating `DELETE FROM app_user` on failure | One transaction spanning both; skipping the cleanup and leaving the orphan | `UserAccountService::create()` commits in its own `wrapInTransaction()` and closes its EntityManager on a unique violation — that is its documented contract, and reusing it (for AC-10's mapping) means accepting it. So the second failure cannot roll the first back and must be compensated instead, exactly as `TrainerOnboardingService::createTrainer()` now does. Leaving the orphan produces an unreachable account with an unguessable password and no association. |
| **Q5′.** AC-9's confirmation email | One email, which *is* the verification email (a `player_welcome` template carrying the verification link and naming the trainer) | A welcome email plus a separate verification email | The resolved open question makes verification mandatory for ShareLink registrants, so a "welcome, you're in" mail with no link would invite the user to attempt a sign-in S1 refuses. One mail, one action, one link. |
| **Q6.** Concurrency on the coach accept path | `SELECT … FOR UPDATE` by selector for the single-use half; the partial unique index for the exclusivity half; both losers converted to typed refusals from a catch block that never touches the closed manager | Optimistic check-then-write; an application lock over the coach | The row lock is what makes "exactly once" survive two devices (S1's precedent for verification tokens); the index is the only thing that can serialize two *different* invitations racing to make the same coach active, because there is no shared row to lock. The closed-EntityManager rule is not optional — ignoring it is what turned a typed refusal into a 500 in the bug S2 just fixed. |
| Audit event for "invitation sent" | None; the `coach_invitation` row is the record | An `AccountEvent` of type `COACH_INVITED`; widening `account_event.subject_user_id` to nullable | There is no `User` to be the subject yet, and `subject_user_id` is NOT NULL with `ON DELETE RESTRICT` precisely so an audit row can never lose its subject. The invitation row already carries trainer, address and timestamp; loosening an S2-shipped audit table's core guarantee to duplicate that would be a bad trade. Acceptance *is* audited (`COACH_INVITATION_ACCEPTED`), when a subject exists. |
| `ProfilePlayer` for the AC-7 player fields | New `profile_player` subtype with `player_name`, `declared_age`, `gender` | Columns on `User`; deferring the fields entirely with the rest of the Parent/Child work | S2's rule is that `User` carries only non-capability, non-role-specific display data — age and gender are role-specific player data, so they belong in the hierarchy S1 froze for exactly this. Deferring them is not available: AC-7 names them as required form fields, so they need a home now. |
| Age representation | `declared_age smallint` as submitted, dated by `Profile::createdAt` | A synthesized `date_of_birth` derived from the declared age | The form asks for an age (AC-7), so a date of birth would be invented — wrong by up to a year — and later age-group or eligibility logic would trust it. Storing what was actually said, with the date it was said, keeps the staleness visible and leaves a clean migration when US-01.03's form gains a real birthday field. |
| No `profile_coach` table | Not created | An empty `profile_coach` for symmetry | The spec's Out of scope keeps every coach profile field for US-01.11, and S2 explicitly said the remaining subtypes arrive "when they have real columns". An empty table is a schema commitment made before the shape is known. |

## Risks

- **Partial unique indexes: verified, not a risk** — with one syntax correction found
  during implementation. The initial spike (a single `doctrine:schema:update --dump-sql`
  run against a fresh, not-yet-existing table) confirmed the predicate generates
  correctly, but did not catch a second-run stability issue: PostgreSQL's own
  introspection (`pg_get_expr`) normalizes a stored predicate to its parenthesized form
  (`(ended_at IS NULL)`), while DBAL's `Index::samePartialIndex()` does a literal string
  comparison against the ORM-declared `where` option. Declaring the option as
  `'ended_at IS NULL'` (no parens) therefore diffs forever once the index exists — every
  `schema:update`/`schema:validate` run sees a mismatch and wants to drop and recreate it.
  **Fix, applied in `TrainerCoachAssociation`:** declare the option pre-parenthesized —
  `options: ['where' => '(ended_at IS NULL)']` — which matches Postgres's canonical form
  and makes `schema:update --dump-sql` report "Nothing to update" on a second run, exactly
  as it did for the original throwaway spike table below. Spiked against the installed
  stack (doctrine/orm 3.6.8, doctrine/dbal 4.4.4, PostgreSQL 18) with a throwaway entity
  carrying `#[ORM\UniqueConstraint(columns: ['coach_id'], options: ['where' => 'ended_at IS NULL'])]`:
  `doctrine:schema:update --dump-sql` emitted exactly
  `CREATE UNIQUE INDEX uniq_scratch_active_coach ON scratch_partial_index_spike (coach_id) WHERE ended_at IS NULL;`,
  confirming `PostgreSQLPlatform::supportsPartialIndexes()` returns `true` and
  `AbstractPlatform` appends the `WHERE` clause from the index's `where` option. DBAL's
  `Schema\Index::isFulfilledBy()` explicitly compares the `where` option for equality when
  diffing two indexes (`src/Schema/Index.php:718-725`), so a matching predicate does not
  re-diff on a second run — no perpetual-diff risk. Spike entity and its table were removed
  after the check; `doctrine:schema:validate` confirmed the schema is clean. No fallback
  needed.
- **Nothing in S3 ends a coach association.** A coach who stops working with a trainer is
  permanently blocked from accepting anyone else's invitation, because the only way out is
  an `ended_at` write no shipped code path performs. Support will be doing this in SQL until
  the roster-management slice ships "end this coach's association". Flag it in that slice's
  inputs, and expect it to be reported as a bug before then.
- **`ProfilePlayer` is the shape most likely to change when Parent/Child lands.**
  `player_name` and `declared_age` describe "the one player this account represents", which
  is a coherent statement only while a parent-with-children account does not exist. When
  `ProfileChild` arrives, this data either moves or gains a relationship. Mitigation: keep
  these three columns out of every query except the roster display and the registration
  write, so the later migration touches two call sites rather than twenty.
- **`UNIQUE (trainer_id, player_id)` blocks a future leave-and-rejoin.** It is the right
  constraint for S3 (nothing ends a player association) and the wrong one the day a player
  can leave a trainer. That is a partial-index migration plus an `ended_at` column, not a
  redesign — but it is a migration on a table that will have real rows by then.
- **The `/join/{code}` URL is a permanent public secret with no revoke path.** By AC-2 it
  never expires, and this slice ships no way to retire one, so a link abused at scale can
  only be stopped by deactivating the trainer. Deliberate (the spec's scrape edge case
  settles for platform-wide protections), and cheap to fix later: an additive nullable
  `revoked_at` column plus one resolver check. Revisit if abuse is observed.
- **The new source limiter is per-node, like every other one.** S1's filesystem
  `cache.rate_limiter` pool means 20 registrations per hour *per app container*. Same
  mitigation as S1's: move the pool to Redis before the second node exists, not after.
- **The compensating delete can itself fail.** If the database is unreachable at exactly
  that moment, an orphaned `User` row (role PLAYER or COACH, no profile, no association,
  unverified) survives — the residual risk `TrainerOnboardingService` already carries, now
  on a *public* endpoint, so it is reachable by anyone rather than only by a Super Admin.
  Mitigation: log the failed compensation at `critical`, and consider an `app:` sweep
  command for unverified, profile-less, association-less accounts older than an hour.
- **`usage_count` is a denormalization and can drift.** It is safe only while every writer
  of a `TrainerPlayerAssociation` increments it in the same transaction — true for the one
  service method that exists today, not enforceable by the schema. Mitigation: a
  repository-integration test asserting `usage_count` equals the association count for every
  S3 path, and a note in the Epic-08 camp-conversion slice's inputs that it must either
  increment or deliberately pass no link.
- **Voter and service guard can diverge.** Two places encode AC-20/AC-21, which is the
  point (defence in depth) and also a maintenance hazard: a role added to one and not the
  other fails open at the HTTP edge or produces a confusing 500 instead of a 403. Mitigation:
  the voter truth table and the service guards are driven by the same functional test matrix,
  parameterized over all four roles.
- **Three new email templates against an unanswered Q-01.04.** The transactional email list
  is still client-owned. Nothing here depends on the answer — each template is a file — but
  if the client supplies their own copy, expect all three to be rewritten.

## Post-implementation hardening decisions (2026-08-20)

The dual code-review/security-review pass (Task 30) found two Major correctness bugs and
four security Mediums on the anonymous-writable surface this slice introduces for the
first time. The two Majors are unconditional fixes. The three Mediums below trace to this
document's own AC-10/AC-11 wording and required an explicit decision, made by the
requester on 2026-08-20:

- **AC-10 amended: registration duplicate-email response is enumeration-resistant.**
  `PlayerRegistrationService::registerViaShareLink()`'s `EmailAlreadyInUseException`
  branch no longer renders a field-level "already exists" error naming the address (that
  message, on a public, permanently-broadcastable endpoint, was a definitive
  email-enumeration oracle — S1's `AccountStatusChecker`/`UniformAuthenticationFailureHandler`
  deliberately avoid the same class of leak elsewhere). On a duplicate, the controller now
  renders the same "check your email" success response as a genuine new registration, and
  the *existing* account is emailed a "someone attempted to register with your address"
  notice instead. The prober learns nothing; the address owner does.
- **AC-11's no-confirmation design gets a revoke path, shipped in this slice rather than
  deferred.** The player roster permanently displays the joined player's name and email
  with no way to leave, so a one-click forced navigation to `/join/{code}` (AC-11 forbids a
  confirmation step, so no CSRF token can gate it) was a permanent, unconsented PII
  disclosure, not the reversible convenience the original design assumed. Fix: add
  `TrainerPlayerAssociation.ended_at` (nullable, mirroring `TrainerCoachAssociation`'s
  already-established shape) and narrow `UNIQUE (trainer_id, player_id)` to the same kind
  of partial unique index the coach side already uses — `WHERE ended_at IS NULL` — so a
  player can leave and later rejoin the same trainer without resurrecting a stale row. A
  "leave this trainer" action for the player, plus a "you've been connected with trainer
  X" notification email on every genuinely new association, close the loop.
- **AC-19's pre-hijack/squatting risk gets its already-scoped mitigation now, not deferred
  to a later slice.** An anonymous registration commits a real `app_user` row with an
  attacker-chosen password at an attacker-chosen email before verification — this Risks
  section already named the sweep-command mitigation; it now ships with S3 rather than
  waiting for a report. A full redesign (holding the credential until verification) is
  deliberately deferred — out of scope for this hardening pass — since it changes the
  registration flow's shape, not just adds a safety net.

Also fixed, no product decision required: `usage_count`'s lost-update race under real
concurrency (an atomic `SET usage_count = usage_count + 1` replaces the hydrate-then-write
`++`); the three empty-message exceptions (`ShareLinkUnavailableException`,
`RoleNotEligibleForShareLinkException`, `AccountNotEligibleException`) now carry the
default user-facing text the edge-case table always required; a missing
`coach_invitation_account`/`coach_invitation_source` limiter pair on
`Trainer\CoachController::invite()` (previously unthrottled, unlike every other
mail-sending endpoint in this project); `CoachInvitationService::accept()` gained the same
`isActive()` trainer guard `PlayerShareLinkService::associate()` already had, for
voter/service-guard symmetry; `IpTruncator`'s IPv6 prefix widened from `/64` to `/48` to
match realistic allocation sizes; dead branches in `CoachRegistrationService` (a
same-trainer-reuse check that can never fire, since the coach account was just created)
were collapsed; a stale test name/docblock in `CoachInvitationAcceptTest.php` asserting a
bug already fixed earlier in this slice was corrected; both compensating-delete call sites
now log at `critical` on failure rather than losing the orphan silently.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `PlayerShareLink.trainer_id` NOT NULL + `UNIQUE (trainer_id)` + `UNIQUE (code)`; `PlayerShareLinkResolver` | AC-1 |
| `player_share_link` has no expiry and no max-uses column (unrepresentable) | AC-2 |
| `CoachInvitation.accepted_at` + `expires_at` (`P7D`); `FOR UPDATE` by selector | AC-3 |
| `Trainer\ShareLinkController::show` → `PlayerShareLinkService::getOrCreateFor` | AC-4 |
| `CoachInvitationService::invite`, `CoachInvitationFormType`, `emails/coach_invitation.html.twig` | AC-5, AC-19 |
| `TrainerPlayerAssociation.share_link_id` + `PlayerShareLink.usage_count` incremented in-transaction | AC-6 |
| `PlayerShareLinkRegistrationFormType` + `ProfilePlayer` | AC-7 |
| `PlayerRegistrationService::registerViaShareLink`, `TrainerPlayerAssociation`, `Trainer\PlayerRosterController` | AC-8 |
| `EmailVerificationTokenService::issue` + `TEMPLATE_PLAYER_WELCOME` | AC-9 |
| `UserAccountService::create` → `EmailAlreadyInUseException` → field-level error (S1/S2 mechanism, reused) | AC-10 |
| `PlayerShareLinkController::follow` signed-in branch → `PlayerShareLinkService::associate` | AC-11 |
| One `trainer_player_association` row per pair; no path updates a sibling row | AC-12 |
| `UNIQUE (trainer_id, player_id)` + `existsFor` pre-check + violation-as-idempotent-success | AC-13 |
| `CoachInvitationController::accept` GET; `CoachRegistrationFormType` with no `email` field | AC-14, AC-21 |
| `CoachInvitationService::accept` (sets `accepted_at`, creates the association); `Trainer\CoachController::index` | AC-15 |
| Partial unique index `(coach_id) WHERE ended_at IS NULL` + `findActiveForCoach` pre-check + `CoachAlreadyActiveElsewhereException` | AC-16 |
| `CoachInvitation::status()` derived + `Trainer\CoachController::index` | AC-17 |
| Distinct `CoachInvitationAlreadyAcceptedException` / `CoachInvitationExpiredException`; re-invite action on the Coaches list | AC-18 |
| `PlayerShareLinkService::associate` role guard + `ShareLinkVoter` | AC-20 |
| `CoachInvitationService::accept` role + email equality guard; email taken from the invitation, never the request | AC-21 |

Edge cases, in the spec's table order: deactivated/deleted account follows a player link →
`associate()`'s `isActive()` guard plus S1's `EquatableInterface` session invalidation;
deactivated/deleted **trainer** → `PlayerShareLinkResolver`/`CoachInvitationService::resolve`
filter `trainer.status = ACTIVE` and raise the single "no longer available" refusal;
two devices on one coach link → `SELECT … FOR UPDATE` by selector; wrong email on a coach
link → AC-21's equality guard; coach re-follows their own accepted link →
`accept()`'s step 4 idempotency branch; coach ended with A accepts B → `ended_at IS NULL`
in both the pre-check and the partial index's predicate; scraped player link →
`share_link_registration_source` limiter plus S1's CSRF and unique-email rules; signed-in
Coach follows a player link → AC-20's role guard; signed-in Player follows a coach link →
`accept()`'s role guard.

No criterion and no edge case is uncovered.
