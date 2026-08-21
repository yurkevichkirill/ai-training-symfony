# TASK-005 — Epic-01 slice S5: Coach Features

Design: `specs/sdd-coach-features-architecture.md`. Spec:
`specs/sdd-coach-features-spec.md`. Each task cites the acceptance criteria
(AC-N) it serves, or — for a schema/infrastructure/gate task with no AC of
its own — the Decision or Risk it protects. Mark `[x]` only once the change
is made, migrated, and (where a test task follows) proven.

**Sequencing risk called out up front — read before starting Task 24.**
The architecture's D2d/Risk section requires `AvailabilitySummaryFormatter`'s
extraction (existing `summarize(list<PlayerAvailabilitySlot>)` becoming a
two-line adapter over a new `summarizeWeek(WeeklyAvailability)`) to land only
once TASK-004 (S4) is fully merged and green — the same file is under active
parallel modification there. **Checked against
`tasks/TASK-004/writing-plans-plan.md` at the time this plan was written:
45 of its 47 tasks are `[x]`; Task 46 (review pass) and Task 47 (full
regression) are still open (`[ ]`).** TASK-004 is therefore **not yet fully
merged/green**, so this risk is **pending, not cleared**. Task 24 below
carries an explicit blocking gate on that fact rather than assuming it has
resolved by implementation time — re-check `tasks/TASK-004/writing-plans-plan.md`
immediately before starting Task 24, and do not start it while any of
TASK-004's 47 tasks (in particular 46/47) remain unchecked.

## Schema

- [x] 1. Add `'COACH' => ProfileCoach::class` to `Profile`'s
  `#[ORM\DiscriminatorMap]` (the one edit to a file S1 otherwise treats as
  frozen — additive only, identical in shape to S3's `PLAYER` addition; no
  other line in `Profile` changes). Create `App\Entity\ProfileCoach extends
  Profile` (`profile_coach`, JOINED child: `id` FK `profile(id)` ON DELETE
  CASCADE; `bio` text NULL; `credentials` text NULL; `certifications` text
  NULL; `isPublic` boolean NOT NULL DEFAULT false). No new constraint is
  declared beyond the base table's existing `UNIQUE (user_id, type)`. (D1,
  D1b, AC-11, AC-12, AC-13)
- [x] 2. Create `App\Entity\CoachAvailabilitySlot` (`coach_availability_slot`:
  `id` UUIDv7 PK; `coach` FK `app_user` ON DELETE CASCADE, NOT NULL;
  `dayOfWeek` smallint, ISO-8601 Monday=1…Sunday=7; `startsAtMinute` smallint
  0…1439; `endsAtMinute` smallint 1…1440; `createdAt`), column-for-column
  matching S4's `PlayerAvailabilitySlot` shape but its own table (per D2 —
  do not reuse or alter `player_availability_slot`). Hand-written CHECKs:
  `day_of_week BETWEEN 1 AND 7`; `starts_at_minute >= 0 AND ends_at_minute
  <= 1440 AND starts_at_minute < ends_at_minute`. Index `(coach_id,
  day_of_week, starts_at_minute)`. **Deliberately no**
  `(day_of_week, starts_at_minute, ends_at_minute)` roster-filter index (D2 —
  nothing in this slice queries across coaches) **and no `is_unavailable`
  flag or row-per-day placeholder** — absence of rows for a weekday is "not
  available" (AC-1). (D2, D2b, AC-1, AC-2, AC-3)
