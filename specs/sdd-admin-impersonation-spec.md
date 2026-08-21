# Spec: Super Admin Impersonation and Audit (Epic-01, slice S6)

> Naming note: filed as `sdd-admin-impersonation-spec.md` to satisfy this repo's
> file-naming hook (`.claude/hooks/file-naming-validator.sh`), consistent with S2–S5's
> `sdd-*` pairs. The feature slug is `admin-impersonation` everywhere else (this file's
> body, the architecture file to follow, and its `specs/MANIFEST.md` row).
>
> Scope: **slice S6 only**, covering exactly one user story from
> `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`: US-01.07 (Super Admin
> Impersonates User), lines 383–404, including its own "Security Requirements"
> sub-block. Per the requirements analysis, this is the last remaining unbuilt story in
> Epic-01 — US-01.01–06, 08–10 are shipped or explicitly deferred to Epic-02. Source:
> `tasks/TASK-006/requirements-analyst-requirements.md`'s FR-001…FR-008, NFR-001…NFR-003,
> BR-001…BR-004, and its Gap Analysis.
>
> Builds on four shipped, frozen slices: `specs/auth-foundation-spec.md` /
> `-architecture.md` (S1: firewall-centric security, single-role `User`,
> `EquatableInterface`-based session invalidation), `specs/sdd-user-management-spec.md` /
> `-architecture.md` (S2: `AccountEvent`/`AccountEventRecorder` — the write-once audit
> table this slice's report reads from — and the Users directory/`Admin\UserController`
> this slice's "Impersonate" action is launched from), and S3/S4/S5's association and
> role conventions (unaffected by this slice; noted only for the frozen-config caution
> below). Verified directly against the current `config/packages/security.yaml`,
> `src/Controller/Admin/UserController.php`, `src/Entity/AccountEvent.php`,
> `src/Enum/AccountEventType.php` — no impersonation code, no `switch_user` firewall key,
> exists anywhere in the repo today (see "Ground truth confirmed" below).
> This document answers *what* and *why*. The design lives in
> `specs/sdd-admin-impersonation-architecture.md` (not yet written).

## Ground truth confirmed against source

- **No impersonation code exists anywhere.** A repo-wide scan for `Impersonat|switch_user`
  matches nothing except Symfony's own reference docblock. No `switch_user` key is
  configured under the `main` firewall in `config/packages/security.yaml`.
- **`security.yaml`'s `role_hierarchy` already names this slice by omission.** Its
  comment states `ROLE_SUPER_ADMIN` deliberately does *not* inherit `ROLE_TRAINER` and
  friends, because that inheritance "would give a Super Admin silent, unaudited reach
  into a trainer's workspace. That reach is impersonation, which is S6, and it is
  logged." Impersonation was always intended as a distinct, explicitly audited
  mechanism, not a role-inheritance side effect.
- **`AccountEvent`'s own docblock commits to being this slice's audit backbone.** It
  states it is "write-once, same shape and rationale as `AuthEvent` (a table, not a log
  channel, because **S6 reports over this**)." New `AccountEventType` cases are the
  expected extension point for the audit trail this spec's report reads.
- **`Admin\UserController`** (`#[IsGranted('ROLE_SUPER_ADMIN')]`, `/admin/users`) is the
  existing Users directory this slice's "Impersonate" action attaches to, per the epic's
  own "From Users tool, click 'Impersonate' button on user row." No such action exists
  on it today.
- **Nothing about impersonation session state (start time, still-active, remaining TTL)
  exists yet.** `AccountLifecycleService`/`AccountEventRecorder` (S2) are the only
  reusable session/audit primitives; whichever concrete shape answers "is a session
  active right now" (a dedicated table vs. session-only state) is an architecture
  decision — this spec's acceptance criteria describe only the observable behavior a
  Super Admin, an impersonated user, and a compliance reviewer must see, not the schema.

## Problem

Every prior Epic-01 slice gives each role its own login and its own permission set, but
gives a Super Admin no way to see the platform as another user without asking that user
to share their own session — which is exactly the "silent, unaudited reach" S1's own
`security.yaml` comment says impersonation must never be. Support and troubleshooting
routinely need a Super Admin to view/act as a specific Trainer, Coach, or Player exactly
as that user would see it, but until this slice there is no switch mechanism, no visible
indicator that a session is impersonated, no automatic end to that access, no rule
stopping a Super Admin from reaching into *another* Super Admin's account, and no record
of who did this, to whom, when, or for how long — so nothing in this feature is safe to
ship without also shipping its own audit trail and report.

