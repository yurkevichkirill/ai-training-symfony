# Spec: Auth Foundation (Epic-01, slice S1)

> Scope: **slice S1 only** — core authentication and authorization (FR-001…FR-007).
> Source: `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`, analysed in
> `tasks/TASK-001/requirements-analyst-requirements.md`.
> This document answers *what* and *why*. The design lives in
> `specs/auth-foundation-architecture.md` (not yet written).

## Problem

The platform has four distinct kinds of user — Super Admin, Trainer/Business Owner,
Coach/Contractor, and Player/Parent — and today there is no way for any of them to
prove who they are or to be kept out of anything. Every other epic (Epic-02 … Epic-08)
is blocked on this: a ShareLink belongs to a trainer, an event belongs to an
organization, a payment belongs to a payer, and none of those owners exist until
accounts and roles do.

The consequences of getting this wrong are not evenly distributed. Trainers are
independent businesses sharing one platform, so an authorization defect leaks one
business's roster to a competitor. A meaningful share of the player population are
minors, so a session or password defect exposes children's data. Both make the
authorization boundary a P0 concern rather than a later hardening pass.

## User scenarios

1. **Any user (all four roles)** signs in with their email and password so that they
   land on the workspace their role is entitled to.
   Path: they open the site, enter email and password, and arrive at the dashboard for
   their role — a Super Admin sees the platform, a Trainer sees their organization, a
   Coach sees their assignments, a Player/Parent sees their training. Nobody chooses
   their role at login; the account already carries exactly one.

2. **A user who has forgotten their password** asks for a reset so that they can get
   back in without contacting support.
   Path: they ask for a reset from the sign-in page, receive a link by email, and set a
   new password. The link stops working an hour after it was issued, and once it has
   been used it cannot be used again.

3. **A newly created user** confirms that the email address on the account really
   reaches them, so that reset links and every later notification are deliverable.
   Path: the account is created, a verification link arrives by email, and following it
   marks the address confirmed. The link stops working after 24 hours, and a fresh one
   can be requested.

4. **A signed-in user** is kept inside their role so that no one reaches another
   role's capabilities by guessing a URL.
   Path: they attempt to reach something their role does not permit — by typing the
   address, following a stale link, or replaying an old request — and are refused. The
   refusal is decided on the server; hiding the link in the interface is presentation,
   not enforcement.

5. **A user who has finished working** signs out, or simply walks away, so that the
   next person at that machine cannot continue their session.
   Path: signing out ends the session immediately. An abandoned session also stops
   being usable on its own after a period of inactivity.

6. **An attacker** guessing passwords against a known email address is slowed to the
   point of futility, so that a weak password is not automatically a compromised one.
   Path: repeated failed sign-in attempts against the same account or from the same
   origin are throttled, and the response does not reveal whether the email exists.

## Acceptance criteria

**Authentication**

- [ ] **AC-1** A user with a correct email and password, whose account status is
  Active **and whose email address is verified**, is signed in. (FR-001, FR-005,
  Q-01.05)
- [ ] **AC-2** A user with an incorrect password, an unknown email, a non-Active
  account status, or an unverified email address is not signed in, and these cases are
  indistinguishable to the caller in both message and observable timing. (FR-001,
  FR-003, FR-005)
- [ ] **AC-3** All four roles — Super Admin, Trainer, Coach, Player/Parent — sign in
  through the same mechanism, with no role-specific sign-in path. (FR-001)
- [ ] **AC-4** No password is stored or logged in a recoverable form; only a salted
  hash from a current industry-standard algorithm is persisted. (FR-002)
- [ ] **AC-5** Email is unique platform-wide, enforced by the database and not only by
  application validation, and is compared case-insensitively so that two accounts
  cannot differ only by letter case. (FR-002, BR-001)

**Session management**

- [ ] **AC-6** Signing out invalidates the session server-side; replaying the previous
  session identifier afterwards is refused. (FR-003)
- [ ] **AC-7** A session becomes unusable after the configured inactivity period, and
  the session identifier is transported so that client-side script cannot read it and
  it is not sent over plaintext connections. (FR-003, NFR-009)
- [ ] **AC-8** The session identifier is regenerated on sign-in and on password change,
  so that a value observed before authentication cannot be used after it. (FR-003,
  NFR-009)

**Password reset**

- [ ] **AC-9** Requesting a reset for a registered address sends a link containing a
  single-use, unpredictable token. (FR-004)
- [ ] **AC-10** A reset token is refused more than one hour after issue, and is refused
  on second use even within the hour. (FR-004, BR-003)
