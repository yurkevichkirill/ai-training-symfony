# Spec: ShareLink Invitations (Epic-01, slice S3)

> Naming note: filed as `sdd-sharelink-invitations-spec.md` (rather than the bare
> `sharelink-invitations-spec.md` slug) to satisfy this repo's file-naming hook, which
> requires spec/architecture Markdown under `specs/` to start with a discovered skill
> directory name — same reason S2's pair is `sdd-user-management-*`. The feature slug
> stays `sharelink-invitations` everywhere else (this file's body, the architecture file
> to follow, and its `specs/MANIFEST.md` row).
>
> Scope: **slice S3 only** — the ShareLink invitation system (FR-017…FR-021), covering
> exactly two user stories: US-01.02 "Player Registers via ShareLink" (including its
> "Acceptance Criteria (Multi-Trainer)" block) and US-01.08 "Trainer Invites Coach".
> Source: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` §7 (US-01.02,
> US-01.08), §9 (Business Rules: Player Registration, Coach Invitation, ShareLink
> Tracking, Multi-Tenancy), §10 Flow 1 (Player Registration via ShareLink) and Flow 5
> (Trainer Invites Coach).
> Builds on `specs/auth-foundation-spec.md` / `-architecture.md` (S1, shipped) and
> `specs/sdd-user-management-spec.md` / `-architecture.md` (S2, shipped): `User` with a
> single `UserRole` (`SUPER_ADMIN`, `TRAINER`, `COACH`, `PLAYER` — no separate `PARENT`
> role; a parent is a `PLAYER`-role account, per S1's G-23), the frozen `Profile`
> hierarchy (`Profile` abstract base + `ProfileTrainer` concrete so far — no `Coach` or
> `Player` profile subtype exists yet), `AccountInvitation` (single-use, 7-day-expiry
> selector/verifier token — but always issued *to an existing `User` row* the Super Admin
> already created; see Problem below for why S3 cannot reuse it as-is), `AccountEvent`
> audit log, and `AccountLifecycleService` (deactivation/deletion).
> This document answers *what* and *why*. The design lives in
> `specs/sdd-sharelink-invitations-architecture.md` (not yet written).

## Problem

S1 gave every account a way to sign in, and S2 gave a Super Admin a way to create a
Trainer and manage any account's lifecycle — but there is still no way for anyone else
to ever reach the platform. S1 deliberately built no self-registration route for any
role (BR-005), and S2's `AccountInvitation` only ever targets a `User` row a Super Admin
already created (a trainer). A Player has no path to an account at all, and a Coach has
no path either. Without it, the roster the rest of the platform depends on — a trainer's
players, a trainer's coaches — never has anyone in it beyond the Super-Admin-made
trainers themselves.

This is also where three business rules that only make sense once real registrants exist
first get a code path: a Player can be associated with more than one Trainer without a
duplicate account (multi-tenancy §9), a Coach can be active under exactly one Trainer at
a time (strictly enforced, §9), and both kinds of link carry different lifetimes — a
player link is static and reusable indefinitely, a coach link is unique and expires in
seven days (§8, §9, US-01.02's Implementation Notes). None of S1's or S2's token
mechanisms encode "reusable, no expiry, not yet tied to any user" — `AccountInvitation`
is single-use and always has a `user_id` from the moment it is issued — so this slice
needs its own kind of link, not a relabeling of S2's.

## User scenarios

1. **A prospective Player or Parent**, with no account yet, registers by following a
   trainer's ShareLink.
   Path: clicks `https://app.platform.com/join/ABC123` → not signed in → a registration
   form (name, email, password, phone, and the player's own name/age/gender) → submitting
   creates one account, associates it with the trainer who owns that link, and sends a
   confirmation email.

2. **An already signed-in Player** follows a trainer's ShareLink for a trainer they are
   not yet associated with.
   Path: no form, no confirmation step — the association is created instantly and they
   land in that trainer's context. This is the same outcome whether it is their first
   trainer association or an additional one; the system does not treat "first" specially.

3. **A Player already associated with one trainer** follows a *different* trainer's
   ShareLink.
   Path: exactly one new trainer association is added to the existing account; nothing
   about the account's other trainer association(s) changes, and no second account is
   ever created for the same person.

4. **A Trainer** invites a named person to work with them as a Coach.
   Path: from a "Coaches" area, enters the coach's email (and optionally a name and
   personal message) → the system generates a unique, single-use link that expires in
   seven days and emails it to that address → the coach follows it, registers or signs
   in, and becomes associated with the inviting trainer, who now sees them in their
   Coaches list with the invitation marked "Accepted."

