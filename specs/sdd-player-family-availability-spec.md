# Spec: Player/Family Availability (Epic-01, slice S4)

> Naming note: filed as `sdd-player-family-availability-spec.md` (rather than the bare
> `player-family-availability-spec.md` slug given in the delegation) to satisfy this
> repo's file-naming hook (`.claude/hooks/file-naming-validator.sh`), which requires
> spec/architecture Markdown under `specs/` to start with a discovered skill directory
> name — same reason S2's and S3's pairs are `sdd-user-management-*` /
> `sdd-sharelink-invitations-*`. The feature slug stays `player-family-availability`
> everywhere else (this file's body, the architecture file to follow, and its
> `specs/MANIFEST.md` row).
>
> Scope: **slice S4 only**, covering exactly four user stories from
> `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`: US-01.03 (Parent Creates
> Child Profile), US-01.04 (Parent Manages Child-Trainer Associations, including the
> *data* needs behind its context-switching documentation), US-01.06 (Child Login with
> Constraints — the permission ceiling and the ShareLink-blocking + parent-notification
> flow only), and US-01.09 (Player/Parent Sets Availability — "Best Times"). Source:
> §7 (those four stories), §9 "Business Rules & Logic" → "Parent/Child Relationships" and
> "ShareLink Tracking", §10 Flow 2 and Flow 6, §8 "Data Requirements" (Player Profiles,
> Best Times). Cross-checked against `tasks/TASK-001/requirements-analyst-requirements.md`'s
> FR-024…FR-034, BR-010…BR-012, BR-019, and Gaps G-02, G-07, G-16, G-19, G-20, G-25.
>
> Builds on three shipped, frozen slices: `specs/auth-foundation-spec.md` /
> `-architecture.md` (S1: `User` with a single `UserRole` — no separate `PARENT` case; a
> parent is a `PLAYER`-role account, per G-23), `specs/sdd-user-management-spec.md` /
> `-architecture.md` (S2: the frozen `Profile`/`ProfileTrainer` JOINED hierarchy, the
> Users directory, invitation flow, deactivation, GDPR deletion), and
> `specs/sdd-sharelink-invitations-spec.md` / `-architecture.md` (S3: `ProfilePlayer` —
> `playerName`/`declaredAge`/`gender`, no parent/child fields —, `PlayerShareLink`,
> `TrainerPlayerAssociation` with `UNIQUE(trainer_id, player_id) WHERE ended_at IS NULL`
> and a self-service `leave()`, `CoachInvitation`, `TrainerCoachAssociation`, the
> registration flow, and ShareLink-click handling for logged-out/logged-in players).
> Verified directly against the current `src/Entity/User.php`, `Profile.php`,
> `ProfilePlayer.php`, `TrainerPlayerAssociation.php` and
> `src/Controller/Player/TrainerRosterController.php`,
> `src/Controller/PlayerShareLinkController.php`, `src/Controller/ProfileController.php` —
> not assumed from the architecture docs alone (see "Ground truth confirmed" below).
> This document answers *what* and *why*. The design lives in
> `specs/sdd-player-family-availability-architecture.md` (not yet written).

## Ground truth confirmed against source

- **`ProfilePlayer` carries no parent/child distinction and no link to a parent
  account.** Its only fields are `playerName`, `declaredAge`, `gender`. Nothing in S1–S3
  distinguishes "this player is a child" or "this player belongs to that parent."
- **`User` carries no child-account marker and no role constraint beyond the single
  `UserRole` scalar.** `UserRole` has exactly `SUPER_ADMIN`, `TRAINER`, `COACH`, `PLAYER` —
  no `PARENT`, no "child" flag anywhere.
- **`TrainerPlayerAssociation.player_id` is a mandatory, non-nullable FK to `app_user.id`**
  (frozen S3 schema; not to be altered). Any player-side row this slice creates —
  including one for a child — must resolve to a real `User` row to reuse this table
  without a schema change to a frozen entity.
- **Consequence, stated but not resolved here (see Open questions):** whichever concrete
  shape S4 assigns to "a child" (a new columns/table, or reuse of `User`/`ProfilePlayer`
  with new markers) is an architecture decision. This spec's acceptance criteria describe
  only the observable behavior a parent, a child, and a trainer must see — not the schema.

## Problem