- [ ] **AC-11** Requesting a reset for an unregistered address produces the same
  response as for a registered one, revealing nothing about which addresses exist.
  (FR-004)
- [ ] **AC-12** Completing a reset invalidates that user's other active sessions and
  any outstanding reset tokens for the account. (FR-004)

**Email verification**

- [ ] **AC-13** Account creation issues a verification link carrying a single-use,
  unpredictable token; following it marks the address verified. (FR-005)
  *S1 boundary (recorded 2026-08-18):* S1 builds and tests the whole mechanism — token
  issue, consume, single-use enforcement, and a public resend endpoint that exercises it
  end to end — but has **no account-creation path to trigger it from**, because
  self-registration is out of scope (S3 ShareLinks) and the `app:create-super-admin`
  bootstrap sets verification directly to avoid a first-boot deadlock (AC-25). The
  account-creation trigger is claimed by S2/S3, not silently dropped. AC-13 is therefore
  satisfied in S1 via the resend path only.
- [ ] **AC-14** A verification token is refused more than 24 hours after issue, and a
  replacement can be requested from the account. (FR-005, BR-003)

**Authorization (RBAC)**

- [ ] **AC-15** Every user account carries exactly one role, and the schema makes a
  second role unrepresentable rather than merely discouraged. Capabilities that are not
  the security identity — a parent who also plays — are carried by profile records
  attached to that single-role account, never by a second role. (FR-006, BR-004, G-23)
- [ ] **AC-16** After sign-in a user reaches the dashboard for their role, and each of
  the four roles has a distinct landing destination. (FR-006)
- [ ] **AC-17** A request for a capability outside the caller's role is refused on the
  server with an authorization failure, whether or not the interface offered a link to
  it. Absence of a navigation entry never constitutes enforcement. (FR-006, BR-020)
- [ ] **AC-18** An unauthenticated request to any non-public route is refused; the
  publicly reachable set is exactly sign-in, password reset request and completion,
  email verification, and the static assets those pages need. (FR-006)

**Abuse resistance**

- [ ] **AC-19** Repeated failed sign-in attempts are throttled per account and per
  source, and the throttle applies equally to attempts against non-existent accounts so
  that throttling behaviour does not itself enumerate users. (FR-007, BR-002)
- [ ] **AC-20** Password reset requests and verification-email resend requests are rate
  limited per account and per source. (FR-007)
- [ ] **AC-21** Every state-changing request carries CSRF protection; a request with a
  missing or invalid token is refused. (FR-007, NFR-009)

**Cross-cutting**

- [ ] **AC-22** Sign-in, password reset, and email verification screens are operable by
  keyboard alone, expose errors to assistive technology, and meet WCAG 2.1 AA contrast
  and focus-visibility requirements. (NFR-007)
- [ ] **AC-23** Those same screens are usable on a phone-sized viewport with
  touch-sized controls. (NFR-008)
- [ ] **AC-24** Authentication events — sign-in success, sign-in failure, sign-out,
  password reset requested and completed, email verified — are recorded with actor,
  timestamp, and source, and the records contain no password or token material.
  (FR-041 groundwork)
- [ ] **AC-25** A Super Admin account can be created on a system with no users, by an
  operator-run console command, without a self-registration path existing and without
  credentials appearing in migration history or the repository. (G-08, BR-005)

## Edge cases

| Case | Expected |
|---|---|
| Password reset requested twice; both links opened | The most recently issued token is valid; earlier outstanding tokens for that account are refused. |
| Reset link opened after the password was already changed by another means | Refused as consumed; the user is directed to request a new one. |
| Reset link opened while signed in as a *different* user | The existing session is discarded before the reset is applied; the reset always acts on the token's subject, never on the session's. |
| Sign-in attempt on an account deactivated mid-session (S2 sets the status; S1 must honour it) | Refused, and any existing session for that account stops being usable at its next request rather than surviving until inactivity expiry. |
| Verification link opened when the address is already verified | Treated as success and idempotent — no error, no second state change. |
| Two accounts registered concurrently with the same email | Exactly one succeeds; the other fails on the uniqueness constraint with a validation error, not a 500. |
| Email differing only by case or surrounding whitespace (`Ann@x.com` vs `ann@x.com `) | Treated as the same address: normalised on input, matched case-insensitively. |
| Throttle limit reached, then the correct password supplied | Still refused for the remainder of the throttle window; a correct password does not clear the counter. |
| Email delivery fails for a reset or verification message | The user-facing response is unchanged (AC-11 holds); the delivery failure is recorded for operators and the message is retried. |
| Session cookie present but the underlying user record was deleted | Treated as unauthenticated. |
| Concurrent sign-ins for one account from two devices | Both are permitted; a password reset (AC-12) ends both. |
| Very long or non-ASCII password submitted | Accepted up to a documented byte limit and hashed unmodified; never silently truncated. |