## User scenarios

1. **A Super Admin** wants to see the platform exactly as a specific Trainer sees it, to
   troubleshoot an issue that Trainer reported.
   Path: Users directory → click "Impersonate" on that Trainer's row → confirmation
   modal reads "View platform as [User Name] ([Role])?" → confirm → every subsequent
   page renders, and every access-control decision behaves, as if that Trainer — not the
   Super Admin — is signed in.
2. **A Super Admin, mid-impersonation**, wants a constant, unmissable reminder they are
   not viewing as themselves, and a one-click way back.
   Path: a sticky banner ("Viewing as [User Name] | Exit Impersonation") is visible on
   every authenticated page for the duration → clicking "Exit Impersonation" returns them
   to their own Super Admin session and view immediately.
3. **A Super Admin** tries to impersonate another Super Admin account (by mistake or by
   probing).
   Path: clicking "Impersonate" on a `ROLE_SUPER_ADMIN` row is refused with a validation
   error before any switch happens; no impersonation session is ever created for that
   attempt.
4. **A Super Admin** starts impersonating and then forgets to exit.
   Path: at the 1-hour mark from the start of the session, the very next request (or a
   background mechanism) forces the session back to the Super Admin's own view without
   requiring the explicit "Exit Impersonation" click.
5. **A Super Admin, while impersonating**, performs an action the impersonated user's
   role permits.
   Path: the action succeeds exactly as if the impersonated user had performed it, but
   any audit trail the app writes for that action carries the real Super Admin's
   identity as actor context, not only the impersonated user's.
6. **A Compliance-minded Super Admin** wants to review who impersonated whom, and for how
   long, across the platform.
   Path: an "Impersonation History" report lists every session (actor, subject, start,
   end, duration), filterable at minimum by actor and/or subject and by date range.

## Acceptance criteria

**Starting impersonation (US-01.07, FR-001, FR-004)**

- [ ] **AC-1** A Super Admin sees an "Impersonate" action on every non-Super-Admin row in
  the Users directory. (FR-001)
- [ ] **AC-2** Clicking "Impersonate" shows a confirmation reading "View platform as
  [User Name] ([Role])?" before anything switches; the session is not switched until
  that confirmation is accepted. (FR-001)
- [ ] **AC-3** A Super Admin has no "Impersonate" action available, and any direct
  attempt to trigger impersonation of a `ROLE_SUPER_ADMIN` account (including a forged
  request bypassing the UI) is refused with a validation error; no impersonation session
  is created for that attempt. (FR-004, BR-002)

**Impersonated view (FR-002, FR-003, FR-007)**

- [ ] **AC-4** After confirming, every page renders, and every access-control decision
  behaves, exactly as if the impersonated user — not the Super Admin — were signed in:
  navigation, visible data, and permitted/denied actions all match the impersonated
  user's own role and account. (FR-002, NFR-002)
- [ ] **AC-5** A sticky banner reading "Viewing as [User Name] | Exit Impersonation" is
  present on every authenticated route for the duration of the session, and is
  distinguishable from ordinary chrome by more than color alone (e.g. an icon or label,
  not color-only). (FR-003)
- [ ] **AC-6** Clicking "Exit Impersonation" on the banner ends the session immediately
  and returns the browser to the Super Admin's own view, with the Super Admin's own
  original permissions restored. (FR-003)
- [ ] **AC-7** Any action performed while impersonating that itself produces an audit or
  attribution record elsewhere in the app records the real Super Admin's identity as
  actor (an `admin_id`-equivalent context), not the impersonated user alone. (FR-007)

**Expiry (FR-005, BR-003)**

- [ ] **AC-8** An impersonation session that is not explicitly exited is force-ended no
  later than 1 hour after it started; the next request after that mark (or a background
  mechanism) returns the browser to the Super Admin's own view without requiring the
  "Exit Impersonation" click. (FR-005)
- [ ] **AC-9** Every impersonation session ends exactly once, by exactly one of two
  reasons — explicit exit or 1-hour timeout — and is never left open indefinitely, and
  never recorded as ended by both reasons. (BR-003)

**Audit and compliance report (FR-006, FR-008, BR-001, BR-004)**

- [ ] **AC-10** Every impersonation session, once started, produces a queryable record
  with actor (the Super Admin), subject (the impersonated user), a start timestamp, and —
  once ended — a non-null end timestamp and computed duration. (FR-006, BR-004)
