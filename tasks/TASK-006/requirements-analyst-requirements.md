# S6 — Super Admin Impersonation and Audit — Requirements

## Overview
Epic-01 slice S6: implement US-01.07 "Super Admin Impersonates User" — a Super Admin can
temporarily view/act on the platform as any non-Super-Admin user, with a sticky exit banner,
a hard 1-hour session ceiling, an absolute block on impersonating other Super Admins, and full
audit logging (who/whom/start/end/duration) surfaced through an "Impersonation History" report.
This is the only remaining unbuilt story in Epic-01 (US-01.01–06, 08–10 are shipped or
explicitly deferred to Epic-02 per S5's spec).

## Source
- `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`, US-01.07 (lines 383–404)
- `specs/sdd-user-management-spec.md` / `-architecture.md` (S2: Users directory, AccountEvent,
  AccountEventRecorder, AccountLifecycleService)
- Live repo scan of `config/packages/security.yaml`, `src/Controller/Admin/UserController.php`,
  `src/Entity/AccountEvent.php`, `src/Enum/AccountEventType.php`

## Confirmed current state (via direct repo inspection)
- **No impersonation code exists anywhere.** `grep -rn "Impersonat\|switch_user"` across
  `src`, `config`, `templates` matches nothing except Symfony's own reference docblock in
  `config/reference.php`. No `switch_user` key is configured under the `main` firewall in
  `config/packages/security.yaml`.
- `security.yaml`'s `role_hierarchy` block carries a comment that already names this slice:
  *"ROLE_SUPER_ADMIN deliberately does NOT inherit ROLE_TRAINER and friends: inheritance would
  give a Super Admin silent, unaudited reach into a trainer's workspace. That reach is
  impersonation, which is S6, and it is logged."* This is a strong signal from S1's own author
  that impersonation was always intended to be a distinct, explicitly-audited mechanism, not a
  side effect of role inheritance.
- `src/Entity/AccountEvent.php`'s class docblock states: *"Write-once, same shape and rationale
  as `AuthEvent` (a table, not a log channel, because **S6 reports over this**)."* This is a
  direct, load-bearing confirmation that the existing `AccountEvent`/`AccountEventRecorder`
  mechanism (S2) was designed to be the audit backbone this slice's "Impersonation History"
  report reads from — new `AccountEventType` cases are the expected extension point, not a new
  parallel audit table.
- `src/Controller/Admin/UserController.php` (`#[IsGranted('ROLE_SUPER_ADMIN')]`, `/admin/users`)
  is the existing Users directory/tool that US-01.07 says impersonation is launched from
  ("From Users tool, click 'Impersonate' button on user row"). No "Impersonate" action exists
  on it today.
- `AccountLifecycleService` and `AccountEventRecorder` (S2) are the only reusable session/audit
  primitives; nothing about impersonation state (start time, still-active, expiry) exists yet.

## Functional Requirements
1. **FR-001**: Super Admin can trigger impersonation of any non-Super-Admin user from a button
   on the Users directory row.
   - Acceptance: Confirmation modal reading "View platform as [User Name] ([Role])?" appears
     before the switch takes effect.
   - Priority: High
2. **FR-002**: On confirm, the Super Admin's session is switched to view/act as the target user,
   with navigation, permissions, and data matching the impersonated user exactly.
   - Acceptance: Every page renders and every access-control decision behaves as if the
     impersonated user, not the Super Admin, were signed in.
   - Priority: High
3. **FR-003**: A sticky, color-coded banner ("Viewing as [User Name] | Exit Impersonation") is
   visible on every page while impersonating.
   - Acceptance: Banner present on all authenticated routes during an active impersonation
     session; clicking "Exit Impersonation" returns the Super Admin to their own view.
   - Priority: High
4. **FR-004**: A Super Admin cannot impersonate another Super Admin account.
   - Acceptance: Attempting to impersonate a `ROLE_SUPER_ADMIN` target row is rejected with a
     validation error; no impersonation session is created.
   - Priority: High (security-critical)
5. **FR-005**: Impersonation sessions expire automatically after 1 hour if not explicitly exited.
   - Acceptance: At the 1-hour mark, the next request (or a background check) forces exit back
     to the Super Admin's own session/view.
   - Priority: High
6. **FR-006**: Every impersonation session is fully audited: actor (Super Admin), subject
     (impersonated user), start time, end time, duration, and reason for end (explicit exit vs.
     timeout).
   - Acceptance: A queryable record exists for every session, closed exactly once, with
     non-null start and end timestamps once ended.
   - Priority: High
7. **FR-007**: All actions taken during an impersonation session are attributable to the Super
   Admin as actor (admin_id context), not silently attributed to the impersonated user alone.
   - Acceptance: Any audit trail written elsewhere in the app during an active impersonation
     (e.g. future AccountEvents, if any exist for actions a Super Admin could take as the
     target) carries the real actor's identity in context, not just the target's.
   - Priority: High
8. **FR-008**: An "Impersonation History" report is available to Super Admins for compliance
   review, listing who impersonated whom, when, and for how long.
   - Acceptance: A page/route lists completed (and, arguably, in-progress) impersonation
     sessions with actor, subject, start, end, duration; filterable at minimum by actor and/or
     subject and date range (exact filter set is an open question, see Gap Analysis).
   - Priority: High

## Non-Functional Requirements
1. **NFR-001**: Impersonation-session state must answer "is this session still active right
   now, and how much time is left" cheaply on every request (banner + timeout enforcement) —
   not just after the fact from a write-once event log.
   - Metric: no full-table scan of a growing audit log needed to answer "is user X currently
     impersonating," and the 1-hour deadline must be enforced without relying on a fire-and-
     forget event as the single source of truth of "in progress."
2. **NFR-002**: The impersonation switch must not weaken authorization anywhere — the
   impersonated view's permissions must be the target's real permission set, not the Super
   Admin's, for the whole duration.
3. **NFR-003**: The mechanism must be consistent with this project's existing security
   conventions (default-deny access_control, one role per user, `IsGranted` attributes,
   `EquatableInterface`-based session invalidation) rather than introducing a parallel
   authentication path.

## Business Rules
1. **BR-001**: Only `ROLE_SUPER_ADMIN` users may initiate impersonation.
2. **BR-002**: A `ROLE_SUPER_ADMIN` account can never be an impersonation *target* (FR-004),
   regardless of who the actor is.
3. **BR-003**: An impersonation session has exactly one end — explicit exit or 1-hour timeout —
   never both, and never left open indefinitely.
4. **BR-004**: Every impersonation session must be logged; there is no "off the record"
   impersonation path.

## Security Requirements (from the epic spec, called out separately per its own structure)
- CANNOT impersonate other Super Admin accounts (validation error) — FR-004/BR-002.
- All actions during impersonation logged with `admin_id` context — FR-007.
- Impersonation session expires after 1 hour (or explicit exit) — FR-005/BR-003.
- Audit report available: "Impersonation History" for compliance — FR-008.

## Task Breakdown

### Entities / Schema
| Entity | Properties | Relations | Notes |
|--------|------------|-----------|-------|
| `AccountEventType` (extend) | new cases: `IMPERSONATION_STARTED`, `IMPERSONATION_ENDED` (values TBD in architecture, e.g. distinguishing timeout vs. explicit exit in `context`) | writes actor=Super Admin, subject=target, via existing `AccountEventRecorder` | Satisfies the "audit report reads AccountEvent" contract the entity docblock already commits to |
| Impersonation session state (open question) | admin_user_id, target_user_id, started_at, ends_at/expires_at, ended_at, end_reason | FK to `app_user` twice (actor/target) | Needed to answer NFR-001's "is this active right now" — either a dedicated `ImpersonationSession` entity/table, or symfony session attributes + a scheduled/lazy sweep that also emits the AccountEvent pair. Flagged as the central open question for architecture phase (see below). |

### Services
| Service | Purpose | Methods |
|---------|---------|---------|
| Impersonation orchestration service (name TBD) | Start/end an impersonation session, enforce BR-002/BR-003, write audit events | `start(actor, target)`, `end(session, reason)`, `isActive(...)`, `remainingSeconds(...)` |
| Impersonation history/report query (repository or service) | Read-side for the compliance report | `search(criteria): paginated list` |

### Controllers
| Controller | Endpoints | Purpose |
|------------|-----------|---------|
| `Admin\UserController` (extend) or a new `Admin\ImpersonationController` | `POST /admin/users/{id}/impersonate` (start, with confirmation step), `POST /admin/impersonation/exit` (or `/_switch_user` if native) | Start/stop impersonation from the Users directory row |
| Admin impersonation history controller | `GET /admin/impersonation-history` | Compliance report, Super-Admin-only |

### Frontend (server-rendered)
- Confirmation modal/page: "View platform as [User Name] ([Role])?" before switching.
- Sticky, color-coded banner partial rendered globally while impersonating (e.g. via a Twig
  global / event listener injecting it into the base layout), with an "Exit Impersonation"
  control.
- Impersonation History report template: table of sessions (actor, subject, start, end,
  duration, end reason), Super-Admin-only route.
- Accessibility: banner must be perceivable without relying on color alone (per epic's "color-
  coded (e.g. red/orange)" cue — needs a text/icon cue too, not color-only), keyboard-operable
  exit control, report table with proper headers/scope.

### Testing Tasks
- Integration: start impersonation happy path, banner presence, exit returns to Super Admin
  view with original permissions restored.
- Integration: cannot start impersonation targeting a `ROLE_SUPER_ADMIN` (validation error,
  no session created, no audit event beyond the rejected attempt if that's itself logged).
- Integration: 1-hour expiry forces exit without explicit action.
- Integration: actions taken while impersonating are attributable to the real Super Admin
  (admin_id context), not just the target.
- Integration: Impersonation History report lists sessions with correct actor/subject/
  start/end/duration; Super-Admin-only access.
- Unit: session/expiry calculation logic, end-reason determination.

## Gap Analysis (open questions — none blocking, all flagged for architecture phase)
- [ ] **switch_user vs. custom session swap** (central decision). Confirmed via direct
  read of `config/packages/security.yaml`: no `switch_user` key exists under the `main`
  firewall today. Symfony's native `switch_user` firewall listener is purpose-built for
  exactly this feature set: it already restricts "who can switch to whom" via a role/voter
  check (`role: ROLE_ALLOWED_TO_SWITCH` + a voter, which maps cleanly onto BR-001/BR-002's
  "Super Admin only, never onto another Super Admin"), already exposes `IS_IMPERSONATOR`/
  `switch_user` security attributes and a `SwitchUserToken` distinguishing the real vs.
  impersonated user (which is exactly FR-007's "actions attributable to the real admin"
  requirement — Symfony surfaces the original token via
  `SwitchUserToken::getOriginalToken()`), and already fires
  `SwitchUserEvent`s (`security.switch_user`) that a listener can hook to write the
  `AccountEvent` audit rows for FR-006. Recommendation: **build on native `switch_user`**
  rather than a fully custom session-swap — it satisfies most of FR-002/FR-004/FR-007's
  mechanics for free and keeps this slice consistent with the project's existing
  firewall-centric security conventions (NFR-003). What `switch_user` does **not** give
  for free: the 1-hour hard expiry (FR-005) and an "is this session currently active"
  queryable state for the report before it ends (NFR-001) — Symfony's switch-user token
  lives in the session with no built-in TTL or persisted session record. This slice will
  need either (a) a session-stored `switchedAt` timestamp checked on a listener that force-
  exits past 1 hour, or (b) a dedicated `ImpersonationSession` row updated at start/end
  that a scheduled sweep or listener also treats as authoritative for expiry — likely (b),
  matching NFR-001. **This is a resolved recommendation, not a final decision** — needs
  explicit confirmation in the architecture phase given it touches the frozen-ish
  security.yaml firewall config three other slices depend on being stable.
- [ ] **AccountEventType extension vs. dedicated entity.** The `AccountEvent` docblock's own
  claim ("a table, not a log channel, because S6 reports over this") strongly suggests new
  `IMPERSONATION_STARTED`/`IMPERSONATION_ENDED` cases are expected and sufficient for the
  *history report* (a closed, past-tense read). But answering "is there an impersonation
  session active right now, and what's its remaining TTL" (needed for the banner countdown
  and for enforcing FR-005 server-side) is awkward from a write-once append log alone —
  you'd have to find the latest START with no matching END for a given actor. A dedicated
  `ImpersonationSession` entity (mutable: `started_at`, `expires_at`, `ended_at`,
  `end_reason`) that is *also* mirrored into two `AccountEvent` rows on start/end for the
  report's consistency with every other slice's audit mechanism is the shape to evaluate in
  architecture. Flagged, not resolved.
- [ ] **Exact filter/columns for "Impersonation History."** The epic spec names the report but
  not its filter set. Given S2's Users directory precedent (`UserSearchCriteria` with
  role/status/query/keyset pagination), a similar actor/subject/date-range filter with keyset
  pagination is the natural fit, but needs explicit confirmation.
  end.
- [ ] **What happens to in-flight impersonation on Super-Admin actor deactivation/logout, or
  on the target being deactivated/deleted mid-session** — not addressed by the epic text;
  needs an explicit rule (most likely: force-end the session, matching this project's existing
  "deactivation ends open sessions for free via `EquatableInterface`" pattern from S2, but
  impersonation's `SwitchUserToken` shape may need its own check).
- [ ] **Whether the confirmation modal is a real intermediate GET+POST step or a same-page JS
  confirm()** — epic text says "Confirmation modal," consistent with this project's existing
  Twig/Turbo/Stimulus conventions rather than a full page nav; left for frontend design.
- [ ] **Rate limiting / abuse controls on the impersonate action itself** — not mentioned in
  the epic's security requirements, but worth a explicit "no" or "yes" decision given every
  other write-heavy, sensitive S1–S5 action in this codebase got one (login throttling, S3's
  mail rate limiter). Flagged, not assumed either way.

## Next Steps (Suggested)
Do not auto-execute — presented for the orchestrating flow to choose:
- brainstorm `TASK-006: S6 Super Admin impersonation and audit requirements analyzed — see
  tasks/TASK-006/requirements-analyst-requirements.md` — recommended given the switch_user vs.
  custom decision and the AccountEventType-vs-dedicated-entity question both benefit from
  collaborative design dialogue before architecture commits.
- architect `TASK-006: ...` — viable if the switch_user recommendation above is accepted as-is
  and the team wants to skip straight to architecture.
- writing-plans `TASK-006: ...` — not recommended yet; design (spec + architecture) has not
  been written for this slice.
