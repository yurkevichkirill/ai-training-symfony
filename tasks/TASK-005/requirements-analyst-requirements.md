# Coach Features (Epic-01, slice S5) Requirements

## Overview

Epic-01 slice S5 covers the remaining coach-facing scope from
`Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` not already shipped in S1-S4:

- US-01.10 "Coach Sets My Times (Availability)" — coach's own weekly recurring
  availability, plus the trainer-assignment conflict-warning-with-override flow.
- The coach-specific slice of US-01.11 "User Edits Own Profile" — bio, credentials,
  certifications, public-profile-visibility checkbox.

US-01.08 "Trainer Invites Coach" is explicitly OUT of scope here — it shipped in S3
(`CoachInvitation`, `TrainerCoachAssociation`, exclusivity).

## Source

- `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` — US-01.10, US-01.11
  (coach row), §8 "For Coach Profiles", §9 "Coach Availability Conflicts", Flow (none
  dedicated — covered under Epic-level AC "Coach Workflows").
- `specs/MANIFEST.md` and all four existing spec pairs (auth-foundation, user-management,
  sharelink-invitations, player-family-availability) — read for conventions and frozen
  entities.
- Ground-truth source scan: no `ProfileCoach` (or any Coach-specific profile) entity
  exists in `src/Entity`. Coaches today are represented only as a `User` with
  `UserRole::COACH` plus a `TrainerCoachAssociation` row; S2's `Profile` hierarchy has
  `Profile` (abstract), `ProfileTrainer`, and S3 added `ProfilePlayer` — no coach subtype.
- `src/Availability/WeeklyAvailability.php` and `TimeRange.php` (S4) are plain immutable
  value objects with no Doctrine dependency — reusable as-is for representing a coach's
  weekly schedule shape, but S4's persistence (`PlayerAvailabilitySlot`,
  `player_availability_slot`) is keyed to a player and is not reusable storage for a
  coach (different owner FK, different entity, and semantically a coach's availability is
  a *recurring template*, not an absence-inclusive slot set the same way — flagged for the
  architecture phase to confirm/refute).

## Functional Requirements

1. **FR-038: Coach defines weekly recurring availability.**
   - Acceptance: A signed-in coach can, from a "My Times"/"Availability" page, set one or
     more time ranges per weekday (e.g., "Monday 4-6pm AND 7-9pm"); saving replaces that
     coach's stored weekly schedule.
   - Priority: High

2. **FR-039: Trainer-assignment conflict warning + override (buildable half only).**
   - Acceptance: the *mechanism* for evaluating "does time X conflict with coach Y's saved
     availability" and recording an override decision (event_id, coach_id, override_reason,
     overridden_by, timestamp) must exist as a reusable service capability, because Epic-02
     (Event Management) does not exist yet in this codebase and "assigning a coach to an
     event" has no real caller today. This FR is split explicitly in the spec into a
     buildable slice (coach-availability lookup + an override-log table/service) and a
     deferred slice (the actual event-assignment UI/flow), rather than inventing an Events
     system to exercise it end-to-end.
   - Priority: Medium (buildable half); the event-assignment half is blocked, not merely
     lower priority.

3. **FR-040: Coach profile fields — bio, credentials, certifications, public visibility.**
   - Acceptance: a signed-in coach can edit bio (free text), credentials (free text or
     structured list — architecture decision), certifications (free text or structured
     list), and toggle a "public profile" visibility checkbox, following the same
     read-only-field rules (email/role uneditable) as every other role's profile edit in
     US-01.11.
   - Priority: High

4. **FR-041: Coach profile entity / storage.**
   - Acceptance: since no Coach profile subtype exists, this slice must decide (spec-level:
     flag it; architecture-level: resolve it) whether these fields live on a new
     `ProfileCoach extends Profile` (matching S2's frozen `Profile`/`ProfileTrainer`
     pattern and S3's `ProfilePlayer` precedent) or elsewhere. The spec states the
     observable requirement only; entity shape is deferred to architecture, consistent with
     how S4 handled its own equivalent open question (G-07).
   - Priority: High

## Non-Functional Requirements

1. NFR-S5-01: Coach availability edits and profile edits meet the epic's general
   performance targets (<1s profile save) — no new target introduced.
2. NFR-S5-02: No new Composer package assumed necessary (matches S1-S4 precedent);
   flagged for architecture to confirm.

## Business Rules

1. BR-S5-01 (from epic §9): "Coaches set weekly availability (recurring schedule)."
   Distinct semantics from S4's player Best Times, which store absence-inclusive slots
   per specific day; a coach's schedule is a template with no per-instance override baked
   in — the override lives on the (deferred) assignment-time check, not on the schedule
   itself.
2. BR-S5-02 (from epic §9): "When trainer assigns coach to conflicting time: system shows
   warning... trainer can override with required reason... override is logged... coach
   sees assignment (not blocked), can accept or request change." The "accept or request
   change" half also depends on a notification/assignment UI this codebase has no host
   for yet (Epic-02) — flagged as a further boundary beyond just the override log.
3. BR-S5-03 (from epic §8): coach profile carries "which trainer they work for (ONE
   trainer only)" — already enforced by S3's `TrainerCoachAssociation` partial unique
   index; this slice does not re-touch that mechanism, only adds the bio/credentials/
   certifications/visibility fields.

## Task Breakdown (indicative — spec phase, not a commitment)

### Entities
| Entity | Properties | Relations |
|--------|------------|-----------|
| ProfileCoach (tentative) | bio, credentials, certifications, isPublic | extends Profile (1:1 User) |
| CoachAvailabilitySlot (tentative) | coach_id, weekday, start, end | belongs to User (coach) |
| CoachAssignmentOverride (tentative, buildable-service-only) | coach_id, trainer_id, reason, created_at, (event_id nullable/deferred) | belongs to User (coach), User (trainer) |

### Services
| Service | Purpose | Methods |
|---------|---------|---------|
| CoachAvailabilityService (tentative) | store/read coach weekly schedule | replaceWeek, weekFor, conflictsWith |
| CoachProfileService or extension of existing profile-edit service | edit coach-specific fields | updateCoachProfile |

### Controllers
| Controller | Endpoints | Purpose |
|------------|-----------|---------|
| Coach\AvailabilityController (tentative) | GET/POST /coach/availability | coach's own weekly schedule CRUD |
| Coach\ProfileController or existing ProfileController extension | GET/POST /profile | coach-specific fields |

## Gap Analysis

- [ ] Whether coach availability needs "not available" explicit rows (S4 pattern) or is
  purely additive ranges (epic's own examples are purely additive: "Monday: 4-6pm AND
  7-9pm", no "not available" language for coaches) — flag for spec resolution.
- [ ] Whether `credentials`/`certifications` are free text or a structured repeatable
  list — epic doesn't specify; flag as an open question for the spec, not blocking.
- [ ] Confirm Epic-02 does not exist in this codebase (confirmed: no `Event` entity found
  in prior spec reads and no Epic-02 spec files) — the event-assignment half of US-01.10
  must be explicitly deferred.

## Next Steps (Suggested)

Do not auto-execute — presented for the calling agent to choose.