- [ ] **AC-11** There is no path to start an impersonation session that skips producing
  its audit record; an impersonation attempt that is refused (AC-3) creates no session
  and no "started" audit record for that attempt. (BR-004)
- [ ] **AC-12** Only a user with `ROLE_SUPER_ADMIN` can initiate impersonation or view the
  "Impersonation History" report; every other role gets a server-side (not merely
  UI-hidden) refusal on both. (BR-001)
- [ ] **AC-13** The "Impersonation History" report lists impersonation sessions with
  actor, subject, start time, end time, and duration, and is filterable at minimum by
  actor and/or subject and by a date range. (FR-008)
- [ ] **AC-14** The report's session list reflects reality at read time: a session that
  ended by timeout and one that ended by explicit exit are both shown as ended, with a
  non-null end time and duration; a still-open session (if shown at all) is
  distinguishable from an ended one. (FR-008, NFR-001)

## Edge cases

| Case | Expected |
|---|---|
| A Super Admin clicks "Impersonate" on their own row | Refused the same way as impersonating another Super Admin (AC-3) — a Super Admin is never a valid target, including themselves. |
| Two impersonation attempts against the same target, from two Super Admin sessions, at the same moment | Both are independent sessions with independent actors; nothing in this slice serializes concurrent impersonation of one target by different admins, and neither attempt is silently dropped. |
| A Super Admin already impersonating clicks "Impersonate" again on a different row | Not designed as a nested/stacked impersonation in this slice; the epic describes exactly one active impersonation per admin session at a time — a second attempt while one is active is treated as invalid, not as a silent stack, pending architecture confirmation (see Open questions). |
| The impersonated user's account is deactivated while the impersonation session is active | Not addressed by the epic text; flagged, not designed here (see Open questions) — most likely a forced end of the session, consistent with this project's existing deactivation-ends-sessions pattern from S2. |
| The Super Admin (actor) is deactivated or logs out while impersonating | Same as above — flagged, not designed here (see Open questions). |
| The 1-hour timeout elapses while the impersonated browser tab is idle with no further requests | The session is force-ended on the *next* request past the 1-hour mark, not by a guaranteed instantaneous cutoff; the report must still show a correct end time and duration once that next request (or a background sweep) closes it. |
| A Super Admin exits impersonation, then immediately re-impersonates the same target | Creates a brand-new, independent session and a brand-new audit record — never resumes or merges with the just-ended one. |
| The "Impersonation History" report is filtered by a date range with no matching sessions | Shows an empty result, not an error. |

## Out of scope

- **Any impersonation of, or by, a `ROLE_SUPER_ADMIN` account as target.** Absolute per
  BR-002/AC-3 — no override, no "impersonate a Super Admin with extra confirmation" path
  exists or is designed here.
- **Rate limiting / abuse controls on the impersonate action itself.** Not named in the
  epic's security requirements; explicitly flagged rather than assumed either way — see
  Open questions.
- **Nested/stacked impersonation** (a Super Admin impersonating while already
  impersonating). The epic's language ("view the platform as any user") does not
  describe or require this; not designed here beyond the edge-case row above flagging it
  for architecture confirmation.
- **Any change to `security.yaml`'s `role_hierarchy` or to any other slice's firewall
  behavior.** This slice may add configuration (e.g. a `switch_user` key) but must not
  alter the existing hierarchy or any other route's access control.
- **Any new report beyond "Impersonation History."** No dashboard, export, or alerting
  on impersonation activity is designed here.

## Open questions

None of the items below change the acceptance criteria above if answered differently —
each AC describes observable behavior, not implementation — but each is flagged because
it is load-bearing for the architecture phase.

