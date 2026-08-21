# Spec: Coach Features (Epic-01, slice S5)

> Naming note: filed as `sdd-coach-features-spec.md` to satisfy this repo's
> `.claude/hooks/file-naming-validator.sh` file-naming convention, matching S2-S4's
> `sdd-*` pattern. The feature slug is `coach-features` everywhere else (this file's
> body, the architecture file to follow, and its `specs/MANIFEST.md` row).
>
> Scope: **slice S5 only**, covering exactly two items from
> `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`: US-01.10 ("Coach Sets My
> Times (Availability)" — the coach's own weekly recurring schedule, plus the
> trainer-assignment conflict-warning-with-override flow) and the coach-specific slice of
> US-01.11 ("User Edits Own Profile" — bio, credentials, certifications, public-profile
> visibility checkbox). Source: §7 (those two stories), §8 "Data Requirements" → "For
> Coach Profiles" and "For Coach Availability Overrides", §9 "Best Times / Availability"
> and "Coach Availability Conflicts". Cross-checked against
> `tasks/TASK-005/requirements-analyst-requirements.md`'s FR-038…FR-041, BR-S5-01…BR-S5-03.
>
> **US-01.08 ("Trainer Invites Coach") is explicitly NOT re-scoped here** — it shipped in
> S3 (`CoachInvitation`, `TrainerCoachAssociation` with its partial-unique exclusivity
> index). This slice only adds what S3 left out: the coach's own availability and the
> coach-specific profile fields.
>
> Builds on four shipped, frozen slices: `specs/auth-foundation-spec.md` (S1: `User`,
> single `UserRole::COACH`), `specs/sdd-user-management-spec.md` (S2: frozen
> `Profile`/`ProfileTrainer` JOINED hierarchy — no coach subtype),
> `specs/sdd-sharelink-invitations-spec.md` (S3: `TrainerCoachAssociation` with
> `UNIQUE(coach_id) WHERE ended_at IS NULL` enforcing one-trainer-at-a-time), and
> `specs/sdd-player-family-availability-spec.md`/`-architecture.md` (S4: the
> `App\Availability\WeeklyAvailability`/`TimeRange` plain value objects, and the
> `player_availability_slot` persistence pattern this spec deliberately does **not**
> reuse as storage — see "Ground truth confirmed").
>
> This document answers *what* and *why*. The design will live in
> `specs/sdd-coach-features-architecture.md` (not yet written).

## Ground truth confirmed against source

- **No Coach profile entity exists.** `src/Entity/Profile.php` (abstract, frozen) has
  exactly two concrete subtypes today: `ProfileTrainer` (S2) and `ProfilePlayer` (S3).
  There is no `ProfileCoach`. A coach today is only a `User` row with
  `UserRole::COACH` plus, once invited and accepted, a `TrainerCoachAssociation` row. Bio,
  credentials, certifications, and public-visibility have nowhere to live yet.
- **`App\Availability\WeeklyAvailability` and `TimeRange` (S4) are plain, Doctrine-free
  value objects** — reusable as-is to *represent* a coach's weekly schedule shape. S4's
  *storage* entity, `PlayerAvailabilitySlot` / `player_availability_slot`, is keyed to a
  player (`player_id` FK into `app_user` via the player side of the relationship) and
  built around S4's "absence-as-Not-Available" semantics for filtering players by
  Best Times. A coach's schedule is a different owner and a different intended read
  pattern (conflict-checking against a single assignment time, not roster filtering
  across many players) — this spec does not assume the same table serves both; that
  reuse-vs-new-table question is explicitly left to the architecture phase (see Open
  questions), while the *value objects* are confirmed reusable regardless of that answer.
