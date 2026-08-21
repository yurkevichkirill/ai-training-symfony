# Design: Coach Features (Epic-01, slice S5)

> Answers *how*. The *what* and *why* live in `specs/sdd-coach-features-spec.md`
> (AC-1…AC-16, of which AC-9/AC-10 are explicitly deferred to Epic-02); this file does not
> restate them. Governed task: TASK-005. Feature slug: `coach-features`.
>
> Builds on four shipped slices — `specs/auth-foundation-architecture.md` (S1),
> `specs/sdd-user-management-architecture.md` (S2),
> `specs/sdd-sharelink-invitations-architecture.md` (S3),
> `specs/sdd-player-family-availability-architecture.md` (S4). Nothing they froze is
> edited: `User`, `Profile`'s own columns, `ProfileTrainer`, `ProfilePlayer`,
> `CoachInvitation`, `TrainerCoachAssociation`, `TrainerPlayerAssociation`,
> `PlayerAvailabilitySlot`, `AccountEvent` and `UserRole` keep every column and every case
> they already have. This slice adds three new tables, one concrete `Profile` subtype
> (one additive line in the discriminator map — S3's own precedent for `ProfilePlayer`),
> two `AccountEventType` cases, two new value-layer classes, one new voter, one new
> route, one additive route on an existing controller, and one additive method on each of
> two existing services. **No email, no Messenger message, no rate limiter, no Composer
> package.**
>
> **Ground truth re-verified against source, not against the docs.** Five facts shape the
> design and two of them the spec could not have known:
> 1. **`Profile` already anticipates this subtype in writing.** Its docblock names
>    "`profile_trainer`, later `profile_coach`/`profile_player`/`profile_child`", and its
>    `#[ORM\DiscriminatorMap]` currently holds exactly `TRAINER` and `PLAYER`. Adding
>    `COACH` is the same one-line additive change S3 made for `PLAYER`; the base table's
>    columns and its `UNIQUE (user_id, type)` are untouched.
> 2. **No code path anywhere creates a coach profile.** `CoachInvitationService::accept()`
>    and `CoachRegistrationService::registerAndAccept()` create a `User` and a
>    `TrainerCoachAssociation` and nothing else. Every coach that already exists in a
>    deployed database therefore has no `profile_coach` row and never will unless this
>    slice either backfills or creates lazily — see Decision **D1c**.
> 3. **`TrainerCoachAssociationRepository::findActiveForCoach($coach)` already exists**
>    and is already backed by the partial unique index
>    `uniq_trainer_coach_active_coach (coach_id) WHERE ended_at IS NULL`, which guarantees
>    at most one row. That single indexed lookup *is* the authorization primitive AC-5
>    needs, so this slice adds **no** `CoachAssociationResolver` counterpart to S4's
>    `ChildAccountResolver` (D4b).
> 4. **`ProfileController::edit()` already renders a role-conditional second form**
>    (`ProfileTrainerFormType` when `UserRole::TRAINER === $user->getRole()`) with its
>    sibling write action at `POST /profile/business`. AC-11's "alongside the common
>    fields" is that page, and the coach form is that pattern applied once more (D5a).
> 5. **`role_hierarchy` is deliberately flat** — `ROLE_SUPER_ADMIN: [ROLE_USER]`, no
>    inheritance of `ROLE_COACH`. So `#[IsGranted('ROLE_COACH')]` refuses a Super Admin
>    *by itself*, and AC-15's "server-side, not merely UI-hidden" needs no extra clause
>    for admins. This is load-bearing and is asserted in the tests below rather than
>    assumed.
>
> Also verified: `AvailabilityWeekFormType` is a bare `CollectionType` of seven
> `DayAvailabilityFormType`s keyed 1…7, with no player/child coupling of any kind — it is
> reusable verbatim. `account_event.type` is a plain `varchar(64)`, so new
> `AccountEventType` cases need no migration. `App\Availability\WeeklyAvailability` and
> `TimeRange` are plain, final, readonly, Doctrine-free, and contain no notion of a player.
>
> **Files owned by TASK-004 (S4), in flight in parallel, are not edited by this design.**
> Every S4 touchpoint below is additive and lands in a *new* file: the conflict evaluator
> is a new class beside `WeeklyAvailability` rather than a method on it, and the coach
> summary string comes from a new method on `AvailabilitySummaryFormatter` whose existing
> signature is unchanged (D2c, D2d).

## Approach

Five shaping choices carry the slice.

1. **A coach profile is the fourth `Profile` subtype, created lazily on first save.**
   `ProfileCoach extends Profile` → `profile_coach`, holding bio, credentials,
   certifications and one boolean. It is *capability data for one role a user plays* —
   exactly what S1's frozen contract says a `Profile` is — so it needs no argument to
   justify its home. What does need one is its *absence*: coaches already exist without
   one, so the write path upserts (D1c) and every read tolerates `null`, which is also
   precisely how AC-16's "off when nothing has ever been saved" is expressed — no row
   means not public.

2. **Coach availability gets its own table, and reuses S4's *encoding* rather than S4's
   *storage*.** `coach_availability_slot` is column-for-column the shape
   `player_availability_slot` proved out — ISO weekday, minutes-from-midnight endpoints,
   rows only for ranges that exist, absence *is* "not available" — driven by the same
   `WeeklyAvailability`/`TimeRange` value objects and the same
   `AvailabilityWeekFormType`. What is *not* shared is the table, because the owner
   column, the FK, and the index set all differ (D2). The duplicated surface is one
   entity and one repository of straight-line code; everything with logic in it is shared.

3. **The conflict check is a pure function over a value object, not a query.**
   `CoverageEvaluator::evaluate(WeeklyAvailability, int $dayOfWeek, TimeRange)` returns
   one of three `AvailabilityCoverage` cases. It touches no database, no entity and no
   `User`, so AC-6's three-way answer is unit-testable with no kernel and is reusable
   verbatim by Epic-02 against any availability grid — including a player's — without
   Epic-02 having to import anything coach-specific.

4. **The override log is an audit table with no event column and no caller.** AC-7/AC-8
   need a record that is complete *on its own terms*: which coach, which trainer, what
   candidate time, what the evaluated coverage was, the reason, and when. Storing the
   candidate day/start/end is what makes the row self-describing with no event in
   existence — and it is also what makes Epic-02's future `event_id` a pure *narrowing*
   addition that can never contradict what is already stored (D3, D3b). It ships with
   tests and no HTTP or CLI writer at all, which is deliberate: a console command that
   writes a compliance record from a hand-typed reason is a forgery primitive, not a
   test harness (D3c).

5. **Authorization is one voter over `TrainerCoachAssociation`, mirroring S4's
   `AvailabilityVoter` exactly.** Same three-attribute shape, same "reads only `User`
   and an association row, never a `Profile`" invariant, same defence-in-depth pairing of
   a voter at the edge with a guard inside the service. The association lookup is
   evaluated *per authorization check*, which is what makes the spec's "association ends
   ⇒ the former trainer loses read access" edge case continuous rather than
   fetch-time-only.

## Components

### Entities and schema

**`App\Entity\ProfileCoach`** → `profile_coach` (JOINED child of `profile`)

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK, FK `profile(id)` ON DELETE CASCADE | Doctrine's JOINED-inheritance child key; the row's own id/user/timestamps live on `profile` |
| `bio` | `text` NULL | AC-11 |
| `credentials` | `text` NULL | AC-12 (free text — D1b) |
| `certifications` | `text` NULL | AC-12 (free text — D1b) |
| `is_public` | `boolean` NOT NULL DEFAULT `false` | AC-13; the default is the "never saved" state made explicit, and NOT NULL means there is no third "unknown visibility" value |

`Profile`'s `#[ORM\DiscriminatorMap]` gains `'COACH' => ProfileCoach::class`. That is the
**only** edit to a frozen file in this slice, it is a PHP-level addition with no DDL of
its own, and it is the identical change S3 made when it added `PLAYER`. `UNIQUE (user_id,
type)` on the base table then gives "at most one coach profile per user" for free — no
new constraint is declared.

No `deleted_at` handling, no photo column, no name/phone: all three are inherited
(`Profile::$deletedAt`) or live on `app_user` and are edited by S2's already-shipped
`ProfileCommonFormType` path, which AC-11's "alongside the common fields" refers to.

**`App\Entity\CoachAvailabilitySlot`** → `coach_availability_slot`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7, generated in the constructor (S1 convention) |
| `coach_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | the coach's own `User`, not a `profile_coach` row (D2b) |
| `day_of_week` | `smallint` NOT NULL | ISO-8601, Monday = 1 … Sunday = 7 (`date('N')`) |
| `starts_at_minute` | `smallint` NOT NULL | minutes from local midnight, 0…1439 |
| `ends_at_minute` | `smallint` NOT NULL | 1…1440; 1440 is "midnight at the end of the day" |
| `created_at` | `timestamptz` NOT NULL | |

Hand-written checks, identical in form to S4's: `CHECK (day_of_week BETWEEN 1 AND 7)`,
`CHECK (starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)`.
One index: `(coach_id, day_of_week, starts_at_minute)` — it serves the coach's own grid
read, the trainer's roster-card summary (batched by `coach_id IN (…)`), and the AC-6
conflict check, all three of which are always scoped to known coaches.
**Deliberately no `(day_of_week, starts_at_minute, ends_at_minute)` index** — that is
S4's roster-*filter* index, and nothing in this slice or in the epic ever asks "which
coaches are free at 6pm" across coaches. Adding it later is one line; carrying it now is
write amplification for a query with no caller.

**No `is_unavailable` flag and no row-per-day placeholder** — absence *is* "not
available" (AC-1's "a day with no time range set means the coach is not available"), the
same encoding S4 chose and the same encoding `WeeklyAvailability` already implements.
**No time-zone column** — the spec puts time zones out of scope; every value is
facility-local wall-clock, and the absent column keeps that visible (see Risks).
**No `trainer_id`** — a coach has exactly one trainer at a time by S3's index, and the
epic asks for one weekly schedule, not one per relationship.

**`App\Entity\CoachAssignmentOverride`** → `coach_assignment_override`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `coach_id` | `uuid` NOT NULL FK `app_user` **ON DELETE RESTRICT** | whose availability was overridden (AC-7) |
| `overridden_by_user_id` | `uuid` NOT NULL FK `app_user` **ON DELETE RESTRICT** | the trainer who overrode (AC-7, AC-8) |
| `day_of_week` | `smallint` NOT NULL | the candidate assignment's weekday |
| `starts_at_minute` | `smallint` NOT NULL | the candidate range that conflicted |
| `ends_at_minute` | `smallint` NOT NULL | |
| `coverage` | `varchar(24)` NOT NULL | `App\Enum\AvailabilityCoverage`: `FULLY_AVAILABLE`, `PARTIALLY_AVAILABLE`, `UNAVAILABLE` — what AC-6 evaluated at the moment of the override |
| `reason` | `text` NOT NULL | AC-7's required reason |
| `created_at` | `timestamptz` NOT NULL | AC-7's "when" |

Hand-written checks: the same `day_of_week` and `starts < ends` pair as above, plus
`CHECK (btrim(reason) <> '')` — AC-7's "recording without a reason is refused" is a
database fact, not only a service guard (both exist; see D3d). Indexes
`(coach_id, created_at)` and `(overridden_by_user_id, created_at)` — AC-8's two query
directions. **No unique constraint of any kind**: the spec's fourth edge case requires
two rapid overrides for the same coach/trainer pair to persist as two separate rows.
This is an append-only audit log; nothing updates or deletes a row, which is also why
both FKs are `RESTRICT` rather than `CASCADE` — the same choice S2 made for
`account_event.subject_user_id`, for the same reason (a compliance record must not
vanish when an account is removed).

**No `event_id` column.** Epic-02 adds one as `event_id uuid NULL FK event(id) ON DELETE SET NULL`
plus an index in its own migration — see **D3b** for why that is forward-compatible by
construction and why inventing the column now is worse than omitting it.

**`App\Enum\AccountEventType`** gains `COACH_AVAILABILITY_UPDATED` (written by
`CoachAvailabilityService::replaceWeek()`, post-commit; actor = subject = the coach) and
`COACH_ASSIGNMENT_OVERRIDDEN` (written by `CoachAssignmentOverrideService::record()`,
post-commit; actor = the trainer, subject = the coach — the canonical "one user acting on
another" shape `AccountEvent` exists for). Coach profile edits record no new case: they
reuse S2's existing `PROFILE_UPDATED` through `ProfileService`'s own
`recordProfileUpdated()`, exactly as the trainer's business-details edit does. The column
is `varchar(64)`, so neither case needs a migration.

**Migration.** One migration, `Version…CoachFeatures`: create `profile_coach`,
`coach_availability_slot`, `coach_assignment_override`; then the hand-written SQL DBAL
does not diff (five CHECK constraints). Down-migration drops all three in reverse.
**No `ALTER TABLE` anywhere, and no data backfill** — every table is new, no existing
column changes, and existing coach accounts intentionally get no `profile_coach` row
(D1c). `doctrine:schema:update --dump-sql` must report nothing on a *second* run, S3's
partial-index/CHECK normalization trap, even though this slice declares no partial index.

### Controllers → routes

Every route is authenticated. **`security.yaml` gains no new line** — the `^/` catch-all
covers both, so S1's `RouterSweepTest` (which reads that file) keeps passing untouched.

| Route | Controller | Delegates to |
|---|---|---|
| `GET\|POST /coach/availability` (`app_coach_availability`) | `Coach\AvailabilityController::edit` (new, `#[IsGranted('ROLE_COACH')]`) | `CoachAvailabilityService::weekFor` / `::replaceWeek` (AC-1…AC-4) |
| `POST /profile/coach` (`app_profile_edit_coach`) | `ProfileController::editCoach` (added beside the existing `editBusiness`) | `ProfileService::updateCoachDetails` (AC-11…AC-14, AC-16) |

Modified existing controllers, both additively:

- **`ProfileController::edit()`** — one more role-conditional branch beside the trainer
  one: when `UserRole::COACH === $user->getRole()`, build `ProfileCoachFormType` from
  `ProfileRepository::findCoachProfile($user)` (nullable — D1c), and pass it to the
  template as `coachForm`. Email, role and account-created date are not fields on any of
  these forms, which is how AC-14 is satisfied — by absence, the same way it already is
  for trainers. `editCoach()` mirrors `editBusiness()` line for line, including its
  `if (UserRole::COACH !== $user->getRole()) throw $this->createAccessDeniedException(...)`
  guard and its redirect back to `app_profile_edit`. Because every action operates on
  `$this->getUser()` and never on a request-supplied id — the invariant
  `ProfileController`'s own docblock states — AC-15's "no access to edit *another* user's*
  fields through any route this slice adds" is true by construction, not by a check.
- **`Trainer\CoachController::index()`** — the existing `/trainer/coaches` roster gains
  each coach's availability summary (AC-5). `findActiveFor($trainer)` already returns
  only *actively* associated coaches, so the negative half of AC-5 ("a trainer with no
  active association cannot see that coach's availability") is the roster query's own
  `WHERE`, not a filter applied afterwards. The summaries come from one batched
  `CoachAvailabilitySlotRepository::findForCoaches()` call over the roster's coaches plus
  `AvailabilitySummaryFormatter::summarizeWeek()` per card — one query for the page, no
  N+1, S4's roster-card precedent. A coach with no rows renders the formatter's explicit
  "No availability set" string, never an empty cell (the spec's second edge case).

**No trainer-facing detail route, and no "assign coach" route of any kind.** AC-5 asks
for a summary and the roster page is where a trainer already looks at their coaches;
everything past that is AC-9/AC-10, which this slice does not build. `VIEW_COACH_AVAILABILITY`
ships anyway (D4c) so the future detail page and Epic-02's assignment screen fail closed.

### Services

**`CoachAvailabilityService`** (new)
- `weekFor(User $coach): WeeklyAvailability` — reads `CoachAvailabilitySlotRepository::weekFor()`
  and groups rows back into the value object. A weekday with no rows comes back as that
  day's empty range list — "not available" (AC-1), not a third state.
- `replaceWeek(User $coach, WeeklyAvailability $week, User $actor): void` — AC-2, AC-3.
  Normalizes (`WeeklyAvailability::normalized()` — merging the overlapping/touching
  ranges of the spec's first edge case), then one transaction around
  `CoachAvailabilitySlotRepository::replaceWeekFor($coach, $slots)`, a bulk DQL delete
  scoped by `coach_id` followed by the inserts. AC-2's "replaces, never appends" and
  AC-3's isolation are that `WHERE coach_id = :coach` clause — not a diffing algorithm,
  and structurally incapable of touching `player_availability_slot`. Post-commit:
  `COACH_AVAILABILITY_UPDATED`. Service guard (defence in depth, S3's Decision Q4):
  refuses unless `$coach` is an active `UserRole::COACH` and `$actor === $coach`, throwing
  `CoachActionNotPermittedException` — which is what makes AC-15 and the spec's
  forged-request edge case true for a caller that never passes through the controller.
- `evaluate(User $coach, int $dayOfWeek, TimeRange $candidate): AvailabilityCoverage` —
  AC-6. `CoverageEvaluator::evaluate($this->weekFor($coach), …)`. Read-only, no event, no
  write; a pure lookup wrapped around a pure function.

**`CoachAssignmentOverrideService`** (new)
- `record(CoachAssignmentOverrideRequest $request, User $coach, User $trainer): CoachAssignmentOverride`
  — AC-7. Trims the reason and throws `MissingOverrideReasonException` on empty *before*
  the insert (the DB CHECK is the second layer, not the message source); asserts
  `$coach` is a `COACH` and `$trainer` is a `TRAINER` with an active
  `TrainerCoachAssociation` to that coach; re-evaluates coverage through
  `CoachAvailabilityService::evaluate()` and stores what it evaluated, rather than
  trusting a caller-supplied verdict. One transaction, one insert. Post-commit:
  `COACH_ASSIGNMENT_OVERRIDDEN`. **No caller in this slice** (D3c).
- `findForCoach(User $coach): list<CoachAssignmentOverride>` /
  `findForTrainer(User $trainer): list<CoachAssignmentOverride>` — AC-8's "queryable
  later", thin delegations to the repository. No view renders them in this slice; the
  spec's AC-8 asks for the mechanism a future compliance view *could* read, and that is a
  repository method with a test, not a page.

**`CoachAssignmentOverrideRequest`** (new, plain readonly DTO, no Form):
`{int dayOfWeek, int startsAtMinute, int endsAtMinute, string reason}`. Epic-02 adds
`?Uuid $eventId = null` to it — a defaulted trailing constructor parameter, so no existing
call site changes (D3b).

**`ProfileService`** (S2, extended — no behavior change to existing callers)
- `updateCoachDetails(User $user, ProfileCoachRequest $request, ?User $actor = null): void`
  — mirrors `updateTrainerDetails()`, with one deliberate difference: where the trainer
  version throws `\LogicException` when no profile exists, this one **creates** the
  `ProfileCoach` (D1c). Then sets the four fields, `touch()`es, flushes through the same
  private helper, and records `PROFILE_UPDATED` via the same
  `recordProfileUpdated($user, $actor ?? $user)`. Whitespace-only input never reaches
  here as whitespace: `ProfileCoachRequest`'s constructor trims each string and maps `''`
  to `null` (the spec's fifth edge case), in one place rather than per field per caller.

**`AvailabilitySummaryFormatter`** (S4, extended — existing signature untouched)
- New: `summarizeWeek(WeeklyAvailability $week, int $maxDays = 3): string`, which holds
  the day-label/range-formatting/"+N more" logic. The existing
  `summarize(list<PlayerAvailabilitySlot> $slots, int $maxDays = 3)` keeps its exact
  signature and becomes a two-line adapter that groups its slots into a
  `WeeklyAvailability` and delegates. Every S4 caller and every S4 test is unaffected;
  the coach side calls `summarizeWeek()` directly, since `CoachAvailabilityService::weekFor()`
  already returns that type (D2d).

**`App\Availability\CoverageEvaluator`** (new) and **`App\Enum\AvailabilityCoverage`**
(new). The evaluator is final, stateless and dependency-free:
`evaluate(WeeklyAvailability $week, int $dayOfWeek, TimeRange $candidate): AvailabilityCoverage`,
normalizing defensively before comparing so it is correct for a hand-built week as well as
a stored one. `FULLY_AVAILABLE` when one normalized range covers the candidate end to end;
`UNAVAILABLE` when no range shares a minute with it (note: *shares a minute*, so a range
that merely *touches* the candidate at an endpoint is not coverage — the asymmetry with
`TimeRange::overlapsOrTouches()`, which exists for merging, is deliberate and is unit
tested); `PARTIALLY_AVAILABLE` otherwise. Because normalization has already merged
touching ranges, "covered by two adjacent stored ranges" cannot arise as a false
`PARTIALLY_AVAILABLE`.

### Forms and validation

- **`ProfileCoachFormType`** over the `ProfileCoachRequest` DTO (S2's array-data pattern
  in `ProfileController` is followed as it stands): `bio` (`TextareaType`, `required: false`,
  `Length(max: 4000)`), `credentials` (`TextareaType`, `required: false`,
  `Length(max: 2000)`), `certifications` (`TextareaType`, `required: false`,
  `Length(max: 2000)`), `isPublic` (`CheckboxType`, `required: false`). **No `NotBlank`
  anywhere** — that absence is AC-16, and an all-blank submit is a valid save that stores
  three `null`s and leaves `is_public` at whatever the checkbox says. No `email`, `role`
  or `createdAt` field exists on the type, which is AC-14.
- **`AvailabilityWeekFormType`**, **`DayAvailabilityFormType`**, **`TimeRangeFormType`**
  and the `MinutesFromMidnightTransformer` are **reused verbatim from S4** — no new form
  type, no option, no subclass. They are already free of any player coupling (verified),
  and `Coach\AvailabilityController` converts between the form's
  `array<int, array{ranges: list<array{start:int,end:int}>}>` shape and
  `WeeklyAvailability` with the same two private helpers `Player\AvailabilityController`
  uses. The per-day `Count(max: 6)`, `Range(0, 1440)` and `start < end` constraints come
  along unchanged.
- **No form for the override log** — it has no HTTP surface (D3c). Its validation is the
  DTO's trim plus the service's typed refusal plus the DB CHECK.

### Authorization

One new voter, reading only `User::role`, `User::status` and `TrainerCoachAssociation` —
no `Profile` is read, so S1's frozen "authorization never reads a Profile" invariant
holds.

| Voter | Attribute | Subject | Granted when |
|---|---|---|---|
| `CoachVoter` | `EDIT_COACH_PROFILE` | none | the token user is an active `COACH` (AC-11…AC-14) |
| `CoachVoter` | `EDIT_COACH_AVAILABILITY` | `User` (the coach) | the subject **is** the token user, and is an active `COACH` (AC-1, AC-15) |
| `CoachVoter` | `VIEW_COACH_AVAILABILITY` | `User` (the coach) | the above, **or** the token user is an active `TRAINER` and `TrainerCoachAssociationRepository::findActiveForCoach($subject)` returns a row whose trainer **is** the token user (AC-5) |

There is no parent-analogue and no delegation: nobody edits a coach's availability but
that coach. There is no Super Admin clause either — `role_hierarchy` is flat, so an admin
is refused by `ROLE_COACH` and by the voter alike, which is AC-15 read literally. If a
future admin-support view needs the read, it gets its own attribute rather than a
widened one.

`EDIT_COACH_PROFILE` has no `denyAccessUnlessGranted()` caller today — `ProfileController`'s
own role check plus its `$this->getUser()`-only rule already satisfy AC-15 — and it ships
anyway with unit tests, so a future coach-profile surface (an admin view, an API
controller) has the attribute waiting instead of re-deriving the rule. Same reasoning as
S4's Decision D6 for its callerless payment attributes.

Defence in depth, per S3's Decision Q4: every rule exists as a voter **and** as a service
guard. `CoachAvailabilityService::replaceWeek()` and
`CoachAssignmentOverrideService::record()` both re-check role and ownership and throw
`CoachActionNotPermittedException`; the voter gives the clean 403 at the HTTP edge, the
guard is what survives a console command, a future API controller, or a forged request
that never passes through the annotated action.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| Coach's own weekly grid | Controller | `Coach\AvailabilityController` (new) |
| Coach profile fields | Controller | `ProfileController` (extended, one action + one branch) |
| Trainer-facing coach availability summary | Controller | `Trainer\CoachController` (extended) |
| Coach availability read/write + conflict lookup | Service | `CoachAvailabilityService` (new) |
| Override recording and audit read | Service | `CoachAssignmentOverrideService` (new) |
| Coach profile write (lazy create + audit) | Service | `ProfileService` (S2, one additive method) |
| Summary string | Service | `AvailabilitySummaryFormatter` (S4, one additive method) |
| Three-way coverage decision | Value layer | `App\Availability\CoverageEvaluator` + `App\Enum\AvailabilityCoverage` (new) |
| Weekly grid shape, normalization, merging | Value layer | `App\Availability\WeeklyAvailability` / `TimeRange` (S4, unchanged) |
| Audit write | Service | `AccountEventRecorder` (S2, unchanged) |
| Coach authorization | Security | `CoachVoter` (new) |
| Active coach↔trainer lookup | Repository | `TrainerCoachAssociationRepository` (S3, unchanged) |
| Queries and persistence | Repository | `CoachAvailabilitySlotRepository`, `CoachAssignmentOverrideRepository` (new), `ProfileRepository::findCoachProfile()` (S2, one additive method) |

Transaction, controller, service and repository boundaries are unchanged from S1's rules:
one transaction per service method, controllers never `flush()`, services never return a
`Response`, repositories never authorize.

### Tests this slice must produce

Functional — **coach availability**: a signed-in coach sets two ranges on one day and
ranges on two other days, saves, and reads them back (AC-1); a day left empty stores zero
rows and renders as not available (AC-1); saving twice with different ranges leaves
exactly the second set, with no duplicate rows for any day (AC-2); a coach's save leaves
another coach's rows and **every** `player_availability_slot` row untouched, asserted by
row count and content (AC-3); the post-save flash names the trainer visibility (AC-4); a
trainer, a player and a Super Admin each get a **403** — not a redirect — on
`GET` and on a forged `POST` to `/coach/availability` (AC-15, forged-request edge case);
the trainer's `/trainer/coaches` page shows the summary string for an actively associated
coach (AC-5), shows the explicit "no availability set" state for a coach who never saved
(edge case 2), and after that association is ended shows neither (edge case 3).

Functional — **coach profile**: a coach edits bio, credentials, certifications and the
checkbox and reads them back after a round trip (AC-11, AC-12, AC-13); the very first
save creates the `profile_coach` row for a coach account that had none (D1c); an
all-blank submit succeeds and stores nulls (AC-16); a whitespace-only credentials value
stores as `null`, not as spaces (edge case 5); the form renders no email/role/created-date
field and a forged submit carrying those parameters changes none of them (AC-14); a
trainer, player and Super Admin get a 403 from `POST /profile/coach` (AC-15); a coach with
no saved profile renders the checkbox unchecked (AC-16).

Unit: `CoverageEvaluator` across the full matrix — candidate inside one range, exactly
equal to one range, spanning two normalized ranges with a gap, starting before and ending
inside, touching a range only at an endpoint (⇒ `UNAVAILABLE`, the deliberate asymmetry),
a day with no ranges, and both 0 and 1440 boundaries (AC-6);
`AvailabilitySummaryFormatter::summarizeWeek()` output, truncation and empty-week string,
**plus a regression assertion that `summarize()`'s existing output for the same data is
byte-identical to before the extraction** (D2d); `ProfileCoachRequest`'s trimming;
`CoachVoter`'s truth table parameterized over every role × active/deactivated ×
self/associated/unassociated combination, including the flat-`role_hierarchy` assertion
that `ROLE_SUPER_ADMIN` grants nothing here.

Integration/service, against the real database: `record()` refuses an empty and a
whitespace-only reason with `MissingOverrideReasonException` and inserts nothing (AC-7);
two rapid `record()` calls for the same coach/trainer pair produce **two** rows (edge case
4); `findForCoach`/`findForTrainer` return them newest-first with the reason, coverage and
candidate time intact (AC-8); the `btrim(reason) <> ''`, `day_of_week` and `starts < ends`
CHECKs each refuse a direct bad insert; `record()` stores the coverage it evaluated, not a
value the caller passed; `UNIQUE (user_id, type)` on `profile` refuses a second
`ProfileCoach` for one user; `doctrine:schema:update --dump-sql` reports nothing to update
on a **second** run.

Regression: S1's AC-1…AC-25, S2's AC-1…AC-24, S3's AC-1…AC-21 and S4's AC-1…AC-24 must
still hold — in particular S2's profile-edit tests (a trainer's business form and a
player's common form must be unchanged by the new branch) and S4's availability suite,
which the `AvailabilitySummaryFormatter` extraction must leave passing with **zero test
edits**.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| A separate `coach_availability_slot` table with S4's column shape | — | Over reusing `player_availability_slot`: that table's owner is a NOT NULL FK named `player_id` on a readonly entity with a `getPlayer()` accessor, so sharing means either renaming a shipped column and its migration (editing frozen S4 code) or adding a nullable `coach_id` plus an exactly-one-of CHECK — which weakens both FKs to nullable and forces `AND coach_id IS NULL` onto S4's hottest shipped query, `findRosterAvailableAt()`. See **D2**. |
| `smallint` minutes-from-midnight endpoints | — | Same reasoning S4 recorded, and reused deliberately so the two tables stay diffable: an integer makes "ends after starts" a plain CHECK, makes coverage comparison pure integer arithmetic, represents 24:00 which `time` cannot, and never round-trips through a `\DateTime` carrying a date and a zone nobody meant. |
| Free `text` for credentials and certifications | — | Over a `coach_certification` child table or a `jsonb` list: nothing in this slice or the epic *reads* an individual certification. AC-13 explicitly builds no public rendering surface, there is no directory, no filter and no sort. A structured list would add a table, a `CollectionType`, per-entry validation and a repository, all to serve zero readers — and would still be guessing at the fields (issuer? year? expiry?) the epic never names. See **D1b**; the migration path if a reader appears is in Risks. |
| No `event_id` column, no `Event` entity, no stub | — | Over a nullable `event_id uuid` with no FK: a column holding ids with no referent is unverifiable data that no constraint can defend, and a nullable-forever column invites writes this slice cannot validate. The stored day/start/end triple already makes each row self-describing, so Epic-02's added FK is pure narrowing. See **D3b**. |
| No new Composer package | — | Every mechanism exists: `Profile`'s JOINED hierarchy for the subtype, S4's value objects and form types for the grid, `AccountEventRecorder` for audit, PostgreSQL CHECKs for the invariants, the existing `TrainerCoachAssociation` partial unique index for the authorization lookup. NFR-S5-02 confirmed. |

Not added: a calendar/recurrence library (a weekly grid with no dates, no exceptions and
no time zones is seven integers-and-ranges, not an RRULE problem); a Messenger message or
email template (no AC in this slice notifies anyone — Q-01.06's "should the coach be
notified when overridden?" is open and unbuilt, and inventing a notification would be
answering a client question by writing code); a rate limiter (no anonymous or
child-triggered write path exists here — both new writers are self-service by an
authenticated coach).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| **D1. (FR-041) Where coach profile fields live** | A new **`ProfileCoach extends Profile`** → `profile_coach`, adding `'COACH'` to the frozen discriminator map | (a) nullable `bio`/`credentials`/`certifications`/`is_public` columns on `app_user`; (b) columns on `TrainerCoachAssociation`; (c) a generic `user_attribute` key/value table | This is exactly what S1's frozen contract says a `Profile` is — "capability data for one role a User plays, never authority" — and `Profile`'s own docblock already names `profile_coach` as a planned subtype, so the shape is not being invented, it is being filled in. (a) edits the authentication table four slices depend on, to hold data only one role has. (b) puts a coach's own bio on a *relationship*, so it would be lost the day the coach changes trainers — which S3's `ended_at`/re-association flow makes a normal event, not a hypothetical. (c) trades typed columns and a CHECKable boolean for stringly-typed rows and no schema at all. `UNIQUE (user_id, type)` on the base table gives "one coach profile per coach" with no new constraint declared. |
| **D1b. Credentials/certifications structure** | Two nullable free-`text` columns | A `coach_certification` child table (name + issuing body + year, repeatable); a `jsonb` array on `profile_coach` | No reader exists or is designed: AC-13 persists a visibility flag and explicitly builds no public profile page, and nothing filters, sorts, or counts a certification. A child table plus a `CollectionType` plus per-entry validation to serve zero readers is cost with no return, and it would have to *guess* the fields — issuer? year? expiry? verification? — that the epic never names, freezing a guess into schema. Free text matches `ProfileTrainer::$description`, the closest shipped precedent, and AC-12 is written to hold either way. If a public profile later needs per-certification structure, the migration is additive: a new `coach_certification` table plus a one-off parse of the free-text column, with the column retained as the unparsed original. **Flagged in Risks.** |
| **D1c. Coach profiles for coaches that already exist** | **Lazy creation on first save** — `ProfileService::updateCoachDetails()` creates the `ProfileCoach` if absent; every read tolerates `null` | (a) backfill one `profile_coach` row per existing `COACH` user in the migration; (b) create it in `CoachInvitationService::accept()`/`CoachRegistrationService::registerAndAccept()` going forward | No shipped code path creates a coach profile (verified), so every coach in a deployed database has none. (b) edits S3 services and, worse, splits coaches into two populations — pre-S5 coaches with no row and post-S5 coaches with one — so every reader needs the null branch *anyway*, and the write path needs the upsert *anyway*. Having built both, the backfill in (a) buys nothing and writes rows for accounts that asked for nothing. Lazy creation is also the natural expression of AC-16: **no row means not public**, so the default state is a fact about the absence of data rather than a value someone had to remember to write. |
| **D2. (Spec's headline open question) Coach availability storage** | **A new `coach_availability_slot` table**, reusing S4's *column shape, encoding, value objects and form types* but **not** its entity or table | (a) reuse `player_availability_slot` as-is (its `player_id` FK pointing at a coach's `User`); (b) add a nullable `coach_id` to it with `CHECK (num_nonnulls(player_id, coach_id) = 1)`; (c) rename it to `availability_slot` with a generic `subject_user_id`; (d) single-table inheritance over a shared base | The value objects are Doctrine-free and player-free, so **the representation reuse the spec asked about is real and is taken in full** — `WeeklyAvailability`, `TimeRange`, `normalized()`, `AvailabilityWeekFormType` and its whole subtree are used verbatim, and the new table is column-for-column identical to S4's. What is *not* shareable is the row's owner. (a) means storing coaches in a table whose column, FK, entity property, constructor parameter and accessor are all named `player`, so every future reader has to know that a "player" row might be a coach — and S4's roster filter would silently sweep coaches into a *player* roster query. (b) makes both FKs nullable, replaces two NOT NULL guarantees with one CHECK, and forces `AND coach_id IS NULL` onto `findRosterAvailableAt()` — S4's hottest shipped query, in a slice that must not touch it. (c) renames a shipped table and column and rewrites S4's migration, entity, repository and tests: a frozen-code edit, and one currently under parallel modification by TASK-004. (d) adds a discriminator and a shared index set to serve two genuinely different read patterns — a roster-wide "who is free at 6pm" scan for players versus a single-coach point lookup for coaches — so the two would fight over indexes forever. The cost paid is one ~90-line entity and one ~60-line repository of straight-line delete-and-insert; the code that has *logic* in it (normalization, merging, coverage, summary formatting, the form tree) is shared, not copied. That is the right side of the duplication trade: duplicate the boring shape, share the behavior. |
| **D2b. What coach availability hangs off** | `coach_id` FK to `app_user` | FK to `profile_coach.id` | Matches `TrainerCoachAssociation.coach_id`'s own precedent, so the AC-5 roster join goes association → slots directly with no hop through `profile`/`profile_coach`. Decisively: a coach may have **no** `profile_coach` row (D1c) but must still be able to set availability, so an FK to the profile would make AC-1 depend on AC-11 having happened first. |
| **D2c. Where the conflict evaluation lives** | A **new** `App\Availability\CoverageEvaluator` + `AvailabilityCoverage` enum beside the existing value objects | (a) a `coverageOf()` method on `WeeklyAvailability`; (b) a private method inside `CoachAvailabilityService`; (c) SQL in the repository | (a) is the tidier object design and is rejected on process grounds that are real, not ceremonial: `WeeklyAvailability` is a TASK-004 file under active parallel modification, and editing it invites a merge conflict in the one class both slices depend on. A separate final class is behaviorally equivalent, costs one file, and can be folded into the value object later with no caller change. (b) buries a pure, table-free function inside a service that needs a database, making AC-6 kernel-dependent to test and unreachable for Epic-02 without dragging the service along. (c) makes the three-way answer a database round trip and puts branching logic in a repository. |
| **D2d. Sharing the summary string** | Add `summarizeWeek(WeeklyAvailability)` to `AvailabilitySummaryFormatter` and make the existing `summarize(list<PlayerAvailabilitySlot>)` a two-line adapter over it | (a) a second `CoachAvailabilitySummaryFormatter`; (b) change `summarize()`'s parameter type to a new interface both slot entities implement | (b) is the classic move and it edits a shipped signature plus adds an interface to a frozen S4 entity — two frozen-file edits to avoid one method. (a) duplicates day labels, 12-hour formatting and the "+N more" truncation, which is exactly the drift that makes a trainer's player card and coach card read differently for no reason. The chosen shape adds one method, changes no signature, and keeps every S4 caller and test byte-for-byte valid — which the tests assert explicitly. |
| **D3. (AC-7, AC-8) The override log's shape** | A dedicated append-only `coach_assignment_override` table carrying coach, overriding trainer, the **candidate day/start/end**, the **evaluated coverage**, the reason and the timestamp | (a) reuse `AccountEvent` with a new type and the details in its JSON context; (b) store only coach/trainer/reason/timestamp | (b) produces a row that cannot answer "what was overridden?" without an event that does not exist — the log would be unreadable for the entire life of this slice and forever for any row written before Epic-02. Storing the candidate time is what makes each row self-describing *and* what makes D3b's forward-compatibility structural. (a) is tempting since `AccountEvent` is already the "one user acting on another" log and a `COACH_ASSIGNMENT_OVERRIDDEN` case is added there anyway — but AC-7's required-reason rule must be enforceable (`CHECK (btrim(reason) <> '')`) and AC-8's queries must be indexable by coach and by trainer, neither of which a JSON context column gives. So both exist, each doing what it is good at: the dedicated table is the queryable compliance record, the `AccountEvent` row is the entry in the unified account timeline. |
| **D3b. Forward-compatibility with Epic-02's `event_id`** | **No column now.** Epic-02 adds `event_id uuid NULL FK event(id) ON DELETE SET NULL` plus an index in its own migration; the service takes a DTO that gains an optional `?Uuid $eventId = null` | (a) add `event_id uuid NULL` now with no FK; (b) add it now with an FK to a stub `event` table; (c) an untyped `context jsonb` column to absorb whatever Epic-02 needs | The spec's own rule is that a deferred column must not be *invented*. (a) is the specific failure mode that rule is about: a nullable uuid with no referent is data nothing can validate, DBAL cannot diff a FK onto it later without the target table existing anyway, and in the meantime any caller can write any uuid. (b) invents the `Event` entity the spec forbids. (c) makes the compliance record's most important future field unqueryable and untyped. **What makes omission safe is structural, not hopeful:** no unique constraint or index anywhere in this table spans a column set an event would have to join, so adding one breaks nothing; the day/start/end triple already records the assignment's time, so a later `event_id` only *narrows* an existing row's meaning and can never contradict it; every existing row remains valid and interpretable with `event_id IS NULL` (it means "recorded before events existed", which is true); and the write surface is a DTO, so Epic-02 adds a defaulted trailing parameter rather than a new method or a changed signature. `ALTER TABLE … ADD COLUMN … NULL` plus `ADD CONSTRAINT … FOREIGN KEY` is a non-blocking, non-rewriting migration in PostgreSQL. |
| **D3c. How the override capability is exercised in this slice** | Service + repository + tests only. **No route, no form, no console command** | (a) an `app:coach:check-availability` console command that records an override; (b) a trainer-facing "record an override" route | The spec permits a console command; this design declines it. `record()` writes a compliance record whose whole value is that it was produced by a real conflict at a real decision point. A command (or route) that writes one from a hand-typed reason is a forgery primitive shipped to production to serve a test, and it would live on long after Epic-02 gave the capability its real caller. Tests exercise the service directly — which is what "prove the capability is correct and ready" (spec scenario 5) actually requires. A read-only `app:coach:availability:check` that *evaluates without recording* would be harmless, and is still not built: nothing asks for it. |
| **D3d. Where the required-reason rule is enforced** | Three layers: the DTO trims, the service throws `MissingOverrideReasonException`, the column carries `CHECK (btrim(reason) <> '')` | The CHECK alone; the service guard alone | The CHECK alone surfaces as a `DriverException` with no usable message and depends on the caller not having already committed something else in the transaction. The guard alone is bypassed by a future second writer or a data import — the exact class of drift S4's D7 had to flag as a *risk* because a shipped column made a CHECK impossible there. Here the column is new, so the invariant can be a database fact, and this project's stated habit ("invariants are database facts") applies with no exception to declare. |
| **D4. (AC-5, AC-15) Authorization shape** | One `CoachVoter` with `EDIT_COACH_PROFILE` / `EDIT_COACH_AVAILABILITY` / `VIEW_COACH_AVAILABILITY`, mirroring `AvailabilityVoter`'s attribute shape but keyed on `TrainerCoachAssociation` | (a) `access_control` path rules; (b) `#[IsGranted('ROLE_COACH')]` alone; (c) extending S4's `AvailabilityVoter` with coach branches | (a) cannot express "this trainer is *this* coach's trainer" at all. (b) covers the edit routes (and does, as belt-and-braces) but says nothing about AC-5's object-level "*which* coach may this trainer read", which is the only genuinely object-level rule in the slice. (c) would put player-parent logic and coach-association logic in one class with two unrelated repositories injected, and would make S4's shipped voter — currently under parallel modification — a file this slice edits. A parallel voter with a parallel shape is the same pattern applied twice, which is what makes both readable. |
| **D4b. The active-association lookup** | Inject `TrainerCoachAssociationRepository::findActiveForCoach()` into the voter directly | A `CoachAssociationResolver` service mirroring S4's `ChildAccountResolver` | `ChildAccountResolver` exists because S4 had to *create* the "is this a child, and whose?" question and wanted exactly one answer to it. Here that method already exists, is already documented, and is already backed by the partial unique index that guarantees at most one row — a resolver would be a pass-through wrapper adding a name and a file. The lookup runs per authorization check and is served from Doctrine's identity map after the first call within a request; the roster page batches its own read separately, so no N+1 arises (asserted by a query-count test, per S4's own risk note). |
| **D4c. Callerless attributes** | Ship `EDIT_COACH_PROFILE` and `VIEW_COACH_AVAILABILITY` with unit tests even though the profile routes are guarded by role + `getUser()` and no coach-availability detail page exists | Add each attribute when its first caller appears | S4's D6 precedent, and the same reasoning: Epic-02's assignment screen and any future admin/API surface will need "may this trainer read this coach's times", and the failure mode of *not* having it is that the future slice invents its own rule from a different spec. The attribute existing and already denying is what makes that slice fail closed. |
| **D5a. (AC-11) Where the coach profile form lives** | Extend the existing `ProfileController` — one role-conditional branch in `edit()` plus a `POST /profile/coach` action beside `editBusiness()` | A new `Coach\ProfileController` with its own `GET /coach/profile` page | AC-11 says the coach fields sit "alongside the common profile fields (name, phone, photo) every role already has", and that page is `/profile`, which *already* renders exactly this pattern for a trainer. A separate controller and route splits one user-visible page across two controllers, duplicates the read-only-field discipline AC-14 depends on (which is currently guaranteed by `ProfileController`'s "always `$this->getUser()`, never a request id" invariant), and gives a coach two profile pages to wonder about. |
| **D5b. (AC-5) Where a trainer sees coach availability** | On the existing `/trainer/coaches` roster, as a summary string per card — **no new route** | A `GET /trainer/coaches/{id}/availability` detail page | AC-5 asks for "a short summary", and `Trainer\CoachController::index()` is already the page where a trainer looks at their coaches, already reads `findActiveFor($trainer)` (so the negative half of AC-5 is the query's own `WHERE`, not an added filter), and already has S4's batched roster-card precedent to copy. A detail page is where AC-9's warning flow would naturally live, and AC-9 is deferred — building the page now means guessing its shape against an Epic-02 requirement. The voter ships regardless (D4c) so that page fails closed when it arrives. |
| **D6. Audit events** | Reuse `AccountEvent` with two new `AccountEventType` cases; coach profile edits reuse the existing `PROFILE_UPDATED` | A new `coach_event` table; a third case for coach profile edits | `AccountEvent` was built for "one user acting on another" and already carries an actor and a subject with the right nullability; `type` is `varchar(64)`, so both cases are migration-free. A coach editing their own profile is *the same event* a trainer editing theirs is — S2's `PROFILE_UPDATED`, written by the same `ProfileService::recordProfileUpdated()` — and a coach-specific duplicate would split one concept across two cases for every future report that reads it. |

## Risks

- **D1b's free-text certifications will be asked to become structured.** The moment a
  public coach profile (AC-13's deferred rendering surface) or a "find a coach with X
  certification" search appears, free text is the wrong shape and the existing data is
  unparsed prose. Deliberate and cheap to fix while row counts are small: add a
  `coach_certification` child table, parse line-by-line as a one-off, and keep the text
  column as the unparsed original rather than dropping it. **Ask the client before
  building the public profile page, not after.**
- **Time zones are absent by construction**, exactly as in S4 — every stored minute is
  facility-local wall-clock, and there is no column recording what was meant. This is
  more dangerous here than for players, because the coach grid's *purpose* is
  conflict-checking against event times that Epic-02 will store with real timestamps.
  Revisit before Epic-02 compares an event's `timestamptz` against these integers; a
  zone on the trainer's organization plus a normalization pass is the cheapest fix.
- **`AvailabilitySummaryFormatter` is an S4 file this slice extends while TASK-004 is in
  flight.** The extraction is behavior-preserving by construction (the old signature
  becomes an adapter) but it is a genuine concurrent edit. Cheapest early check: land it
  *after* TASK-004 merges, and require S4's full availability suite green with zero test
  edits before adding any coach caller.
- **Two availability tables will drift.** They are column-identical today; the day one
  gains a `time_zone`, a `note` or an `is_recurring` and the other does not, a reader
  comparing them will be wrong. Mitigation: a repository-integration test asserting the
  two tables' column sets remain identical, which turns drift into a failing test instead
  of a surprise — and if drift is ever *intended*, deleting that test is the explicit
  decision to allow it.
- **The override log has no reader in this slice, so its usefulness is untested in
  anger.** `findForCoach`/`findForTrainer` are covered, but nobody has looked at a page of
  them to discover a missing column. The likeliest gap is *which trainer's organization*
  the override belonged to, if a coach changes trainers between the override and the
  audit. Cheapest early check: assert in a test that a row written before an association
  ended is still fully readable after it ends — it is, since the trainer is stored
  directly rather than derived — and note that anything beyond that is Epic-02's to
  discover.
- **AC-13's checkbox persists a value with no enforcement anywhere.** `is_public` is
  written and read by nothing but the form. Whoever builds the public profile must treat
  it as the gate; nothing in this slice stops a future page from listing coaches
  regardless. Mitigation: name it in that slice's spec, and consider a
  `VIEW_PUBLIC_COACH_PROFILE` voter attribute at that point rather than a template
  condition.
- **`coach_assignment_override`'s `RESTRICT` FKs make a coach with overrides
  undeletable.** Correct for an audit record and consistent with
  `account_event.subject_user_id`, but it means S2's GDPR deletion path meets a new
  blocker. Cheapest early check: exercise `AccountLifecycleService::delete()` against a
  coach that has an override row and confirm the anonymize-in-place path handles it — the
  spec does not cover this, so **decide it explicitly during implementation** rather than
  discovering it from a failing delete.
- **AC-9/AC-10 are deferred, and a reader of the code will not see that.** The service
  surface looks complete, so a future contributor may assume the assignment flow exists
  somewhere. Mitigation: `CoachAssignmentOverrideService`'s class docblock states, in
  writing, that it has no production caller by design, names Epic-02 as the intended one,
  and points at D3b for the `event_id` extension — the same in-code documentation
  discipline `TrainerCoachAssociation` used for its writer-less `endedAt` column.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `Coach\AvailabilityController::edit` + reused `AvailabilityWeekFormType` + `CoachAvailabilitySlot` (absence = not available) | AC-1 |
| `CoachAvailabilityService::replaceWeek` → `replaceWeekFor`'s `DELETE … WHERE coach_id = :coach` then insert, over `WeeklyAvailability::normalized()` | AC-2 |
| That same `WHERE coach_id = :coach` scoping, plus a separate table from `player_availability_slot` (D2) | AC-3 |
| `Coach\AvailabilityController::edit`'s post-save flash naming trainer visibility | AC-4 |
| `Trainer\CoachController::index` + `findActiveFor($trainer)` + `CoachAvailabilitySlotRepository::findForCoaches` + `AvailabilitySummaryFormatter::summarizeWeek`; `CoachVoter::VIEW_COACH_AVAILABILITY` for the negative half | AC-5 |
| `App\Availability\CoverageEvaluator::evaluate` + `AvailabilityCoverage` (three cases), wrapped by `CoachAvailabilityService::evaluate` | AC-6 |
| `CoachAssignmentOverrideService::record` + `CoachAssignmentOverrideRequest`'s trim + `MissingOverrideReasonException` + `CHECK (btrim(reason) <> '')` | AC-7 |
| `coach_assignment_override`'s self-describing columns + `(coach_id, created_at)` / `(overridden_by_user_id, created_at)` indexes + `findForCoach`/`findForTrainer`; `AccountEventType::COACH_ASSIGNMENT_OVERRIDDEN` in the account timeline | AC-8 |
| **Not built — Epic-02.** No route, no template, no assignment surface. D3b keeps the log's shape ready | AC-9 |
| **Not built — Epic-02.** No notification, no assignment for a coach to respond to (Q-01.06 also open) | AC-10 |
| `ProfileCoach.bio` + `ProfileCoachFormType` rendered inside the existing `ProfileController::edit` page (D5a) | AC-11 |
| `ProfileCoach.credentials` / `.certifications` as free text (D1b) | AC-12 |
| `ProfileCoach.is_public` NOT NULL DEFAULT false + the checkbox; no public rendering surface built | AC-13 |
| No `email`/`role`/`createdAt` field on `ProfileCoachFormType`, and `ProfileController`'s always-`$this->getUser()` invariant | AC-14 |
| `#[IsGranted('ROLE_COACH')]` under a flat `role_hierarchy` + `CoachVoter` + `CoachActionNotPermittedException` service guards in `CoachAvailabilityService`/`CoachAssignmentOverrideService` | AC-15 |
| No `NotBlank` on any `ProfileCoachFormType` field + `ProfileCoachRequest`'s `''` → `null` trim + `is_public` DEFAULT false + D1c's "no row means not public" | AC-16 |

Edge cases, in the spec's table order:

1. **Overlapping or touching ranges on one day** — `WeeklyAvailability::normalized()`
   (S4, reused verbatim) merges them before `replaceWeek` persists anything, so `4-6pm`
   + `5-7pm` stores as one `4-7pm` row.
2. **A coach who has never saved availability** — zero rows;
   `AvailabilitySummaryFormatter::summarizeWeek()` returns its explicit "No availability
   set" string, which the roster card renders instead of an empty cell.
3. **The `TrainerCoachAssociation` ends after a trainer already saw the schedule** —
   `findActiveFor($trainer)` stops returning that coach *and*
   `CoachVoter::VIEW_COACH_AVAILABILITY` re-evaluates `findActiveForCoach()` on every
   check, so read access is lost continuously, not just at initial fetch.
4. **Two overlapping override writes for the same coach/trainer pair** — both persist:
   `coach_assignment_override` carries no unique constraint at all, by design, and nothing
   updates or deletes a row.
5. **Whitespace-only credentials/certifications** — `ProfileCoachRequest`'s constructor
   trims and maps `''` to `null`, so the column stores `NULL`, not spaces.
6. **A trainer or admin calls a coach-availability-editing route** — `ROLE_COACH` on the
   controller (flat `role_hierarchy`, so a Super Admin is refused too),
   `CoachVoter::EDIT_COACH_AVAILABILITY` on the subject, and
   `CoachActionNotPermittedException` inside `replaceWeek` for any caller that never
   passes through the controller.
7. **Epic-02 calls AC-6/AC-7's capability with a real `event_id`** — D3b: one additive
   nullable column plus an FK, one defaulted DTO parameter, no changed signature, no
   invalidated row.

**Nothing in this design is left silently unanswered, and one thing is deliberately
absent rather than approximated:** AC-9 and AC-10 are not built, and no stub, no
placeholder route, and no invented `Event` entity stands in for them. The four questions
the spec delegated to this phase — availability storage reuse (**D2**), `ProfileCoach`
shape (**D1**), credentials/certifications structure (**D1b**), and override-log
forward-compatibility (**D3b**) — are each resolved with the rejected alternatives named.
Q-01.06 (should a coach be notified when overridden?) remains a client question and is
answered by building no notification, which is the only answer that does not
pre-commit the client to a mechanism.
