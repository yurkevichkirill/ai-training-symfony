# Spec: User Management Basics (Epic-01, slice S2)

> Scope: **slice S2 only** — Users directory, Super-Admin-creates-trainer, profile
> editing, deactivation, GDPR deletion (FR-008…FR-016).
> Source: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`, analysed in
> `tasks/TASK-001/requirements-analyst-requirements.md`.
> Builds on `specs/auth-foundation-spec.md` / `-architecture.md` (S1, done: AC-1…AC-25).
> This document answers *what* and *why*. The design lives in
> `specs/sdd-user-management-architecture.md`.
> Naming note: prefixed `sdd-` (rather than following S1's bare `auth-foundation-*`
> naming) to satisfy this repo's file-naming hook, which requires spec/architecture
> Markdown under `specs/` to start with a discovered skill directory name. `sdd` is
> the flow producing this pair; the shared `user-management` slug keeps the pair
> sorting together as `specs/MANIFEST.md` expects.

## Problem

S1 gives every account a role and a way to sign in, but there is still exactly one way
to create a user — `app:create-super-admin` at a shell — and no way to see, edit,
deactivate, or delete one. Nobody can run the platform: a Super Admin cannot onboard a
single trainer, no one can fix a typo in their own name, and a GDPR erasure request has
no code path to act on. Every later slice (S3's ShareLinks, S6's impersonation) needs a
real user directory and real account lifecycle under it, so this is the next hard
dependency after S1.

## User scenarios

1. **A Super Admin** creates a trainer account so that a new business can start using the
   platform.
   Path: Users tool → "Create User" → Trainer → business name, contact name, email,
   phone → the trainer receives an invitation email and sets their own password from it
   → they sign in and land on the trainer dashboard S1 already built.

2. **A Super Admin** browses and searches the user directory so that they can find an
   account to support.
   Path: Users tool lists every account, paginated, filterable by role and status, and
   searchable by name or email — search and filters are scoped to this tool, not a
   platform-wide search.

3. **Any signed-in user** edits their own profile so that their details stay current.
   Path: "Profile" page shows the fields their role can edit (name, phone, photo) plus
   read-only fields (email, role, account-created date); trainers additionally edit their
   business details. Saving persists immediately with a confirmation.

4. **A Super Admin** deactivates a user who should no longer have access, without losing
   their history.
   Path: Users tool → "Deactivate" on a row → confirmation → the account can no longer
   sign in, but every historical record that already points at it is untouched, and a
   Super Admin can reactivate it later.

5. **A Super Admin** permanently deletes a user's personal information to satisfy a GDPR
   erasure request.
   Path: Users tool → "Delete" on a row → warning → the account's name, email, and phone
   are irreversibly replaced with anonymized placeholders; the account can never sign in
   or be reactivated; a compliance record captures who did it, when, and why.

## Acceptance criteria

**Users directory**

- [ ] **AC-1** A Super Admin sees every account in a paginated list; no other role can
  reach this tool. (FR-008)
- [ ] **AC-2** The list is filterable by role and by status, and searchable by name or
  email; search and filters apply only within this tool. (FR-008, G-17)
- [ ] **AC-3** The list remains responsive-enough to page through at the data volumes a
  training deployment produces; pagination is server-side, never "load everything then
  filter in the browser." (NFR-002)

**Trainer creation**

- [ ] **AC-4** A Super Admin creates a trainer account by supplying business name, contact
  name, email, and phone; the account is created with no usable password. (FR-009)
- [ ] **AC-5** Creating the account sends an invitation email containing a single-use,
  time-limited link; the recipient sets their own password from it, which also verifies
  their email and activates the account. (FR-009, G-10 resolved below)
- [ ] **AC-6** An expired or already-used invitation link is refused with a clear message
  and no path to guess a working one. (FR-009, BR-003 lifetime family)
- [ ] **AC-7** Attempting to create a trainer with an email already in use is refused with
  a field-level validation error, not a duplicate account and not a 500. (FR-009, US-01.01)
- [ ] **AC-8** There is no route, form, or endpoint through which a Trainer, Coach, or
  Player/Parent role can be self-registered; trainer creation is reachable only by a
  Super Admin. (FR-010, BR-005)
- [ ] **AC-9** Creating a trainer writes an audit record — who created it, when, and the
  trainer details — queryable later. (US-01.01 implementation note)

**Profile editing**

- [ ] **AC-10** Every signed-in user can view and edit their own name, phone number, and
  photo; email, role, and account-created date are shown but not editable through this
  form. (FR-011)
- [ ] **AC-11** A trainer additionally edits their business name and organization details
  through the same profile area. (FR-011, role-specific fields)
- [ ] **AC-12** Uploading a profile photo accepts only JPEG/PNG/WebP up to 5 MB, rejects
  anything else with a validation error, and the stored file is served back at a URL that
  is not a directly browsable filesystem path guessable from another user's id.
  (FR-012)
- [ ] **AC-13** A user cannot edit another user's profile through this form; only a Super
  Admin edits an arbitrary account, and does so through the Users tool, not by spoofing
  the profile form's target id. (FR-013, BR-020)

**Deactivation**

- [ ] **AC-14** A Super Admin deactivates an account from the Users tool after a
  confirmation step; the account's status becomes `DEACTIVATED`. (FR-014, US-01.12)
- [ ] **AC-15** A deactivated account cannot sign in and sees the existing S1 rejection
  behavior (uniform message); a session already open for that account stops working at
  its next request (reusing S1's `EquatableInterface` mechanism — no new code path).
  (FR-014)
- [ ] **AC-16** Deactivation deletes nothing: the account row, and everything that already
  references it, is untouched and still readable. (FR-014, BR-016)
- [ ] **AC-17** A Super Admin reactivates a `DEACTIVATED` account, and it can sign in again
  immediately. (FR-014, US-01.12)

**GDPR deletion**

- [ ] **AC-18** A Super Admin deletes a user's PII from the Users tool after a warning that
  states the action is irreversible. (FR-015, US-01.13)
- [ ] **AC-19** After deletion: name becomes "Deleted User", email becomes
  `deleted_<user-id>@example.com`, phone becomes null, photo becomes the default; the
  account status becomes `DELETED` and it can never sign in again. (FR-015)
- [ ] **AC-20** A `DELETED` account can never be reactivated; the Users tool offers no such
  action for it. (FR-015, BR-016)
- [ ] **AC-21** Deletion writes an immutable compliance record — the original user id, who
  deleted it, when, and an optional reason — independent of the now-anonymized user row.
  (FR-015, US-01.13)
- [ ] **AC-22** The anonymized email is guaranteed unique across repeated deletions because
  it is derived from the account's own immutable id, never colliding with another
  anonymized or live account. (G-15 resolved)
- [ ] **AC-23** Deleting an account a second time (already `DELETED`) is refused as a
  no-op with a clear message, not a second anonymization pass. (edge case)

**Multi-tenancy (foundation only — see Out of scope)**

- [ ] **AC-24** A `TrainerProfile` is the anchor later slices attach organization-owned
  records to; creating one does not yet grant it any data to isolate, so there is nothing
  in S2 for a leakage test to exercise. (FR-016, scoped down — see Decisions)

## Edge cases

| Case | Expected |
|---|---|
| Invitation link opened after the trainer already set a password through it | Refused as consumed; no second password-setting is possible from that link. |
| Two Super Admins create a trainer with the same email concurrently | Exactly one succeeds; the other gets a field-level "email already in use" error, not a 500 (same mechanism as S1's concurrent-registration handling). |
| Super Admin deletes a user, then immediately tries to delete the same user again | Refused as a no-op — the account is already `DELETED`. |
| Super Admin deactivates a `DELETED` account | Refused — `DELETED` is a terminal state; deactivation is only meaningful from `ACTIVE`. |
| Profile photo upload of a valid-looking file with a spoofed extension (e.g. a script renamed `.jpg`) | Rejected: validation checks the actual MIME/content, not the filename extension. |
| Search query containing SQL metacharacters or `%`/`_` wildcards | Treated as literal text; no error, no injection, no unintended wildcard expansion. |
| A user's own profile-edit `POST` includes a different user's id in a hidden/tampered field | Refused; the form always acts on the signed-in user's own id server-side, never on a client-supplied id, unless the caller is a Super Admin using the admin edit action. |
| Trainer invitation email undeliverable (bad domain, mailbox full) | Same as S1's pattern: the Super Admin still sees "trainer created, invitation sent"; delivery failure is retried and operator-visible, not surfaced as a different user-facing outcome. |

## Out of scope

- **Full multi-tenant data isolation** (0% leakage across organizations, NFR-011). S2
  creates the `TrainerProfile` anchor only; there are no coach/player-owned records yet
  to leak, since those associations arrive in S3 (ShareLinks) and S4 (family). Building
  an isolation voter now would guess at S3's association shape. Tracked as the S3 "Done
  when" criterion, not dropped.
- Coach and player profile subtypes (`profile_coach`, `profile_player`, `profile_child`)
  — added by S3/S4/S5 when each has real fields to put in them.
- ShareLink registration, coach invitation (S3).
- Family accounts, child constraints, Best Times (S4/S5).
- Impersonation and its audit report (S6) — AC-9/AC-21's audit records are the
  groundwork S6 reports over, same relationship S1's `AuthEvent` has to S6.
- Portal branding (S8).
- Thumbnail generation for photos: spec mentions it (FR-012); S2 stores and serves the
  original (validated, size-capped) upload only. A thumbnail is a presentation-layer
  optimization with no acceptance criterion that depends on it existing — added later if
  a real page-weight problem shows up, not speculatively.

## Resolved decisions

- **G-10 — invitation link, not a temporary password.** A temporary password sent by
  email is itself an unencrypted, replayable credential sitting in an inbox indefinitely.
  A single-use, time-limited invitation link that ends in the trainer setting their own
  password (and verifying their email in the same action) has no equivalent weakness and
  reuses S1's proven selector/verifier token discipline instead of inventing password
  generation and forced-change tracking. This also directly satisfies "forced password
  change on first login" — there is no password to change, because there was never one
  to begin with.
- **G-15 — anonymized-email collision, resolved by construction.** `deleted_<user-id>@example.com`
  cannot collide because `user_id` is already the table's unique primary key; two
  different users can never anonymize to the same address, and re-deleting the same user
  is rejected as a no-op (AC-23) before a second anonymization is attempted.
- **G-14 — retention vs. erasure, resolved in favor of erasure.** The spec's §8 mention of
  "backup of original data" for deleted users is superseded here: GDPR erasure means the
  PII is gone from the live row, full stop. The compliance record (AC-21) keeps only what
  accountability requires — who deleted the account, when, and an optional reason — never
  a backup of the erased PII. If a future legal requirement needs more, that is a new,
  explicit decision, not a default.
- **G-17 — search scope.** "Tool-specific search" means: the Users tool's search box
  matches against that account's name and email only (case-insensitive substring), scoped
  by whatever role/status filters are also active, and never queries outside the Users
  tool's own listing. It is not a platform-wide search index.
- **G-18 — trainer self-service user management is not S2.** §3's "Trainer manages own
  organization users" has no user story and no data to act on until S3/S4 create
  trainer-owned coach/player associations; S2 leaves it unbuilt rather than guessing at a
  UI for records that do not exist yet.

## Open questions

Non-blocking, same disposition as S1's:

- **Q-01.01 (P2, client)** — Skill-level definitions. Irrelevant to S2 (no player
  profile yet); tracked for S3/S4.
- **Q-01.04 (P1, client)** — Full transactional email list. S2 needs exactly one new
  template (trainer invitation) beyond S1's two.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-008 Users directory, pagination, filters, scoped search | AC-1, AC-2, AC-3 |
| FR-009 Super Admin creates trainer, invitation, audit | AC-4, AC-5, AC-6, AC-7, AC-9 |
| FR-010 No self-registration for any role | AC-8 |
| FR-011 Own-profile editing, role-specific fields, read-only fields | AC-10, AC-11 |
| FR-012 Photo upload | AC-12 |
| FR-013 Super Admin edits any account | AC-13 |
| FR-014 Deactivation (soft delete) | AC-14, AC-15, AC-16, AC-17 |
| FR-015 GDPR deletion | AC-18, AC-19, AC-20, AC-21, AC-22, AC-23 |
| FR-016 Multi-tenancy foundation | AC-24 |

Slice S2 is done when AC-1 … AC-24 hold, on top of S1's AC-1…AC-25 continuing to hold
(regression, not just addition).