- **No Event/Event-Management entity exists anywhere in this codebase.** A repo-wide
  scan of `src/Entity` finds no `Event`, no scheduling/session entity, and no Epic-02 spec
  file under `Task/Epics/` or `specs/`. "Trainer assigns coach to event" (the second half
  of US-01.10) therefore has no real subject to assign *to* — there is no event to check
  a time against, no roster to attach the coach to, and no UI surface an assignment flow
  would hang off. This is the central scope boundary this spec draws (see "In scope vs.
  deferred" and Out of scope).
- **`TrainerCoachAssociation`'s one-trainer-at-a-time exclusivity is already enforced**
  (S3, partial unique index on `coach_id` where `ended_at IS NULL`). This slice does not
  touch that mechanism.

## Problem

A coach can be invited, register, and be associated with exactly one trainer (S3), but
once inside the platform a coach has no way to tell that trainer when they're available,
and no way to present anything about themselves beyond the bare account fields every role
shares (name, phone, photo — from S2). The epic's own "Coach Workflows" acceptance list
promises a coach can "set My Times (weekly availability)," that a trainer sees "an
availability conflict warning when assigning coach" and "can override with logged
reason," and that a coach "can edit own profile (bio, credentials)." None of this exists
today. Half of it cannot exist for real yet: assigning a coach *to an event* presumes an
Events system (Epic-02) this codebase does not have. This slice builds everything that
does not depend on Epic-02, and draws an explicit, named line around the piece that does.

## In scope vs. deferred (read this before the acceptance criteria)

**Buildable now, in this slice:**
- A coach's own weekly recurring availability: CRUD, storage, a "my times" view.
- A reusable capability to check "does time range X on weekday Y conflict with coach Z's
  saved availability" — a pure query/service capability, exercised by this slice only
  through its own tests and (optionally) a trainer-facing read of a coach's saved
  schedule, not through any real assignment flow.
- An override *record* mechanism (who overrode, which coach, why, when) that a *future*
  Epic-02 slice can call — built and tested against a synthetic/manual trigger (e.g. a
  console command or an internal service call in this slice's own tests), not against a
  real "assign coach to event" UI, because no such UI's subject exists.
- Coach-specific profile fields: bio, credentials, certifications, public-profile
  visibility checkbox, and whatever storage they need.
- Trainer-facing read of a coach's "My Times" summary (parallel to S4's player Best Times
  summary), since a trainer can already see a coach they're associated with via
  `TrainerCoachAssociation` — no Events system needed to view a coach's own saved
  schedule.

**Explicitly deferred, pending Epic-02 (Event Management):**
- Any real "assign coach to event" action, button, or route.
- The actual moment a trainer sees "Coach [Name] is not available at this time per their
  schedule. Continue anyway?" in context of a specific event being created/edited —
  there is no event-creation surface to attach this warning to.
- "Coach sees assignment (no blocking), can accept or request change" — there is no
  assignment for a coach to see or respond to.
- Any `event_id` column or FK — the override-record mechanism this slice builds must
  work with `event_id` deferred/nullable or entirely absent from this slice's own schema,
  to be filled in when Epic-02 exists (an architecture decision, not resolved here).

This split is the single most important scope decision in this document. Every AC below
is tagged **[buildable]** or **[deferred — Epic-02]** so the boundary cannot be missed
during implementation planning.

## User scenarios

1. **A signed-in Coach** sets their weekly recurring availability.
   Path: "My Times"/"Availability" → a weekday-by-weekday view → for each day, add one or
   more time ranges (e.g., "Monday 4:00 PM–6:00 PM" and "Monday 7:00 PM–9:00 PM") → save
   → confirmation. **[buildable]**

2. **A signed-in Coach** edits their own profile's coach-specific fields.
   Path: "Profile"/"Account Settings" → bio (free text), credentials, certifications,
   and a "Make my profile public" checkbox, alongside the common fields (name, phone,
   photo) every role already has from S2 → save → confirmation; email and role stay
   read-only, matching every other role's profile-edit behavior. **[buildable]**

3. **A Trainer** looks at a coach they are associated with and sees that coach's saved
   weekly availability as a short summary. **[buildable]**

4. **A Trainer** attempts to assign a coach to a specific time and the system needs to
   warn them if that time falls outside the coach's saved availability, let them
   override with a required reason, and log the override. **[deferred — Epic-02, except
   for the underlying conflict-check and override-log capability, which is buildable and
   is scenario 5]**

5. **Something in this codebase** (a future Epic-02 caller, exercised in this slice only
   by its own tests/console tooling) calls a conflict-check-and-override-log capability
   directly, without any event existing, to prove the capability itself is correct and
   ready for Epic-02 to call. **[buildable]**

## Acceptance criteria

**Coach weekly availability (US-01.10, buildable half)**

- [ ] **AC-1** [buildable] A signed-in coach can set, for each day of the week, one or
  more specific time ranges (e.g., "Monday 4:00 PM–6:00 PM AND 7:00 PM–9:00 PM"); a day
  with no time range set means the coach is not available that day. (FR-038)
- [ ] **AC-2** [buildable] Saving a coach's weekly availability replaces that coach's
  previously saved schedule for any day included in the submission; it never appends
  duplicate ranges for the same day across repeated saves. (FR-038)
- [ ] **AC-3** [buildable] A coach's saved availability belongs to that coach alone —
  saving it does not read, alter, or depend on any other coach's, any player's, or any
  parent/child's saved availability (S4's `player_availability_slot` rows are untouched
  by this slice). (FR-038)