- [x] 3. Create `App\Entity\CoachAvailabilitySlotRepository` with `weekFor(User
  $coach): list<CoachAvailabilitySlot>` (the grid read), `findForCoaches(list<User>
  $coaches): list<CoachAvailabilitySlot>` (AC-5's batched roster-card read,
  one query, no N+1), and `replaceWeekFor(User $coach, list<CoachAvailabilitySlot>
  $slots): void` (a bulk DQL delete scoped by `coach_id` followed by the
  inserts, run inside the caller's transaction — the `WHERE coach_id =
  :coach` clause is what makes AC-2's "replace, never append" and AC-3's
  per-coach isolation structural rather than logic the service has to get
  right). (AC-1, AC-2, AC-3, AC-5)
- [x] 4. Create `App\Enum\AvailabilityCoverage` (`FULLY_AVAILABLE`,
  `PARTIALLY_AVAILABLE`, `UNAVAILABLE`). Create `App\Entity\CoachAssignmentOverride`
  (`coach_assignment_override`: `id` UUIDv7 PK; `coach` FK `app_user` ON
  DELETE **RESTRICT**; `overriddenByUser` FK `app_user` ON DELETE
  **RESTRICT**; `dayOfWeek` smallint; `startsAtMinute` smallint;
  `endsAtMinute` smallint; `coverage` varchar(24) backed by
  `AvailabilityCoverage`; `reason` text NOT NULL; `createdAt`). Hand-written
  CHECKs: the same `day_of_week`/`starts < ends` pair as Task 2, plus
  `CHECK (btrim(reason) <> '')` (AC-7's required-reason rule as a database
  fact, not only a service guard — D3d). Indexes `(coach_id, created_at)`
  and `(overridden_by_user_id, created_at)`. **No unique constraint of any
  kind** — two rapid overrides for the same coach/trainer pair must both
  persist (edge case 4) — and **no `event_id` column** (D3b: Epic-02 adds
  it later as a pure narrowing addition; inventing it now is explicitly
  rejected). (D3, D3b, D3d, AC-7, AC-8)
- [x] 5. Create `App\Repository\CoachAssignmentOverrideRepository` with
  `findForCoach(User $coach): list<CoachAssignmentOverride>` and
  `findForTrainer(User $trainer): list<CoachAssignmentOverride>`, both
  newest-first (AC-8's "queryable later"). (AC-8)
- [x] 6. Add `findCoachProfile(User $user): ?ProfileCoach` to the existing
  `App\Repository\ProfileRepository` (S2, additive method only — no existing
  method's signature or behavior changes). (D1c, AC-11)
- [x] 7. Add `COACH_AVAILABILITY_UPDATED` and `COACH_ASSIGNMENT_OVERRIDDEN`
  cases to `App\Enum\AccountEventType`. `type` on `account_event` is
  `varchar(64)`, so this needs no migration of its own. (D6, AC-4, AC-8)
- [x] 8. Generate one migration, `Version…CoachFeatures`, creating
  `profile_coach`, `coach_availability_slot`, `coach_assignment_override`
  with every CHECK from Tasks 1, 2 and 4 hand-written into the migration
  (Doctrine's diff tool does not emit CHECK constraints). No `ALTER TABLE`
  anywhere — every table is new, no existing column changes, and no
  backfill is performed for coaches that already exist (D1c — lazy creation
  only, see Task 12). Down-migration drops all three tables in reverse
  order. Run `bin/console doctrine:schema:update --dump-sql` twice after
  migrating and confirm the second run reports nothing to update (S3's
  partial-index/CHECK normalization trap). (D1, D1c, D2, D3, D3b)

## Value layer — conflict evaluation

- [x] 9. Create `App\Availability\CoverageEvaluator` (final, stateless,
  Doctrine-free): `evaluate(WeeklyAvailability $week, int $dayOfWeek,
  TimeRange $candidate): AvailabilityCoverage`. Normalizes defensively
  before comparing. `FULLY_AVAILABLE` when one normalized range covers the
  candidate end to end; `UNAVAILABLE` when no range shares a minute with it
  (a range that only *touches* the candidate at an endpoint is
  `UNAVAILABLE`, not partial — the deliberate asymmetry with
  `TimeRange::overlapsOrTouches()`); `PARTIALLY_AVAILABLE` otherwise. Placed
  beside `WeeklyAvailability`/`TimeRange` as a new file, not a method added
  to either — those are TASK-004 files under active parallel modification
  and must not be edited (D2c). (D2c, AC-6)

## Services

- [x] 10. Create `App\Service\CoachAvailabilityService` with `weekFor(User
  $coach): WeeklyAvailability` (reads `CoachAvailabilitySlotRepository::weekFor()`
  and groups into the value object; a weekday with no rows comes back as an
  empty range list, not a third state) and `evaluate(User $coach, int
  $dayOfWeek, TimeRange $candidate): AvailabilityCoverage` (delegates to
  `CoverageEvaluator::evaluate($this->weekFor($coach), ...)` — read-only, no
  write, no event). (AC-1, AC-6)
- [x] 11. Add `replaceWeek(User $coach, WeeklyAvailability $week, User
  $actor): void` to `CoachAvailabilityService`. Normalizes via
  `WeeklyAvailability::normalized()` (merges overlapping/touching ranges —
  edge case 1), then one transaction around
  `CoachAvailabilitySlotRepository::replaceWeekFor()`. Service guard
  (defence in depth, S3's Q4 pattern): throws
  `CoachActionNotPermittedException` unless `$coach` is an active
  `UserRole::COACH` and `$actor === $coach` — this is what makes AC-15 and
  the forged-request edge case hold for any caller that never passes
  through a controller. Post-commit: records `COACH_AVAILABILITY_UPDATED`
  via `AccountEventRecorder` (actor = subject = the coach). (AC-2, AC-3,
  AC-4, AC-15)
- [x] 12. Add `updateCoachDetails(User $user, ProfileCoachRequest $request,
  ?User $actor = null): void` to the existing `App\Service\ProfileService`
  (S2, additive method — `updateTrainerDetails()` and every other existing
  method's behavior is unchanged). Unlike the trainer version, **creates**
  the `ProfileCoach` when `ProfileRepository::findCoachProfile()` returns
  null (D1c's lazy creation — no backfill migration, no S3 service edit).
  Sets bio/credentials/certifications/isPublic, `touch()`es, flushes through
  the existing private helper, records `PROFILE_UPDATED` via
  `recordProfileUpdated($user, $actor ?? $user)` — the same case the
  trainer's business-details edit already uses; no new `AccountEventType`
  case for this. (D1c, D6, AC-11, AC-12, AC-13, AC-16)
- [x] 13. Create `App\Dto\ProfileCoachRequest` (plain constructor DTO):
  trims `bio`/`credentials`/`certifications` and maps `''` to `null` in the
  constructor (edge case 5's whitespace-only rule handled in one place, not
  per field per caller); carries `isPublic: bool`. (AC-16, edge case 5)
- [x] 14. Create `App\Dto\CoachAssignmentOverrideRequest` (plain readonly
  DTO, no Form): `{int $dayOfWeek, int $startsAtMinute, int $endsAtMinute,
  string $reason}`. Note for the record: Epic-02 will add `?Uuid $eventId =
  null` as a defaulted trailing constructor parameter later — no existing
  call site changes when it does (D3b). (D3b, AC-7)
- [x] 15. Create `App\Exception\MissingOverrideReasonException` and
  `App\Exception\CoachActionNotPermittedException` (plain domain
  exceptions, no HTTP mapping needed beyond the controllers/tests that
  catch them). (AC-7, AC-15)
- [x] 16. Create `App\Service\CoachAssignmentOverrideService` with
  `record(CoachAssignmentOverrideRequest $request, User $coach, User
  $trainer): CoachAssignmentOverride`. Trims the reason and throws
  `MissingOverrideReasonException` on empty **before** the insert (the DB
  CHECK from Task 4 is the second layer, per D3d); asserts `$coach` is an
  active `COACH` and `$trainer` is an active `TRAINER` with an active
  `TrainerCoachAssociation` to that coach; re-evaluates coverage through
  `CoachAvailabilityService::evaluate()` and stores what it evaluated
  rather than trusting a caller-supplied verdict. One transaction, one
  insert. Post-commit: records `COACH_ASSIGNMENT_OVERRIDDEN` (actor =
  trainer, subject = coach). **No route, no form, no console command calls
  this method in this slice** (D3c — deliberate: a writer with no real
  conflict behind it is a forgery primitive, not a test harness). This
  service still needs its own task, distinct from Task 5's repository and
  Task 14's DTO, because it is where AC-7's refusal rule, AC-8's
  audit-completeness guarantee, and the trainer/coach-association
  authorization check actually live — none of those exist yet from the
  entity/repository/DTO alone, and "no HTTP caller" is not the same as "no
  behavior to build or test." (D3, D3c, D3d, AC-7, AC-8)
- [x] 17. Add `findForCoach(User $coach): list<CoachAssignmentOverride>` and
  `findForTrainer(User $trainer): list<CoachAssignmentOverride>` to
  `CoachAssignmentOverrideService`, thin delegations to the Task 5
  repository — AC-8's "queryable later" as a service-layer surface, not
  only a repository one. (AC-8)

## Authorization

- [x] 18. Create `App\Security\Voter\CoachVoter` with three attributes,
  mirroring `AvailabilityVoter`'s shape: `EDIT_COACH_PROFILE` (no subject —
  granted when the token user is an active `COACH`); `EDIT_COACH_AVAILABILITY`
  (subject `User`, the coach — granted when the subject **is** the token
  user and is an active `COACH`); `VIEW_COACH_AVAILABILITY` (subject `User`,
  the coach — granted under the same rule as `EDIT_COACH_AVAILABILITY`, **or**
  when the token user is an active `TRAINER` and
  `TrainerCoachAssociationRepository::findActiveForCoach($subject)` returns
  a row whose trainer is the token user). Reads only `User` and
  `TrainerCoachAssociation`, never a `Profile` — S1's "authorization never
  reads a Profile" invariant holds. No Super Admin clause: `role_hierarchy`
  is flat, so `ROLE_SUPER_ADMIN` grants nothing here by construction — this
  is what makes AC-15 hold for admins with no extra code, and it must be
  asserted by a test (Task 44), not merely assumed. (D4, D4b, D4c, AC-5,
  AC-15)

## Forms and validation

- [x] 19. Create `App\Form\ProfileCoachFormType` over `ProfileCoachRequest`:
  `bio` (`TextareaType`, `required: false`, `Length(max: 4000)`),
  `credentials` (`TextareaType`, `required: false`, `Length(max: 2000)`),
  `certifications` (`TextareaType`, `required: false`, `Length(max:
  2000)`), `isPublic` (`CheckboxType`, `required: false`). **No `NotBlank`
  constraint anywhere** — that absence is AC-16. No `email`, `role`, or
  `createdAt` field on the type — that absence is AC-14. (AC-11, AC-12,
  AC-13, AC-14, AC-16)
- [x] 20. Confirm (no code change expected; this task is a verification
  step, not a build step) that S4's `AvailabilityWeekFormType`,
  `DayAvailabilityFormType`, `TimeRangeFormType`, and
  `MinutesFromMidnightTransformer` are reusable verbatim with no player
  coupling — reread each file to re-verify the architecture's claim before
  wiring `Coach\AvailabilityController` to them, since any coupling found
  here would change Task 21's design. If none is found (expected outcome),
  proceed with Task 21 using them unmodified. (AC-1)

## Controllers

- [x] 21. Create `App\Controller\Coach\AvailabilityController::edit()` at
  `GET|POST /coach/availability` (route `app_coach_availability`,
  `#[IsGranted('ROLE_COACH')]`). Converts between
  `AvailabilityWeekFormType`'s submitted shape and `WeeklyAvailability`
  using the same two private helpers `Player\AvailabilityController` uses,
  then calls `CoachAvailabilityService::weekFor()` / `::replaceWeek()`.
  On success, flashes a confirmation naming that the coach's trainer(s) can
  see this schedule (AC-4's exact wording requirement). No `security.yaml`
  change needed — the existing `^/` catch-all firewall already covers this
  route. (AC-1, AC-2, AC-3, AC-4)
- [x] 22. Add `editCoach()` to the existing `App\Controller\ProfileController`
  at `POST /profile/coach` (route `app_profile_edit_coach`), mirroring
  `editBusiness()` line for line: guards with `if (UserRole::COACH !==
  $user->getRole()) throw $this->createAccessDeniedException(...)`, builds
  `ProfileCoachFormType` bound to a `ProfileCoachRequest` built from
  `ProfileRepository::findCoachProfile($this->getUser())` (nullable — D1c),
  calls `ProfileService::updateCoachDetails()`, redirects back to
  `app_profile_edit`. Operates only on `$this->getUser()`, never a
  request-supplied id — this is what makes AC-15's "no access to edit
  another user's fields through any route this slice adds" true by
  construction. (D5a, AC-11, AC-12, AC-13, AC-14, AC-15, AC-16)
- [x] 23. Add the coach branch to the existing `ProfileController::edit()`:
  when `UserRole::COACH === $user->getRole()`, build `ProfileCoachFormType`
  from `ProfileRepository::findCoachProfile($user)` and pass it to the
  template as `coachForm`, beside the existing trainer branch (unedited).
  No email/role/account-created-date field exists on this form (AC-14 by
  absence, matching the trainer branch's own pattern). (D5a, AC-11, AC-14)

## Trainer-facing summary — the flagged concurrent-edit task

- [x] 24. **Blocking gate — do not start until TASK-004 is fully merged and
  green.** Re-check `tasks/TASK-004/writing-plans-plan.md` immediately
  before starting this task: all 47 of its tasks (in particular Task 46's
  review pass and Task 47's full regression) must be `[x]`. As of this
  plan's writing, 45/47 are done and 46/47 are open, so this risk is
  **pending**, not cleared — do not treat this gate as satisfied without
  re-reading that file's current state. Once clear: add
  `summarizeWeek(WeeklyAvailability $week, int $maxDays = 3): string` to
  the existing `App\Service\AvailabilitySummaryFormatter` (S4), holding the
  day-label/range-formatting/"+N more" logic. Change the existing
  `summarize(list<PlayerAvailabilitySlot> $slots, int $maxDays = 3)` into a
  two-line adapter that groups its slots into a `WeeklyAvailability` and
  delegates to `summarizeWeek()` — **its signature does not change** and
  every existing S4 caller/test must remain valid with **zero test edits**.
  Run S4's full availability test suite immediately after this change and
  confirm it is still 100% green with no edits, before writing Task 26's
  new coach caller. (D2d, Risk: "AvailabilitySummaryFormatter is an S4 file
  this slice extends while TASK-004 is in flight", AC-5)
- [x] 25. Unit test,
  `tests/Unit/Service/AvailabilitySummaryFormatterTest.php` (extended):
  add a regression assertion that `summarize()`'s output for the same
  `PlayerAvailabilitySlot` fixture data used in S4's existing tests is
  byte-identical before and after Task 24's extraction — this is the test
  that turns "behavior-preserving by construction" into a checked fact
  rather than a claim. (D2d)
- [x] 26. Extend `App\Controller\Trainer\CoachController::index()` (the
  existing `/trainer/coaches` roster): batch-load each roster coach's
  availability via `CoachAvailabilitySlotRepository::findForCoaches()`
  (one query for the page, no N+1) and render
  `AvailabilitySummaryFormatter::summarizeWeek()` per card.
  `findActiveFor($trainer)` already scopes the roster to actively
  associated coaches, so the negative half of AC-5 ("a trainer with no
  active association cannot see that coach's availability") is the
  roster query's own `WHERE`, not an added filter. A coach with no rows
  renders the formatter's explicit "No availability set" string, never a
  blank cell (edge case 2). No new route, and no trainer-facing detail
  page (D5b — deferred, AC-9 is Epic-02's). (D5b, AC-5)

## Tests

- [x] 27. Functional — **coach availability**:
  `tests/Functional/CoachAvailabilityTest.php`. A signed-in coach sets two
  ranges on one day and ranges on two other days, saves, and reads them
  back (AC-1); a day left empty stores zero rows and renders as not
  available (AC-1); saving twice with different ranges leaves exactly the
  second set with no duplicate rows for any day (AC-2); a coach's save
  leaves another coach's rows and **every** `player_availability_slot` row
  untouched, asserted by row count and content (AC-3); the post-save flash
  names that the trainer(s) can see this schedule (AC-4); a trainer, a
  player, and a Super Admin each get a **403** — not a redirect — on both
  `GET` and a forged `POST` to `/coach/availability` (AC-15, forged-request
  edge case). (AC-1, AC-2, AC-3, AC-4, AC-15)
- [x] 28. Functional — **trainer view of coach availability**:
  extend `tests/Functional/CoachAvailabilityTest.php` or a sibling file. The
  trainer's `/trainer/coaches` page shows the summary string for an
  actively associated coach (AC-5); shows the explicit "no availability
  set" state for a coach who never saved any (edge case 2); after that
  coach's `TrainerCoachAssociation` ends, the former trainer sees neither
  the summary nor the "no availability set" state — the card/row for that
  coach carries no availability data at all (AC-5, edge case 3). (AC-5)
- [x] 29. Functional — **coach profile**:
  `tests/Functional/CoachProfileTest.php`. A coach edits bio, credentials,
  certifications, and the checkbox and reads them back after a round trip
  (AC-11, AC-12, AC-13); the very first save creates the `profile_coach`
  row for a coach account that had none (D1c); an all-blank submit succeeds
  and stores nulls (AC-16); a whitespace-only credentials value stores as
  `null`, not as spaces (edge case 5); the form renders no email/role/
  created-date field, and a forged submit carrying those parameters changes
  none of them (AC-14); a trainer, a player, and a Super Admin each get a
  403 from `POST /profile/coach` (AC-15); a coach with no saved profile
  renders the visibility checkbox unchecked (AC-16). (D1c, AC-11, AC-12,
  AC-13, AC-14, AC-15, AC-16)
- [x] 30. Unit test, `tests/Unit/Availability/CoverageEvaluatorTest.php`:
  the full matrix — candidate inside one range, exactly equal to one range,
  spanning two normalized ranges with a gap, starting before and ending
  inside a range, touching a range only at an endpoint (⇒ `UNAVAILABLE`,
  the deliberate asymmetry with `overlapsOrTouches()`), a day with no
  ranges at all, and both the 0 and 1440 minute boundaries. (AC-6)
- [x] 31. Unit test, `tests/Unit/Dto/ProfileCoachRequestTest.php`:
  whitespace-only and empty-string inputs for bio/credentials/certifications
  all normalize to `null`; non-blank input is trimmed and preserved.
  (AC-16, edge case 5)
- [x] 32. Unit test, `tests/Unit/Security/Voter/CoachVoterTest.php`:
  parameterized over every role × active/deactivated × self/associated/
  unassociated combination, matching `ShareLinkVoterTest`'s data-provider
  shape, including the explicit assertion that `ROLE_SUPER_ADMIN` grants
  nothing on any of the three attributes (the flat-`role_hierarchy`
  invariant this slice relies on for AC-15). (D4, AC-5, AC-15)
- [x] 33. Integration/service test, against the real database,
  `tests/Service/CoachAssignmentOverrideServiceTest.php`: `record()`
  refuses an empty and a whitespace-only reason with
  `MissingOverrideReasonException` and inserts nothing (AC-7); two rapid
  `record()` calls for the same coach/trainer pair produce **two** rows,
  not one (edge case 4); `findForCoach()`/`findForTrainer()` return them
  newest-first with reason, coverage, and candidate time intact (AC-8);
  `record()` stores the coverage it itself evaluated, not a value the
  caller passed; `record()` refuses when `$trainer` has no active
  association to `$coach`. (AC-7, AC-8, edge case 4)
- [x] 34. Repository/schema integration test, against the real database,
  `tests/Repository/CoachFeaturesConstraintsTest.php`: the
  `btrim(reason) <> ''`, `day_of_week`, and `starts_at_minute < ends_at_minute`
  CHECKs on `coach_assignment_override` each refuse a direct bad insert; the
  equivalent CHECKs on `coach_availability_slot` each refuse a direct bad
  insert; `UNIQUE (user_id, type)` on `profile` refuses a second
  `ProfileCoach` row for one user; `doctrine:schema:update --dump-sql`
  reports nothing to update on a **second** run. (D2, D3, D3d, AC-7)
- [x] 35. Repository integration test asserting the risk named in the
  architecture's "two availability tables will drift" note:
  `tests/Repository/AvailabilityTableColumnParityTest.php` compares
  `player_availability_slot`'s and `coach_availability_slot`'s column sets
  and asserts they remain identical — turning future drift into a failing
  test rather than a silent surprise; deleting this test later is the
  explicit decision to allow divergence, not an accident. (Risk: "Two
  availability tables will drift")
- [x] 36. Repository integration test for the override-log-outlives-
  association risk named in the architecture's Risks section: a
  `CoachAssignmentOverride` row written while a `TrainerCoachAssociation`
  is active remains fully readable (coach, trainer, reason, coverage, time)
  after that association ends — the trainer identity is stored directly on
  the override row, not derived through the association, so ending it must
  not orphan or obscure the audit record. (Risk: "The override log has no
  reader in this slice… a coach changes trainers between the override and
  the audit")

## Review and verification

- [x] 37. `code-reviewer` + `security-reviewer` pass over the full slice,
  with explicit attention to: the `Profile` discriminator-map edit
  (confirm it is the only line touched in that file), the `ProfileCoach`
  JOINED-inheritance mapping, both new CHECK-bearing tables, the
  `CoachVoter` truth table against the flat `role_hierarchy`, and — because
  Task 24 is this slice's one deliberately sequenced file edit — a specific
  diff review of `AvailabilitySummaryFormatter` confirming `summarize()`'s
  signature and behavior are unchanged.
- [x] 38. Full regression: `bin/phpunit` — S1's AC-1…AC-25, S2's AC-1…AC-24,
  S3's AC-1…AC-21, and S4's AC-1…AC-24 must still hold, with particular
  attention to S2's profile-edit tests (the trainer's business form and the
  player's common form must be unchanged by the new coach branch) and S4's
  full availability suite (must pass with zero test edits, re-confirming
  Task 24's requirement one more time at the end of the slice, not only
  right after the extraction). Final run: 597 tests, 2210 assertions, 596
  green. The one remaining failure
  (`AccountLifecycleFlowTest::testTwoConcurrentDeletesForTheSameAccountYieldExactlyOneSuccess`)
  is a confirmed sandbox subprocess-spawning limitation, unrelated to S5. A
  second, genuinely-fixable S4 test-hygiene bug surfaced during this task's
  review (`FamilyAndAvailabilityControllersTest::testChildNewFormRendersAndCreatesAChild`
  created a child `User` via the controller without tracking its id for
  cleanup, leaking one row per run into `app_test` and deterministically
  breaking `ShareLinkRegistrationSourceThrottleTest`'s whole-table row-count
  assertion) — fixed directly in that file's `tearDown()` (now sweeps every
  `child_account.child_user_id` in addition to explicitly tracked ids), not
  left as a known issue. Re-run after the fix: 597/597 green except the one
  sandbox-only failure above.

## Coverage check

**Every AC cited by at least one task** (mechanically re-derived from the
`(AC-N, ...)` citations actually printed in each task above):
AC-1: 2, 3, 9, 10, 20, 21, 27, 28. AC-2: 2, 3, 10, 11, 21, 27.
AC-3: 2, 3, 11, 21, 27. AC-4: 7, 11, 21, 27.
AC-5: 3, 5, 18, 24, 26, 28, 32. AC-6: 4, 9, 10, 14, 30.
AC-7: 4, 5, 14, 15, 16, 21(via service, not route), 33, 34.
AC-8: 4, 5, 7, 16, 17, 33. AC-9: (spec-level, deferred — Epic-02; named only
in the spec's own text, cited here for completeness, not built by any task).
AC-10: (spec-level, deferred — Epic-02; same treatment as AC-9).
AC-11: 1, 6, 12, 19, 22, 23, 29. AC-12: 1, 12, 19, 22, 23, 29.
AC-13: 1, 12, 19, 22, 23, 29. AC-14: 19, 22, 23, 29.
AC-15: 11, 18, 22, 27, 29, 32. AC-16: 12, 13, 19, 29, 31.

Every one of AC-1…AC-8 and AC-11…AC-16 (the slice's buildable ACs, per the
spec's own "done" definition) is cited by at least one task. AC-9 and AC-10
are the spec's own explicitly deferred-to-Epic-02 criteria — this plan
builds no task against them, which matches the spec's statement that
"AC-9 and AC-10 are deliberately excluded from this slice's 'done'... they
are Epic-02's."

**Every task cites at least one real AC, or a named Decision/Risk:** true
for all 38 tasks above. Six deliberately cite a Decision or Risk instead of
an AC because they are schema/infrastructure/gate tasks with no criterion
of their own: Task 8 (migration mechanics — D1, D1c, D2, D3, D3b), Task 9
(the value-layer placement choice — D2c), Task 24 (the flagged concurrent-
edit gate — D2d and the named Risk), Task 35 and Task 36 (the two Risks the
architecture's Risks section names but no AC covers), and Task 37 (the dual
review pass — a review gate over the whole slice, not a change with its own
criterion, the same shape as TASK-004's Task 46).

**The concurrent-edit sequencing risk is addressed by a named task, not
left implicit:** Task 24 is that task. As re-checked against
`tasks/TASK-004/writing-plans-plan.md` while writing this plan, TASK-004 is
at 45/47 tasks complete with Tasks 46 (review) and 47 (regression) still
open — so this risk is **pending, and Task 24 must not be started** until a
fresh check of that file shows all 47 as `[x]`.

**No gap found in either direction** during this planning pass: every
buildable AC (AC-1…AC-8, AC-11…AC-16) is claimed by at least one task, every
task above cites at least one AC or names the Decision/Risk it protects, and
the deferred ACs (AC-9, AC-10) are named rather than silently dropped,
matching the spec's own treatment of them.