5. **A Trainer invites a Coach who is already actively working with a different
   Trainer.**
   Path: the invited person follows the link and attempts to complete the join, but the
   system refuses it with a clear reason — a Coach can be active under only one Trainer
   at a time — and no association is created.

## Acceptance criteria

**ShareLink fundamentals**

- [ ] **AC-1** A ShareLink always belongs to exactly one Trainer; following it always
  resolves to that same trainer, never an ambiguous or different one. (FR-017)
- [ ] **AC-2** A player ShareLink has no expiry and no maximum-use count; it can be
  followed successfully any number of times, indefinitely. (FR-017, §9 "Player
  Registration")
- [ ] **AC-3** A coach ShareLink can be followed successfully exactly once, and stops
  working seven days after it is generated, whichever comes first. (FR-017, §9 "Coach
  Invitation")
- [ ] **AC-4** A Trainer can generate a player ShareLink at any time, for broad sharing,
  without naming a specific recipient. (FR-017, US-01.02 Implementation Notes)
- [ ] **AC-5** A Trainer invites a Coach by supplying that coach's email address
  (optionally with a name and a personal message); the system generates the unique
  seven-day coach link and sends it to that address in an invitation email carrying any
  supplied message. (FR-020, US-01.08)
- [ ] **AC-6** Every successful use of a ShareLink (a new registration or an added
  association) is attributable to the specific link that was used, and the number of
  times a given player ShareLink has been used is retained by the system. (FR-017, §8
  "How many times used" — groundwork for Epic-06 analytics; no reporting UI this slice)

**New player registration (US-01.02, base)**

- [ ] **AC-7** Following a valid player ShareLink while not signed in leads to a
  registration form capturing: name, email, password, phone, and the player's own
  name, age, and gender. (FR-018, US-01.02)
- [ ] **AC-8** Submitting that form creates exactly one account, creates a trainer-player
  association naming the trainer who owns the link that was followed, and the trainer
  can now see that player in their roster. (FR-018, US-01.02)
- [ ] **AC-9** Completing registration sends a confirmation email to the address
  supplied. (FR-018, US-01.02)
- [ ] **AC-10** Registering via a ShareLink is subject to the same account-level rules
  already in force platform-wide: an email already in use is refused with a clear,
  field-level error — never a duplicate account, never an unhandled failure. (FR-018,
  reuses S1 AC-5 / S2 AC-7's mechanism)

**Existing-account association (US-01.02, base + Multi-Trainer)**

- [ ] **AC-11** Following a valid player ShareLink while already signed in as a Player
  creates the association immediately, with no registration form and no separate
  confirmation step. (FR-019, US-01.02)
- [ ] **AC-12** A signed-in Player who already has one or more trainer associations,
  on following a *different* trainer's ShareLink, gets exactly one new association
  added; none of their existing trainer associations are altered, removed, or
  duplicated, and no second account is ever created. (FR-019, US-01.02
  "Acceptance Criteria (Multi-Trainer)", §9 "no duplicate account")
- [ ] **AC-13** Following a ShareLink for a trainer the player is *already* associated
  with is idempotent: no duplicate association is created, and the outcome is the same
  as if the trainer's own ShareLink had simply been visited again. (edge case,
  consistent with AC-12)

**Coach invitation (US-01.08)**

- [ ] **AC-14** Following a valid, unused, unexpired coach ShareLink leads to a
  registration-or-sign-in flow addressed to the invited email address. (FR-020,
  US-01.08)
- [ ] **AC-15** Completing that flow creates (or signs into) the coach's account,
  associates it with the inviting trainer, moves that invitation's status from
  "Pending" to "Accepted", and the coach now appears in the trainer's Coaches list.
  (FR-020, US-01.08)
- [ ] **AC-16** A Coach account that is currently, actively associated with one trainer
  cannot also become active under a different trainer: an invitation that would create
  a second simultaneous active association is refused with a clear message, and no
  association is created. (FR-021, §9 "Coach cannot be active under multiple trainers")
- [ ] **AC-17** A Trainer can see, for every coach invitation they have sent, whether it
  is Pending, Accepted, or Expired. (FR-020, US-01.08)
- [ ] **AC-18** A coach ShareLink that has already been used, or is more than seven days
  old, is refused with a clear message that distinguishes "already used" from
  "expired", and the trainer is offered a way to send a new invitation to the same
  person. (FR-020, US-01.08 Validation)
- [ ] **AC-19** Inviting a coach without an email address is refused with a validation
  error; email is the only required field to send an invitation. (FR-020, US-01.08
  Validation)

**Role integrity**

- [ ] **AC-20** A player ShareLink only ever creates or extends a Player-role
  association; an account of any other role (Coach, Trainer, Super Admin) that follows
  one is refused, never silently given a second role or a Player association bolted
  onto a different role. (FR-019, FR-021, §9 "each user has exactly one role")
- [ ] **AC-21** A coach ShareLink only ever completes for the email address it was
  issued to; a signed-in account with a different email, or a different role, cannot
  use someone else's coach invitation to join as a coach. (FR-020, FR-021)

## Edge cases

| Case | Expected |
|---|---|
| A player ShareLink is followed by an account whose status is `DEACTIVATED` or `DELETED` (S2) | Refused, using S1's existing sign-in/authorization refusal behavior; a deactivated or deleted account cannot gain a new trainer association until it is reactivated (which only a Super Admin can do). |
| The Trainer who owns a ShareLink is themselves `DEACTIVATED` or `DELETED` | The link resolves to no usable trainer; following it is refused with a clear "this invitation is no longer available" message — never a dangling association pointed at an inaccessible trainer. |
| Two devices follow the exact same one-time coach ShareLink at effectively the same moment | Exactly one use succeeds and consumes the link; the other is refused as "this invitation has already been used." |
| A coach invitation link is opened, but the person completes sign-in as a different email than the one the invitation was addressed to | Refused per AC-21; the coach join never completes for an account that does not match the invited email. |
| A coach re-follows their own already-accepted invitation link | Treated as idempotent: no error, no duplicate association — they land in their existing relationship with that trainer, not a re-processed join. |
| A Coach account was previously associated with Trainer A, that association has since ended, and the coach now accepts a fresh invitation from Trainer B | Succeeds — AC-16's exclusivity rule is evaluated against *currently active* associations only, not historical ones (see Open questions: this reading is an assumption, not an explicit epic statement). |
| A player ShareLink URL is shared or scraped far beyond its intended audience, producing a burst of registrations | No special handling beyond what S1 already enforces platform-wide (rate limiting, CSRF protection, unique-email validation) — ShareLink registration is not exempt from, or a replacement for, those protections. |
| A signed-in Coach account follows a player ShareLink | Refused per AC-20 — a Coach-role account cannot gain a Player-role trainer association through this mechanism. |
| A signed-in Player account follows a coach invitation link (whether or not it names their own email) | Refused per AC-21 — a coach join only ever completes through a coach account matching the invited email; a Player account is never converted or dual-associated this way. |

## Out of scope

- **The Parent/Child family half of US-01.02's "Acceptance Criteria (Multi-Trainer)"
  block** — the "Who will train with [New Trainer]?" selection prompt, the
  Parent(Me)+children checklist, and associating only the selected family members.
  Nothing in S1 or S2 built a Parent-vs-Child distinction or a Child profile: the
  `UserRole` enum has no `PARENT` case (a parent is simply a `PLAYER`-role account, per
  S1's G-23), and S2's own scope notes defer "family accounts, child constraints" to a
  later slice (S1/S2 Out-of-scope; epic §13's implementation order also places "Player/
  Parent features (profiles, children, Best Times)" one step after "ShareLink invitation
  system"). Writing an acceptance criterion against a guessed Child entity shape would
  invent structure this repository has not decided yet. This spec's multi-trainer
  criteria (AC-12, AC-13) cover only the case the epic states without any family
  qualifier: an existing account gaining a second trainer with no duplicate account.
  **Flagged back to the requester — see Open questions.**
- **"Separated Views Architecture"** — the per-trainer context switcher and fully
  isolated calendar/tokens/content/reservations views US-01.02 describes. These isolated
  domains (events/calendar is Epic-02, tokens/payments is Epic-05, content is Epic-04)
  do not exist yet. S3 guarantees only the data fact those later views will need: each
  trainer-player association is its own distinct record (AC-12), not a merged one. No
  context-switcher UI or query-level isolation is built in this slice.
- Parent/child profile creation and management in general (US-01.03, US-01.04) — a
  distinct future user story, not part of this delegation.
- Child login constraints and the "ShareLink blocking for children" notification flow
  (US-01.06) — depends on the same Child model called out above.
- Child purchase approval (US-01.05), Best Times/availability (US-01.09, US-01.10) — not
  part of this delegation.
- Camp-to-user conversion (Epic-08 integration, §3) — a different, later registration
  entry point that happens to also produce a player-trainer association; out of scope
  here.
- ShareLink usage analytics/reporting UI (Epic-06) — AC-6 keeps the raw count; dashboards
  over it are a later epic.
- Coach public-profile fields (bio, credentials, certifications — US-01.11) and coach
  availability (US-01.10) — a Coach account exists after this slice, but nothing beyond
  what AC-14/AC-15 require (an email, an association) is captured about them yet.
- Portal branding, impersonation — unrelated slices (S6, S8).

## Open questions

All four blocking questions below were resolved by the requester on 2026-08-20; each
resolution is now load-bearing for the design phase, not an assumption.

- **Parent/Child scope boundary — RESOLVED: defer to the Player/Parent slice.** US-01.02's
  family-selection prompt and "Separated Views Architecture" bullets stay out of S3
  entirely, exactly as scoped in `## Out of scope` above. This slice's multi-trainer
  criteria (AC-12, AC-13) are the full extent of US-01.02's multi-trainer half that S3
  delivers; the family-selection half ships in the later Player/Parent slice once a
  Child profile model exists.
- **Coach account status — RESOLVED: invitation status only.** "Pending"/"Active" in
  US-01.08 refers solely to the `AccountInvitation`-equivalent record's own status
  (Pending/Accepted/Expired, AC-17); the coach's `User` account becomes fully `ACTIVE`
  on completing registration, the same as any other account. There is no third
  "pending coach" account state.
- **Coach exclusivity window — RESOLVED: currently-active associations only.** AC-16's
  single-trainer-at-a-time rule evaluates against the coach's *currently active*
  trainer association(s) only. A coach whose association with a prior trainer has since
  ended is free to accept a new invitation from a different trainer — confirmed as the
  intended reading, not merely the plain-language default.
- **Email verification for ShareLink registrants — RESOLVED: no exception.** S1's
  "email must be verified before first sign-in" rule applies unchanged to every account
  created via a ShareLink (player self-registration or coach invitation). US-01.02's
  "instant access after registration" language describes the trainer association being
  created immediately, not a bypass of email verification.
- **Q-01.04 (carried over from S1/S2, still open, P1, client)** — the full transactional
  email list. Non-blocking, same disposition as S1/S2: this slice needs at least a
  coach-invitation email and a player-registration confirmation email; whether either
  reuses an existing S1/S2 template or needs its own copy is a design-phase decision,
  not a spec blocker.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-017 ShareLink ownership, lifetimes (static/unlimited vs. unique/7-day), usage tracking | AC-1, AC-2, AC-3, AC-4, AC-5, AC-6 |
| FR-018 New player registration via ShareLink | AC-7, AC-8, AC-9, AC-10 |
| FR-019 Existing-account instant association / multi-trainer association | AC-11, AC-12, AC-13, AC-20 |
| FR-020 Coach invitation via unique ShareLink | AC-5, AC-14, AC-15, AC-17, AC-18, AC-19, AC-21 |
| FR-021 Coach single-trainer exclusivity | AC-16, AC-20, AC-21 |

Slice S3 is done when AC-1 … AC-21 hold, on top of S1's AC-1…AC-25 and S2's AC-1…AC-24
continuing to hold (regression, not just addition). All open questions are resolved
(see `## Open questions`); AC-1…AC-21 is confirmed as the full scope of this slice, not
a partial one.