## Out of scope

Deferred to later slices of Epic-01, and not to be designed for here beyond leaving
room:

- User CRUD, the Users directory, profiles, photo upload, deactivation, and GDPR
  deletion (S2). S1 must *honour* an account status field but does not build the tools
  that set it.
- Multi-tenancy data isolation between trainer organizations (S2, cross-cutting). S1
  establishes the role; it does not establish organization ownership of records.
- ShareLink registration for players and coaches (S3) — S1 has no self-registration
  path at all, since only Super Admin creates trainers (BR-005) and everyone else
  arrives through a ShareLink built in S3.
- Family accounts, child login constraints, and parent approval (S4).
- Coach and player availability (S4, S5).
- Super Admin impersonation and its audit report (S6). AC-24 lays the logging
  groundwork; the impersonation feature itself is S6.
- Portal branding (S8).

Also out of scope, permanently or by decision elsewhere:

- Social sign-in, SSO, and multi-factor authentication — absent from the epic's MVP
  scope.
- Self-service email change (BR-001 makes email read-only in MVP).

## Resolved decisions

Answered by the product owner on 2026-08-18, in the `/sdd` phase-2 gate. These were
the blocking open questions; they are now inputs to the design, not guesses.

- **G-23 — role model: one role plus profiles.** A `User` carries exactly one role,
  which is the security identity, and capability-bearing `Profile` records attach to
  it (player profile, coach profile, …). A parent who also plays is one account with
  one role and two profiles. BR-004 therefore holds literally in the schema — AC-15
  stands as written — and US-01.03 / US-01.06 are satisfied through profiles rather
  than through a second role.
- **Q-01.05 — email verification is required before first sign-in.** AC-1 is amended
  below: an Active but unverified account cannot sign in. There is no
  authenticated-but-unverified state.
- **G-22 — thresholds pinned to OWASP-aligned defaults.** Password: minimum 12
  characters, no composition rules, no rotation, maximum 4096 bytes accepted and
  hashed unmodified, rejected against a common-password blocklist. Sign-in throttle:
  5 failures per 15 minutes per account, plus 20 per hour per source. Password reset
  requests and verification resends: 3 per hour per account, 10 per hour per source.
  AC-19 and AC-20 are testable against these numbers.
- **G-08 — first Super Admin created by console command.** An
  `app:create-super-admin` command creates the bootstrap account, prompting
  interactively and falling back to environment variables when run non-interactively.
  No account-creating side effect lives in migration history. The command is also the
  recovery path if every Super Admin is lost. See AC-25.

## Open questions

Non-blocking — a documented default carries the design; the answer should be recorded
before release:

- **Q-01.07 (P2, client)** — Session lifetime: 1, 7, or 30 days? **Working default for
  the design: 8 hours of inactivity, with no "remember me" in S1.** AC-7 is satisfied
  by any of the offered numbers; changing it is a configuration change, not a design
  change.
- **Q-01.04 (P1, client)** — The full set of transactional emails. S1 needs only two
  (password reset, email verification); the wider list matters from S2 onward.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-001 Email/password authentication, all 4 roles | AC-1, AC-2, AC-3 |
| FR-002 Password hashing, email uniqueness | AC-4, AC-5 |
| FR-003 Session management (login, logout, expiry) | AC-2, AC-6, AC-7, AC-8 |
| FR-004 Password reset (1 hour) | AC-9, AC-10, AC-11, AC-12 |
| FR-005 Email verification (24 hours) | AC-1, AC-2, AC-13, AC-14 |
| FR-006 RBAC, role dashboards, backend enforcement | AC-15, AC-16, AC-17, AC-18 |
| FR-007 Rate limiting, CSRF | AC-19, AC-20, AC-21 |
| NFR-007 Accessibility (WCAG 2.1 AA) | AC-22 |
| NFR-008 Mobile / responsive | AC-23 |
| NFR-009 Session security | AC-7, AC-8, AC-21 |
| BR-005 Only Super Admin creates trainers (bootstrap) | AC-25 |

Slice S1 is done when AC-1 … AC-25 hold. The four blocking open questions were
answered on 2026-08-18 and are recorded under "Resolved decisions"; the two remaining
open questions are non-blocking and carry documented defaults.