- **`switch_user` vs. a fully custom session swap (central decision, flagged but not
  locked).** The requirements analysis recommends building on Symfony's native
  `switch_user` firewall listener rather than a fully custom session-swap mechanism: it
  already restricts who can switch to whom via a role/voter check (mapping cleanly onto
  BR-001/BR-002), already exposes `IS_IMPERSONATOR`/`switch_user` security attributes and
  a `SwitchUserToken` distinguishing the real vs. impersonated user (satisfying FR-007's
  "real actor attribution" for free via `SwitchUserToken::getOriginalToken()`), and
  already fires `security.switch_user` events a listener can hook to write the
  `AccountEvent` audit rows for FR-006/AC-10. What `switch_user` does **not** give for
  free: the 1-hour hard expiry (FR-005/AC-8) and a queryable "is this session currently
  active, and what's its remaining TTL" state before it ends (NFR-001/AC-14) — its token
  lives session-side with no built-in TTL or persisted session record, so this slice will
  still need either a session-stored `switchedAt` timestamp checked by a listener, or a
  dedicated `ImpersonationSession` row updated at start/end that a sweep or listener
  treats as authoritative for expiry. **This is a resolved recommendation, not a final
  decision** — it touches the frozen-ish `security.yaml` firewall config three other
  slices depend on being stable, so the architecture phase must explicitly confirm or
  override it before implementation begins.
- **`AccountEventType` extension vs. a dedicated `ImpersonationSession` entity.**
  `AccountEvent`'s own docblock supports new `IMPERSONATION_STARTED`/
  `IMPERSONATION_ENDED` cases being sufficient for the *history report* (AC-13, a closed,
  past-tense read), but answering "is there an impersonation session active right now,
  with what remaining TTL" (needed for the banner and for enforcing AC-8 server-side) is
  awkward from a write-once append log alone. A dedicated mutable
  `ImpersonationSession` entity, also mirrored into two `AccountEvent` rows on start/end
  for report consistency with every other slice's audit mechanism, is the shape to
  evaluate in architecture. Flagged, not resolved.
- **Exact filter/columns for "Impersonation History."** The epic names the report but not
  its filter set. AC-13 requires at minimum actor and/or subject and a date range; whether
  it also needs keyset pagination matching S2's Users-directory precedent
  (`UserSearchCriteria`) is left for architecture to confirm.
- **In-flight impersonation on actor or target deactivation/logout mid-session.** Not
  addressed by the epic text; the edge-case rows above name the two cases but no rule is
  written here — most likely a forced end of the session on either party's
  deactivation, consistent with S2's existing `EquatableInterface`-based session
  invalidation, but `SwitchUserToken`'s shape may need its own check. Needs an explicit
  rule in architecture.
- **Nested/stacked impersonation.** Whether a second "Impersonate" attempt while one is
  already active is refused outright, silently replaces the first, or is disallowed by
  simply hiding the action is not specified by the epic; flagged for architecture, not
  assumed either way.
- **Confirmation modal mechanics** — whether AC-2's "confirmation modal" is a real
  intermediate GET+POST step or a same-page JS/Turbo confirm, consistent with this
  project's existing Twig/Turbo/Stimulus conventions — left for frontend design, not a
  spec blocker.
- **Rate limiting on the impersonate action.** Not mentioned in the epic's security
  requirements, but every other write-heavy, sensitive S1–S5 action in this codebase
  (login throttling, S3's mail rate limiter) got one; flagged as an explicit "yes or no"
  decision for architecture rather than assumed either way.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-001 Trigger impersonation from Users directory, with confirmation | AC-1, AC-2 |
| FR-002 Session switch matches impersonated user exactly | AC-4 |
| FR-003 Sticky banner + exit control | AC-5, AC-6 |
| FR-004 Cannot impersonate a Super Admin | AC-3 |
| FR-005 1-hour expiry or explicit exit | AC-8, AC-9 |
| FR-006 Full audit record (actor/subject/start/end/duration) | AC-10, AC-11 |
| FR-007 Actions during impersonation attributable to the real Super Admin | AC-7 |
| FR-008 "Impersonation History" report | AC-13, AC-14 |
| NFR-001 Cheap "is this active right now" state, not log-scan-derived | AC-14 |
| NFR-002 Impersonated view uses the target's real permissions, not the admin's | AC-4 |
| BR-001 Only Super Admins initiate impersonation / view the report | AC-12 |
| BR-002 A Super Admin can never be a target | AC-3, edge case row 1 |
| BR-003 Exactly one end, explicit or timeout, never both, never open | AC-9 |
| BR-004 Every session logged; no off-the-record impersonation | AC-10, AC-11 |

Slice S6 is done when AC-1 … AC-14 hold, on top of S1's AC-1…AC-25, S2's AC-1…AC-24, S3's
AC-1…AC-21, S4's AC-1…AC-24, and S5's AC-1…AC-16 continuing to hold (regression, not just
addition). This slice is also the last unbuilt story in Epic-01 per the requirements
analysis — no further Epic-01 slice is expected after S6 ships.