- [ ] **AC-4** [buildable] After saving, the system confirms with a message that the
  trainer(s) they work with can see this schedule. (FR-038)
- [ ] **AC-5** [buildable] A trainer viewing a coach they are actively associated with
  (via `TrainerCoachAssociation`) can see a short summary of that coach's saved
  availability (e.g., "Mon 4-6pm, 7-9pm; Sat 9am-12pm"). A trainer with no active
  association to a given coach cannot see that coach's availability. (FR-038, mirrors
  S4's AC-22 pattern for players)

**Conflict-check-and-override capability (US-01.10, buildable half of the assignment flow)**

- [ ] **AC-6** [buildable] A reusable capability exists that, given a coach and a
  candidate time range, reports whether that time range falls entirely within, partially
  within, or entirely outside that coach's saved availability. (FR-039)
- [ ] **AC-7** [buildable] A reusable capability exists to record an override decision —
  which coach, which trainer overrode, the required non-empty reason text, and when —
  whenever a conflict (from AC-6) is overridden; recording without a reason is refused.
  (FR-039, BR-S5-02's "with a required reason")
- [ ] **AC-8** [buildable] Every override recorded by AC-7 is queryable later (who
  overrode which coach's availability, when, and why) — the mechanism a compliance/audit
  view could read, matching this platform's established pattern (S1's auth events, S2's
  account events) of never silently discarding a sensitive decision. (FR-039)
- [ ] **AC-9** [deferred — Epic-02] A trainer is shown "Coach [Name] is not available at
  this time per their schedule. Continue anyway?" at the moment of assigning a coach to
  an actual event, and can only proceed past it by supplying a reason (which triggers
  AC-7). Not built in this slice — there is no event to assign to; see "In scope vs.
  deferred" and Out of scope. This AC exists only to name the epic's literal requirement
  and mark it explicitly as blocked, not silently dropped.
- [ ] **AC-10** [deferred — Epic-02] A coach sees an event assignment made against a
  conflicting time (not blocked from it) and can accept or request a change. Not built in
  this slice for the same reason as AC-9.

**Coach profile fields (US-01.11, coach-specific slice)**

- [ ] **AC-11** [buildable] A signed-in coach can edit a free-text bio, alongside the
  common profile fields (name, phone, photo) every role already has. (FR-040)
- [ ] **AC-12** [buildable] A signed-in coach can edit credentials and certifications
  information (structure — single free-text block vs. a repeatable list — is an
  architecture decision; see Open questions). (FR-040)
- [ ] **AC-13** [buildable] A signed-in coach can toggle a "public profile" visibility
  checkbox; its saved state is readable later by whatever will render a public coach
  profile (no public-facing rendering surface is designed or built in this slice — the
  checkbox's persisted value is the whole of this slice's obligation). (FR-040)
- [ ] **AC-14** [buildable] Saving coach profile fields follows the same read-only-field
  rule every other role's profile edit already follows: email, role, and account-created
  date remain uneditable through this form. (FR-040, matches US-01.11's common rules)
- [ ] **AC-15** [buildable] A non-coach user (trainer, player, super admin) has no access
  — server-side, not merely UI-hidden — to edit or view another user's bio, credentials,
  certifications, or visibility checkbox through any route this slice adds. (FR-040,
  FR-041)
- [ ] **AC-16** [buildable] Submitting the coach profile form with fields left blank
  (bio, credentials, certifications) succeeds — none of these fields is required; only
  the visibility checkbox has a default state (off, i.e. not public) when nothing has
  ever been saved. (FR-040)

## Edge cases

| Case | Expected |
|---|---|
| A coach saves a day's availability with overlapping or touching time ranges (e.g., "4-6pm" and "5-7pm") | Normalized/merged into non-overlapping ranges before storage, mirroring S4's `WeeklyAvailability::normalized()` behavior for the same value object family. |
| A coach who has never saved availability | Trainer's summary view shows an explicit "no availability set" state, never a blank/empty-looking summary indistinguishable from "available nowhere." |
| A coach's `TrainerCoachAssociation` ends (coach leaves/removed) after a trainer already saw their availability | The now-former trainer loses read access to that coach's availability (AC-5's association-gated visibility applies continuously, not just at initial fetch). |
| Two overlapping override-log writes for the same coach/trainer pair in quick succession | Both persist as separate override records (AC-8) — this is an audit log, not a single mutable "current override" state; no dedup or overwrite. |
| A coach submits a credentials/certifications value with only whitespace | Treated as empty (no value saved), consistent with AC-16's "none of these fields is required." |
| A trainer or admin tries to call a coach-availability-editing route for a coach they are not that coach (forged request) | Refused per AC-15, regardless of how the request was made. |
| Epic-02 is later built and needs to call AC-6/AC-7's capability with a real `event_id` | This slice's service surface must not hard-block that extension (e.g., an optional/nullable event reference is acceptable to have been designed for, even if unused here) — an architecture-phase decision, not this spec's to make; flagged in Open questions. |

## Out of scope

- **US-01.08 (Trainer Invites Coach)** — already shipped in S3. Not re-scoped, not
  re-verified beyond confirming (above) that its exclusivity mechanism is untouched.
- **Any real "assign coach to event" flow, UI, or route** — depends on Epic-02 (Event
  Management), which does not exist in this codebase. See "In scope vs. deferred" and
  AC-9/AC-10 above, which name this boundary explicitly rather than silently dropping it.
- **A public-facing coach profile page/rendering** — AC-13 persists the visibility
  checkbox's value only; no public route, template, or directory listing coaches by
  visibility is designed here.
- **Coach notification/response UI for an assignment** ("can accept or request change")
  — depends on the same missing Events system as AC-10.
- **Any change to `TrainerCoachAssociation`'s exclusivity mechanism, `CoachInvitation`,
  or the S3 invitation flow** — frozen, out of this slice's reach.
- **Time-zone handling for coach availability** — the epic never mentions time zones for
  availability (same gap S4 flagged for player Best Times); not invented here.
- **Portal branding, player/child Best Times** — unrelated slices (S8, already-shipped S4).

## Open questions

- **Storage reuse vs. new table for coach availability.** `WeeklyAvailability`/`TimeRange`
  (S4's value objects) are confirmed reusable to *represent* a coach's schedule (see
  "Ground truth confirmed"), but whether the *persistence* entity is a new
  `CoachAvailabilitySlot` table or some parameterized reuse of `PlayerAvailabilitySlot`'s
  shape is an architecture decision. This spec's ACs (AC-1…AC-5) describe only observable
  behavior and hold either way.
- **Coach profile entity shape.** No `ProfileCoach` exists today. Whether bio/
  credentials/certifications/visibility live on a new `ProfileCoach extends Profile`
  (matching S2's frozen `Profile`/`ProfileTrainer` pattern and S3's `ProfilePlayer`
  precedent) or are added some other way is squarely an architecture decision — flagged
  here, same treatment S4 gave its own equivalent question (G-07), so the architect does
  not have to rediscover it.
- **Credentials/certifications structure.** Free text vs. a repeatable structured list
  (e.g., name + issuing body + year) — the epic gives no detail beyond naming the fields.
  AC-12 is written to hold under either answer; flagged non-blocking.
- **Override-record forward-compatibility with Epic-02's future `event_id`.** Whether
  this slice's override-log entity should carry a nullable/deferred event reference now,
  or add one via a later migration when Epic-02 lands, is an architecture decision (see
  edge case table, last row). Either answer satisfies AC-7/AC-8 as written.
- **Q-01.06 (carried over from the epic's own open-question log, still open, P2, client)**
  — "Coach availability override: Should coach be notified when overridden?" AC-10's
  "can accept or request change" implies some notification eventually, but with no
  assignment surface to notify *about*, this is not designed here; flagged non-blocking.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-038 Coach weekly recurring availability (CRUD, storage) | AC-1, AC-2, AC-3, AC-4 |
| FR-038 (trainer-facing read) | AC-5 |
| FR-039 Conflict-check + override-log capability (buildable half) | AC-6, AC-7, AC-8 |
| FR-039 (event-assignment half — deferred) | AC-9, AC-10 |
| FR-040 Coach profile fields (bio, credentials, certifications, visibility) | AC-11, AC-12, AC-13, AC-14, AC-16 |
| FR-041 Coach profile entity / access control | AC-14, AC-15 |
| BR-S5-01 Coach schedule is a template, not absence-inclusive slots | AC-1 |
| BR-S5-02 Override requires a reason and is logged | AC-7, AC-8 |
| BR-S5-03 One-trainer-only exclusivity (already enforced, untouched) | Ground truth confirmed section |

Slice S5 is done when AC-1…AC-8 and AC-11…AC-16 hold (the buildable ACs), on top of S1's
AC-1…AC-25, S2's AC-1…AC-24, S3's AC-1…AC-21, and S4's AC-1…AC-24 continuing to hold
(regression, not just addition). AC-9 and AC-10 are deliberately excluded from this
slice's "done" — they are not this slice's to satisfy; they are Epic-02's, and are named
here only so the boundary is explicit rather than silently missing.