S3 gave a Player a way onto the platform and a way to connect to more than one Trainer,
but every one of its acceptance criteria was written for **an adult acting for themselves**
— it explicitly deferred "the Parent/Child family half" of its own multi-trainer story
(see `sdd-sharelink-invitations-spec.md`'s Out of scope). The epic's own rule is that
**all players under 18 require a parent-managed account** (§9, carried forward as
resolved — see Open questions on the epic's internal contradiction about this). Until this
slice, there is no way for that rule to be true: a parent has no way to create a profile
for a child, no way to connect that child to a trainer or remove them from one, no way to
set availability separately per child, and a child who somehow reached a sign-in has no
constraint at all stopping them from doing anything an adult player could do — including
things the business rules say a child must never be able to do (add a trainer, change a
trainer association, delete the account). Trainers also have no way to see when a player
(adult or child) is available, so nothing in "Best Times" (US-01.09) exists yet either.

## User scenarios

1. **A Parent** (a `PLAYER`-role account) creates a profile for their child so the child
   can train under the family's account.
   Path: from their Player Profiles page, "+ Add Child" → name, age, gender (school and
   photo optional) → if the parent has exactly one trainer, a Yes/No prompt to also
   connect the child to that trainer; if more than one, a checklist of the parent's own
   trainers; if none, no prompt at all → saving creates the child profile, linked to the
   parent, associated with whichever trainers were selected (or none).

2. **A Parent** adds an existing child to another trainer.
   Path: Family/Player Profiles → picks the child → "Add Trainer" → either types in a
   ShareLink code or picks from "My Trainers" (the trainers the parent's own player
   account is already connected to) → confirms → the child is now connected to that
   trainer too, with no duplicate connection ever created for a pairing that already
   exists.

3. **A Parent** removes a child from a trainer the child no longer trains with.
   Path: Family/Player Profiles → the child's row → "Remove" next to a trainer → a
   confirmation naming the child and the trainer and warning about upcoming RSVPs →
   confirms → that one connection ends; the trainer no longer sees the child in their
   roster; every other connection (this child's other trainers, and every other child's
   connections) is untouched.

4. **A Parent who also trains** switches between "themselves" and each child when looking
   at trainer-specific data.
   Path: nothing in this slice builds the actual context-switcher control (deferred, same
   as S3's "Separated Views Architecture" deferral) — but the data the switcher will read
   already exists and is queryable: the parent's own trainer connections, and, separately,
   each child's own trainer connections, never merged into one list.

5. **A signed-in Child account** tries to use a trainer's ShareLink.
   Path: clicks the link → the system recognizes the account as a child → shows a message
   telling them to ask their parent → no connection is made → the parent is emailed,
   naming the child and the trainer, with a "Review Registration" action they can take
   from their own account → only once the parent acts does the child become connected to
   that trainer.

6. **A signed-in Child account** tries to do something only a parent (or an adult player)
   may do — add a trainer, change or remove a trainer connection, delete the account, add
   a payment method, or complete a purchase.
   Path: whatever route they use to attempt it, the system refuses the action; the
   allowed, view-oriented actions (browsing events, viewing purchased content, viewing
   their own progress, submitting feedback, editing their own basic profile info, viewing
   — not spending — tokens) still work.

7. **A Player, or a Parent acting for one of their children**, sets weekly availability so
   trainers know when they can train.
   Path: "Availability"/"Best Times" → a weekly grid → for each day, either specific time
   ranges ("Monday 5:00 PM–8:00 PM") or "Not Available" → save → confirmation that
   trainers can see this when planning. A parent picks which child (or themselves) the
   grid is for via a switcher before saving; each child's saved availability is independent
   of every other child's and of the parent's own.

8. **A Trainer** uses saved availability when planning.
   Path: a player's card in the trainer's roster/CRM shows a short "Best Times" summary
   (e.g., "Mon 5-8pm, Wed 6-9pm"); the trainer can filter their players/roster down to
   only those available at a chosen day/time.

## Acceptance criteria

**Child profile creation (US-01.03)**

- [ ] **AC-1** A signed-in Player can create a child profile by supplying the child's
  name, age, and gender (required); school and photo are optional. (FR-024)
- [ ] **AC-2** A created child profile is linked to the parent's own account and is a
  distinct player identity from the parent's own — the parent's own player data is never
  merged with, or overwritten by, a child's. (FR-024, FR-026)
- [ ] **AC-3** When creating a child profile: if the parent's own player account currently
  has exactly one trainer connection, the system prompts "Will [Child] also train with
  [Trainer]?" (Yes/No); if it has more than one, it presents a checklist of those trainer
  connections to choose from; if it has none, no trainer prompt or checklist is shown at
  all. (FR-025)
- [ ] **AC-4** Answering "Yes" (single-trainer case) or selecting one or more trainers
  (checklist case) connects the new child to every selected trainer as part of the same
  save; answering "No" or selecting none saves the child profile with no trainer
  connection yet. (FR-025)
- [ ] **AC-5** Submitting a child's age outside 1–18, or missing name or gender, is
  refused with a clear validation error and creates nothing. (FR-024, BR-019)
- [ ] **AC-6** A parent can create more than one child profile under their account. (FR-024)

**Family / child-trainer association management (US-01.04)**

- [ ] **AC-7** A parent can see a list of all their children, and for each one: its name,
  age, and every trainer it is currently connected to, each with the date that connection
  began. (FR-027)
- [ ] **AC-8** A parent can connect an existing child to an additional trainer either by
  entering that trainer's ShareLink code, or by choosing from "My Trainers" (the trainers
  the parent's own player account is already connected to); confirming creates exactly
  one new connection; if that exact child-trainer pairing already exists, confirming again
  is a no-op — no duplicate connection is ever created. (FR-027)
- [ ] **AC-9** A parent can remove a child from a specific trainer after a confirmation
  step that names the child and the trainer and warns that upcoming RSVPs will be
  cancelled; confirming ends that one connection immediately, the trainer no longer sees
  that child in their roster from that point on, and the connection's historical record is
  preserved (not deleted) — its removal is what this slice controls; the RSVP cancellation
  itself is a later, separate integration point (see Out of scope). (FR-027, BR-011, BR-012)
- [ ] **AC-10** Ending one child-trainer connection changes nothing about that same
  child's connections with any other trainer, and nothing about any other child's
  connections. (FR-027)
- [ ] **AC-11** For a signed-in parent, the system can produce the full set of "contexts"
  a context selector needs: the parent's own trainer connections (labeled as themselves),
  and, separately for each child, that child's own trainer connections — never combined
  into one undifferentiated list. Whether or not the selector control itself ships this
  slice, this data shape is guaranteed. (FR-028)
- [ ] **AC-12** For a signed-in child account, the same kind of query returns only that
  child's own trainer connections — never the parent's own connections, and never a
  sibling's. (FR-028)

**Child login constraints (US-01.06)**

- [ ] **AC-13** A signed-in child account can: browse eligible events (view-only), view
  content already assigned/purchased to them, view their own training progress, submit
  feedback, update their own basic profile info (photo, preferences), and view (but not
  spend) tokens. (FR-029)
- [ ] **AC-14** A signed-in child account is refused — server-side, not merely hidden from
  the UI — if it attempts to: connect to a new trainer through any route, change or end
  any of its own trainer connections, delete its own account, add or remove a payment
  method, or complete a purchase. (FR-029)
- [ ] **AC-15** A signed-in child account that follows any trainer's ShareLink sees a
  blocking message (e.g., "Ask your parent to register you with this trainer") instead of
  the normal instant-connection outcome an adult player gets; no trainer connection is
  created from that click. (FR-030)
- [ ] **AC-16** The same moment that blocking message is shown, the system emails the
  parent naming the child and the trainer, with a "Review Registration" call to action;
  the child remains unconnected to that trainer until the parent completes the action
  through their own account. (FR-030)
- [ ] **AC-17** Completing that "Review Registration" action from the parent's own account
  produces exactly the same outcome as AC-8's trainer-connection flow — the child ends up
  connected to that trainer with no second, parallel connection mechanism. (FR-030)
- [ ] **AC-18** A signed-in child account never has access — through any context selector,
  roster view, or Best Times view this slice builds — to the parent's own trainer
  connections, availability, or any other of the parent's own player data. (FR-028, FR-029)

**Best Times / availability (US-01.09)**

- [ ] **AC-19** A signed-in Player (or a Parent acting for one of their children) can set,
  for each day of the week, either one or more specific time ranges (e.g., "Monday 5:00
  PM–8:00 PM") or "Not Available", and save them. (FR-033)
- [ ] **AC-20** A parent sets availability separately for each child, and separately for
  themselves if they also train, via a profile switcher; saving one child's (or the
  parent's own) availability never overwrites or alters another child's or the parent's
  own. (FR-033, BR-011)
- [ ] **AC-21** After saving, the system confirms with a message that trainers can see
  these preferences when planning sessions. (FR-033)
- [ ] **AC-22** A trainer-facing player card shows a short "Best Times" summary for that
  player (adult or child) — e.g., "Mon 5-8pm, Wed 6-9pm". (FR-034)
- [ ] **AC-23** A trainer can filter their players/roster to only those available at a
  chosen day and time; the filter matches against both adult and child players' saved
  availability the same way. (FR-034)
- [ ] **AC-24** A day with no time range saved is treated as "Not Available" for filtering
  purposes — never as "unknown" or silently omitted from the filter's evaluation. (FR-033,
  FR-034)

## Edge cases

| Case | Expected |
|---|---|
| A parent creates their first child and has zero trainer connections of their own | No trainer prompt or checklist is shown (AC-3); the child profile is created unconnected, same as answering "No". |
| A parent submits the trainer-selection checklist twice for the same child/trainer pairing (double-submit) | Idempotent — no duplicate connection, same mechanism as AC-8. |
| Two parent sessions/devices remove the same child from the same trainer at the same moment | Exactly one succeeds in ending the connection; the other sees "already removed", not an error. |
| A signed-in child clicks a ShareLink for a trainer they are **already** connected to | Same blocking-message-and-parent-notify behavior as any other trainer's link (AC-15/AC-16) — this spec reads US-01.06 literally, with no carve-out for an existing connection; see Open questions. |
| A child account attempts to call the trainer-connection "add"/"remove" route directly (forged request, not through the UI) | Refused per AC-14 regardless of how the request was made — only a parent, acting on their own account, changes a child's trainer connections. |
| A parent enters a name/age combination close to an existing child's | A soft warning is shown (BR-019's "duplicate check"); it does not block saving — the parent can proceed. |
| A child linked to the family turns 18 while still marked as a child | No transition behavior is defined anywhere in the epic for this slice; not built here (see Open questions). |
| A trainer removes upcoming RSVPs after a child is disconnected | Out of scope for this slice: AC-9 covers only the connection's own removal and its immediate data consequences (the trainer no longer seeing the child); actually cancelling RSVPs is Epic-02 territory, which does not exist yet. |
| A parent sets a child's availability, then later disconnects that child from the trainer the availability was set for | The previously saved availability is preserved as historical data; only the connection's active/ended state changes, not any availability rows. |
| A parent with no children yet, and no trainer connection of their own, opens "Best Times" | Shows only their own (empty) availability grid; no child switcher appears until a child profile exists. |

## Out of scope

- **US-01.05 (Child Purchase Requires Parent Approval)** — depends on tokens/USD payment
  infrastructure that lives in Epic-05, which does not exist yet (tracked as FR-031/FR-032
  in the requirements analysis, explicitly gated on Epic-05). This slice's only connection
  to it: the child-account marker this slice introduces is what a future payments slice
  will key its approval-requirement check off of. No approval workflow, no per-child
  token-spending setting, and no purchase UI of any kind is designed or built here.
- **US-01.10 (Coach Sets My Times)** — a separate slice (S5); nothing here builds coach
  availability, only player/child availability (US-01.09).
- **Super Admin impersonation and its audit tooling (US-01.07)** — a separate slice (S6).
- **The full context-switcher control** (the "Me (→ Coach X)" / children-list UI the epic's
  US-01.04 documents, and the isolated per-context calendar/RSVP/content views it implies).
  Consistent with S3's identical deferral for the trainer-context switcher: this slice
  guarantees only the data shape a later UI slice needs (AC-11, AC-12), not the control
  itself or query-level view isolation beyond what already exists.
- **RSVP cancellation on trainer removal** — the epic's own confirmation copy for removing
  a child from a trainer ("This will cancel all upcoming RSVPs") describes an Epic-02
  behavior; Epic-02 (events/RSVPs) does not exist yet. This slice ends the connection and
  its own immediate data consequences only.
- **A child's age-18 transition / any independent-account path for 16–18-year-olds.** The
  epic surfaces this as its own unresolved question (§12-adjacent, under US-01.06) distinct
  from the "all minors need parent accounts" rule this spec treats as settled; no behavior
  for it is designed here.
- **Time-zone handling for Best Times.** The epic never mentions time zones for
  availability; this slice does not invent one (see Open questions).
- **Portal branding, coach public-profile fields** — unrelated slices (S8, S5).

## Open questions

None of the items below change the acceptance criteria above if answered differently —
each AC describes observable behavior, not a schema — but each is flagged because it is
load-bearing for the architecture phase and was either raised by, or contradicts, the
prior requirements analysis (`tasks/TASK-001/requirements-analyst-requirements.md`).

- **G-07 (carried over, high attention) — how does a child account authenticate, given
  it "shares the parent's contact info"?** `TrainerPlayerAssociation.player_id` is a
  mandatory FK to `app_user.id` (frozen), and `app_user.email` is platform-unique and *is*
  the login identifier (BR-001). This spec's ACs assume a child that can sign in (US-01.06
  describes a real, separate login with its own restricted session) has its own row
  resolvable the same way any player does — most likely its own `User` row with a role of
  `PLAYER` and its own unique email, with "shares the parent's contact info" read as
  "the parent's phone/notification routing is what's used for anything about this child,"
  not "the child has no email of its own." This is an assumption, not a confirmed reading;
  the concrete shape (a marker on `User`, a new child-specific `Profile` subtype, a
  `parent_user_id` column and where it lives) is squarely an architecture decision, not
  this spec's to make — flagged here so the architect does not have to rediscover G-07.
- **Which trainer set counts for AC-3's "only ONE trainer"?** This spec reads it as the
  parent's *own* player-account trainer connections only (matching US-01.04's parallel "My
  Trainers" wording and G-23's "parent account is treated as a player account"), not the
  family's aggregate trainer set across other children. If that reading is wrong, AC-3's
  and AC-8's "My Trainers" source set would need to widen to include trainers reached only
  through other children — flagged for confirmation before the architecture locks in a
  query shape.
- **A logged-in child re-clicking a ShareLink for a trainer they are already connected
  to** (edge case table, row 4): this spec applies the blocking-and-notify flow
  unconditionally, per a literal reading of US-01.06, with no exception carved out for an
  already-existing connection. A narrower reading (skip the block/notify when nothing new
  would be created) is plausible but not stated in the epic; flagged for product
  confirmation, not blocking this spec's AC-15/AC-16 as written.
- **G-02 (epic-internal contradiction, resolved here as directed) — "ALL players under 18
  require parent-managed accounts."** §9 states this as settled while US-01.06 phrases the
  same question as still open (its own "Q-01.05", not to be confused with §12's
  differently-numbered Q-01.05 about email verification). Per this slice's delegation,
  §9's settled rule stands; this is not reopened here.
- **G-16 (partially resolved) — Best Times granularity and time zones.** The "hourly
  blocks OR custom ranges" ambiguity is resolved by this spec in favor of free-form time
  ranges (AC-19), matching the epic's own examples ("Monday 5:00 PM–8:00 PM"). Time zones
  are never mentioned anywhere in the epic and are not designed here; assumed to be a
  single facility-local zone until stated otherwise — flagged, non-blocking.
- **G-20 (carried over, non-blocking) — does the "current trainer context" persist
  server-side** (surviving a device change), or is it session-local? Irrelevant to this
  slice's data-only scope (AC-11/AC-12); relevant once the actual context-switcher control
  is built.
- **Q-01.04 (carried over from S1–S3, still open, P1, client)** — the full transactional
  email list. This slice needs at least one new template: the parent "[Child] wants to
  join [Trainer]'s program" notification (AC-16). Whether it reuses an existing template
  family or needs its own copy is a design-phase decision, not a spec blocker.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-024 Parent creates a child profile (fields, linked to parent) | AC-1, AC-2, AC-5, AC-6 |
| FR-025 Trainer selection at child creation (single-trainer prompt / checklist / none) | AC-3, AC-4 |
| FR-026 Parent account is itself a player account | AC-2 |
| FR-027 Manage child↔trainer connections (add, remove, list) | AC-7, AC-8, AC-9, AC-10 |
| FR-028 Context selector data (parent "Me" + children; child's own-trainers-only) | AC-11, AC-12, AC-18 |
| FR-029 Child login constraints (allow-list / deny-list) | AC-13, AC-14, AC-18 |
| FR-030 ShareLink blocked for children + parent notification | AC-15, AC-16, AC-17 |
| FR-033 Best Times: per-player weekly grid, per-child for parents | AC-19, AC-20, AC-21, AC-24 |
| FR-034 Trainer views/filters by availability | AC-22, AC-23, AC-24 |
| BR-010/BR-012 Minors require parent accounts; connections explicit and parent-changeable | AC-3, AC-4, AC-9, AC-14 |
| BR-019 Validation (child age 1–18, duplicate name/age warning) | AC-5, edge case row 6 |

Slice S4 is done when AC-1 … AC-24 hold, on top of S1's AC-1…AC-25, S2's AC-1…AC-24, and
S3's AC-1…AC-21 continuing to hold (regression, not just addition). FR-031/FR-032 (child
purchase approval) and FR-035…FR-037 (coach My Times, coach public profile) are
deliberately not part of this slice's "done" — see Out of scope.
