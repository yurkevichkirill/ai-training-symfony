# Project: Symfony Layered Architecture Accelerator

An AI-assisted development accelerator for Symfony 7.4 LTS and Symfony 8.1 projects. It provides native Claude Code, Cursor, and Codex workflows centered on pragmatic Controller -> Service -> Repository architecture and Symfony conventions.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | - |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | - |
| frontend-design-spec.md | Pages, components, state management | architect-architecture, api-designer-spec | - |
| docs-generator-implementation.md | Build process, deployment, tooling | - | - |
| auth-foundation-spec.md | Epic-01 slice S1: core authentication and authorization (FR-001…FR-007) — problem, scenarios, AC-1…AC-25, edge cases, resolved decisions | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-18 |
| auth-foundation-architecture.md | Epic-01 slice S1 design: User/token/audit schema and the frozen User↔Profile contract, firewall and uniform-failure authentication, default-deny authorization, reset and verification token services, rate limiting, queued mail, auth event logging, accessible Twig surface | auth-foundation-spec.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-18 |
| sdd-user-management-spec.md | Epic-01 slice S2: Users directory, Super-Admin-creates-trainer (invitation flow), profile editing + photo upload, deactivation, GDPR deletion — problem, scenarios, AC-1…AC-24, edge cases, resolved decisions (G-10, G-14, G-15, G-17, G-18) | auth-foundation-spec.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-20 |
| sdd-user-management-architecture.md | Epic-01 slice S2 design: builds the frozen Profile hierarchy (Profile/ProfileTrainer), AccountInvitation (reuses S1 token discipline), AccountEvent/AccountDeletionLog, anonymize-in-place GDPR deletion, keyset-paginated Users directory | sdd-user-management-spec.md, auth-foundation-architecture.md | 2026-08-20 |
| sdd-sharelink-invitations-spec.md | Epic-01 slice S3: the ShareLink invitation system — US-01.02 (player registration via ShareLink, multi-trainer association) and US-01.08 (trainer invites coach) only — problem, scenarios, AC-1…AC-21, edge cases, resolved decisions (Parent/Child deferred, coach account status, coach exclusivity window, no email-verification exception) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, sdd-user-management-spec.md, auth-foundation-spec.md | 2026-08-20 |
| sdd-sharelink-invitations-architecture.md | Epic-01 slice S3 design: two separate link entities (static per-trainer PlayerShareLink with a monotonic usage counter; single-use 7-day CoachInvitation addressed to an email, reusing SelectorVerifierTokenFactory), two separate association entities (TrainerPlayerAssociation with UNIQUE(trainer,player) for idempotency; TrainerCoachAssociation with a partial unique index on the active coach for exclusivity), ProfilePlayer as the second Profile subtype, two-phase registration with compensating cleanup over UserAccountService, voter + service-guard role/email integrity, one new source rate limiter and three email templates | sdd-sharelink-invitations-spec.md, sdd-user-management-architecture.md, auth-foundation-architecture.md | 2026-08-20 |
| sdd-player-family-availability-spec.md | Epic-01 slice S4: parent creates/manages child profiles, child-trainer connection management (add/remove), context-selector data needs for parent "Me"+children and child's own-trainers-only view, child login permission ceiling + ShareLink-blocking/parent-notification flow, and per-player/per-child Best Times availability with trainer-facing view + filter — problem, scenarios, AC-1…AC-24, edge cases, open questions (none blocking, several flagged for the architecture phase — notably G-07's child-authentication shape and the "My Trainers" set for AC-3) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-001/requirements-analyst-requirements.md, sdd-sharelink-invitations-spec.md, sdd-user-management-spec.md, auth-foundation-spec.md | 2026-08-20 |
| sdd-player-family-availability-architecture.md | Epic-01 slice S4 design: a child is a normal `PLAYER` `User` marked by a new `child_account` row (no new role, no frozen-entity edit) with a derived non-deliverable placeholder email until a parent enables sign-in through S2's existing AccountInvitation flow; one association writer for every family path (S3's `PlayerShareLinkService`, widened additively with an actor argument) so AC-17 reuses AC-8's mechanism; `child_trainer_request` with a pending partial unique index behind the unconditional child ShareLink block + parent notification; absence-as-Not-Available `player_availability_slot` rows with a trainer-facing summary and day/time roster filter; three voters (family, child deny-list, availability) backed by service guards; two new email templates on the existing SendEmailMessage mechanism | sdd-player-family-availability-spec.md, sdd-sharelink-invitations-architecture.md, sdd-user-management-architecture.md, auth-foundation-architecture.md | 2026-08-20 |
| sdd-coach-features-spec.md | Epic-01 slice S5: coach weekly recurring availability ("My Times") and coach-specific profile fields (bio, credentials, certifications, public-visibility checkbox) — problem, scenarios, AC-1…AC-16 (AC-9/AC-10 explicitly deferred to Epic-02), edge cases, an explicit "In scope vs. deferred" scope boundary, and open questions flagged for the architecture phase (coach-availability storage reuse vs. new table, `ProfileCoach` entity shape, credentials/certifications structure, override-log forward-compatibility with Epic-02's future `event_id`). US-01.08 (Trainer Invites Coach, shipped in S3) explicitly not re-scoped; US-01.10's trainer-assigns-coach-to-event half is named and deferred rather than built against an invented Events system, since Epic-02 does not exist in this codebase yet (confirmed by a repo-wide scan: no `Event` entity, no Epic-02 spec file) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-005/requirements-analyst-requirements.md, sdd-player-family-availability-spec.md, sdd-sharelink-invitations-spec.md, sdd-user-management-spec.md, auth-foundation-spec.md | 2026-08-20 |
| sdd-coach-features-architecture.md | Epic-01 slice S5 design: `ProfileCoach` as the fourth `Profile` subtype (bio/credentials/certifications as free text plus a NOT NULL `is_public` boolean), created lazily on first save since no shipped path ever creates a coach profile; a **new** `coach_availability_slot` table that reuses S4's column shape, absence-as-not-available encoding, `WeeklyAvailability`/`TimeRange` value objects and `AvailabilityWeekFormType` verbatim but not its player-keyed entity or table; a pure `CoverageEvaluator`/`AvailabilityCoverage` three-way conflict decision with no database dependency; an append-only `coach_assignment_override` audit table storing the candidate day/start/end and evaluated coverage so each row is self-describing with no Event system in existence, and carrying **no** `event_id` column (Epic-02 adds one additively — no unique constraint spans it, the stored time can only be narrowed, and the write surface is a DTO); one `CoachVoter` keyed on `TrainerCoachAssociation` mirroring S4's `AvailabilityVoter`, backed by service guards; the coach profile form added to the existing `/profile` page beside the trainer one, and the coach availability summary added to the existing `/trainer/coaches` roster — one new route in total, no email, no Messenger message, no rate limiter, no Composer package | sdd-coach-features-spec.md, sdd-player-family-availability-architecture.md, sdd-sharelink-invitations-architecture.md, sdd-user-management-architecture.md, auth-foundation-architecture.md | 2026-08-20 |
| sdd-admin-impersonation-spec.md | Epic-01 slice S6 (the last remaining unbuilt story in Epic-01): Super Admin impersonates a user via a confirmation modal and a sticky "Viewing as X \| Exit Impersonation" banner, an absolute block on impersonating another Super Admin, a 1-hour hard expiry or explicit exit, full audit logging (actor/subject/start/end/duration) with real-actor attribution for actions taken while impersonating, and a filterable "Impersonation History" compliance report — problem, scenarios, AC-1…AC-14, edge cases, open questions flagged for the architecture phase (notably the `switch_user`-vs-custom-session-swap recommendation and `AccountEventType`-extension-vs-dedicated-entity question) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-006/requirements-analyst-requirements.md, sdd-user-management-spec.md, auth-foundation-spec.md | 2026-08-20 |
| sdd-trainer-branding-spec.md | Epic-01 slice S7 (the last remaining unbuilt item in Epic-01's in-scope MVP list): Trainer logo upload (PNG/JPG/SVG, max 2MB, preview, auto-resize) and primary brand color (hex picker, real-time preview, reset-to-default) applied org-wide to that trainer's players/coaches/parents — problem, scenarios, AC-1…AC-12, edge cases, open questions flagged for the architecture phase (notably where branding is rendered/read from given multi-trainer players/coaches, SVG upload safety, the 2MB cap vs. `FileStorage`'s shared 5MB constant, and the auto-resize/default-color mechanisms) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-007/requirements-analyst-requirements.md, sdd-user-management-spec.md, sdd-admin-impersonation-spec.md | 2026-08-20 |
| sdd-admin-impersonation-architecture.md | Epic-01 slice S6 design: impersonation **is** Symfony's native `security.switch_user` (confirmed against the installed Symfony 8.1.4 and against `SwitchUserListener`'s own source), configured with one additive firewall block — `role: ROLE_ALLOWED_TO_SWITCH` decided entirely by a new `ImpersonationVoter` that receives the *target user as its subject* (so BR-002 lives inside the mechanism), plus `target_route: app_home` because the listener would otherwise bounce the freshly impersonated session back to an admin-only URL; one new mutable `impersonation_session` table as the authority for "active right now / expiring when / ended how" (partial unique index on the actor for one-active-session-per-admin, a two-way CHECK making "closed exactly once with a reason" a database fact, no stored duration) **plus** two new `AccountEventType` cases mirroring start/end into the unified account timeline; the sticky banner from the framework's own `is_granted('IS_IMPERSONATOR')` and `impersonation_exit_path()` with no session attribute or Twig global of our own, included once in `base.html.twig`; expiry as a `kernel.request` subscriber at priority 7 (mirroring `SessionIdleSubscriber`'s reasoning from the other side of the firewall's priority 8) that short-circuits with a 302 on the single invariant "an impersonation token with no *open* row, or an expired one, is force-exited", backed by an `app:impersonation:close-expired` bookkeeping command; one guard subscriber at priority 32 requiring POST + CSRF + no nesting in front of the native GET-query trigger; real-actor attribution (AC-7) closed in one place by an additive `impersonatorUserId` context merge in S2's `AccountEventRecorder` rather than at ~20 call sites; a keyset-paginated read-only Super-Admin-only "Impersonation History" report reusing S2's criteria/page shape. No new Composer package, no email, no Messenger message, no rate limiter, no `role_hierarchy` or `access_control` change | sdd-admin-impersonation-spec.md, sdd-user-management-architecture.md, auth-foundation-architecture.md, tasks/TASK-006/requirements-analyst-requirements.md | 2026-08-20 |
| sdd-trainer-branding-architecture.md | Epic-01 slice S7 design: two additive nullable columns on the existing `ProfileTrainer` (`logo_key`, `primary_color_hex char(7)` with a `~ '^#[0-9a-f]{6}$'` CHECK) — no new table, no new `Profile` subtype, no backfill — where `NULL` in both **is** the reset-to-default mechanism; the central branding-context question resolved by establishing that this codebase has **no "current trainer portal" concept at all** (routes are prefixed by the viewer's role, never a tenant; `base.html.twig` has no header/nav; there is no Twig extension or global anywhere; S4's `PlayerContextProvider` deliberately returns a list and never selects) and scoping the slice to three explicit tiers — chrome branding only where the viewer's identity fixes exactly one trainer (a trainer; a coach, by S3's `uniq_trainer_coach_active_coach` partial index) or where a ShareLink code carries one, per-row branding wherever a *set* of trainers is rendered, and the platform default everywhere the answer would be a guess — so AC-12 holds structurally with **no ambient Twig global, no fallback, and no session-remembered "last trainer"**; **raw SVG upload refused**, against US-01.14's own wording, because `FileStorage::read()` serves `Content-Disposition: inline` with no CSP from a directly-navigable same-origin URL, making `<img>`-only rendering unenforceable and no sanitiser existing in the project (two-part condition recorded for adding it later); the 2MB cap via two defaulted trailing parameters on `FileStorage::store()` (`?int $maxBytes`, `?array $allowedMimeTypes`) so S2's photo path is unchanged in text and behaviour; AC-5's auto-resize satisfied without a server-side resize because **neither GD nor Imagick is installed** — CSS-constrained 200px rendering plus a dependency-free `getimagesize()` decompression-bomb guard; the platform default colour **found rather than invented** (`--color-primary: #0b5fae` already in `public/css/app.css`), applied as one custom-property override with `--color-primary-contrast` derived per-render by a pure WCAG relative-luminance function and `--color-focus` deliberately left platform-controlled; one `BrandingVoter` (`EDIT_BRANDING` with an explicit Super Admin clause under a flat `role_hierarchy`; a broad association-based `VIEW_BRANDING`) backed by a service guard, a new `TrainerBrandingService` rather than more methods on S2's self-service-only `ProfileService`, an anonymous logo read nested at `GET /join/{code}/logo` under S3's existing capability token, and `PROFILE_UPDATED` reused for audit. No new entity, no new `AccountEventType` case, no email, no Messenger message, no rate limiter, no Composer package | sdd-trainer-branding-spec.md, sdd-user-management-architecture.md, sdd-admin-impersonation-architecture.md, sdd-coach-features-architecture.md, sdd-sharelink-invitations-architecture.md, auth-foundation-architecture.md, tasks/TASK-007/requirements-analyst-requirements.md | 2026-08-20 |

## Key Decisions

- Target Symfony 7.4 LTS and Symfony 8.1 while detecting each consuming project's installed versions.
- Use `.agents/skills` as the configured canonical source for shared skill parity, mirror Claude and Cursor semantics natively, and keep Codex support files under `.codex`.
- Enforce Controller -> Service -> Repository pragmatically, without requiring pass-through layers or interfaces without a real boundary.
- Deliver Epic-01 in the slices of its §13 implementation order, one spec pair per slice, starting with S1 (`auth-foundation`); TASK-001 is the governed task for S1.
- S1 blocking questions resolved 2026-08-18: one role per user plus attached profiles (G-23); email verification required before first sign-in (Q-01.05); OWASP-aligned password and rate-limit thresholds (G-22); first Super Admin created by an `app:create-super-admin` console command (G-08).
- S1 (`auth-foundation`, TASK-001) shipped and fully tested 2026-08-19 (AC-1…AC-25). S2 (`user-management`, TASK-002) shipped and fully tested 2026-08-20 (AC-1…AC-24): frozen Profile hierarchy, Users directory, trainer invitation flow, profile editing, deactivation, and GDPR deletion. Combined security + code-quality review found 2 High/2 Medium fixed pre-ship (invitation surviving deactivation, GDPR email retention, orphaned photo file, admin-edit-on-deleted-account) plus 3 further High fixed (two-phase trainer-creation orphan, delete-guard concurrency race, deleted-user display name) — all with real-DB regression tests, full suite green (187 tests).
- S3 (`sharelink-invitations`, TASK-003) shipped and fully tested 2026-08-20 (AC-1…AC-21):
  player self-registration via a static per-trainer ShareLink, existing-player
  multi-trainer association, and coach invitations via a single-use 7-day link,
  built on `PlayerShareLink`/`CoachInvitation`/`TrainerPlayerAssociation`/
  `TrainerCoachAssociation`/`ProfilePlayer`. The partial-unique-index approach (verified
  working, not a risk) makes AC-16's coach exclusivity and AC-13's idempotency database
  facts rather than app-level checks. Dual code-review + security-review pass (Task 30)
  found 2 Major correctness bugs (a `usage_count` lost-update race; a blank refusal
  message on the deactivated-trainer coach path) and 4 security Mediums on the slice's
  first anonymous-writable endpoints (unthrottled coach-invite mail, email enumeration
  on registration, account pre-hijack/squatting, a one-click GET-triggered PII
  disclosure with no revoke path) — all fixed, including three product decisions
  (enumeration-resistant registration response, a same-pass account-squatting cleanup
  sweep command, and a player leave/revoke path with its own migration, mirroring the
  coach side's partial-unique-index pattern). Final regression: 278 tests, 1393
  assertions, green; schema clean; every route present and correctly gated.
- S4 (`player-family-availability`, TASK-004) spec written 2026-08-20 (AC-1…AC-24, not yet
  built): confirmed against current source that neither `ProfilePlayer` nor `User` carries
  any parent/child distinction or child-account marker today, so this slice must add
  whatever shape represents "a child" — an architecture decision, not resolved in the
  spec. No blocking open questions were found; the highest-attention flag for the design
  phase is G-07 (how a child account authenticates, given `TrainerPlayerAssociation`'s
  frozen mandatory FK to `app_user` and email's platform-wide uniqueness as the login
  identifier) and the exact "My Trainers" set AC-3's single-trainer prompt counts against.
  US-01.05 (child purchase approval) stays explicitly deferred to whenever Epic-05 exists.
- S4 (`player-family-availability`, TASK-004) design written 2026-08-20, resolving all four
  open questions the spec delegated to the architecture phase. **G-07:** a child is a normal
  `User` with `role = PLAYER` and its own `ProfilePlayer`, marked as a child by a new
  `child_account (child_user_id UNIQUE, parent_user_id)` row — rejecting a new `UserRole::CHILD`
  case (would silently revoke every `ROLE_PLAYER`-gated action AC-13 requires a child to keep),
  a `ProfileChild` subtype (a relationship is not the capability data the frozen Profile
  contract holds) and a `parent_user_id` column on `app_user` (edits an entity three slices
  depend on). Its login identifier is a derived, non-deliverable `child_<uuid>@children.invalid`
  placeholder — `app_user.email` is UNIQUE, NOT NULL and *is* the identifier — replaced by a
  real address only when a parent opts into "Enable sign-in", which reuses S2's existing
  `AccountInvitation` set-your-own-password flow rather than adding credential machinery. All
  mail about a child routes to the parent (BR-011), which also fixes an undeliverable
  `PLAYER_TRAINER_CONNECTED` message S3's code would otherwise queue to a `.invalid` address.
  **"My Trainers":** confirmed as the spec read it — the parent's own player account's active
  connections only, never the family aggregate (an aggregate makes AC-3's single-trainer prompt
  shape depend on unrelated sibling history; the ShareLink-code path already reaches any other
  trainer). **Already-connected child re-clicks a ShareLink:** unconditional block + parent
  notification, with the child branch placed before any association lookup so the narrower
  reading cannot be reintroduced by accident. **Q-01.04:** AC-16 reuses the existing
  `SendEmailMessage`/`SendEmailMessageHandler` mechanism but needs its own template file and
  copy (no existing template carries a call to action); D1d adds a second one for the child
  sign-in invitation. Three new tables (`child_account`, `child_trainer_request`,
  `player_availability_slot`), one nullable `profile_player.school` column, six
  `AccountEventType` cases, no new Composer package, and no edit to anything S1/S2/S3 froze —
  S3's already-shipped `TrainerPlayerAssociation.ended_at` + partial unique index covers AC-9
  outright. One declared deviation, flagged rather than hidden: AC-16's literal "email on every
  click" is narrowed by a 24-hour per-pairing re-notification window (D3b), because an
  unthrottled child-triggered mail path is a mail-bomb primitive.
- S4 (`player-family-availability`, TASK-004) shipped and fully tested 2026-08-20 (AC-1…AC-24,
  47/47 plan tasks): child accounts, child-trainer connection management with unconditional
  ShareLink blocking + parent notification, and per-player Best Times availability with
  trainer-facing summary/filter. Combined code + security review found no fixes needed. Full
  regression: 524 tests, 2012 assertions, 522 green; the 2 remaining failures are confirmed
  sandbox-only artifacts (this container cannot spawn subprocesses, which cascades into one
  order-dependent row-count assertion) — not defects in S1–S4 code, reproduced and documented
  in `tasks/TASK-004/writing-plans-plan.md` Task 47.
- S5 (`coach-features`, TASK-005) spec written 2026-08-20 (AC-1…AC-16, not yet built):
  confirmed against current source that no `ProfileCoach` (or any Coach profile subtype)
  exists — `Profile`'s only concrete subtypes today are `ProfileTrainer` (S2) and
  `ProfilePlayer` (S3) — and that this codebase has no Event/Event-Management entity or
  Epic-02 spec file at all. That absence drives the slice's central scope decision: US-01.10
  is split into a buildable half (coach's own weekly recurring availability CRUD/storage,
  a trainer-facing summary read gated on an active `TrainerCoachAssociation`, and a
  conflict-check-plus-required-reason override-log capability, all exercisable by this
  slice's own tests without any real event to assign to) and a deferred half named
  explicitly rather than silently dropped (AC-9/AC-10: the actual "assign coach to event"
  warning-and-override UI and the coach's accept/request-change response), both blocked on
  Epic-02. US-01.08 (Trainer Invites Coach) is confirmed already shipped in S3 and is not
  re-scoped. S4's `WeeklyAvailability`/`TimeRange` value objects are confirmed reusable to
  represent a coach's schedule shape, but S4's `player_availability_slot` storage is not
  assumed reusable as-is (different owner, different read pattern) — left as an open
  question for the architecture phase, alongside the `ProfileCoach` entity shape,
  credentials/certifications structure (free text vs. structured list), and whether the
  override-log entity should carry a nullable `event_id` now or add one when Epic-02 lands.
- S5 (`coach-features`, TASK-005) design written 2026-08-20, resolving all four open
  questions the spec delegated to the architecture phase. **Coach availability storage
  (the headline question):** the *representation* reuse is taken in full — S4's
  `WeeklyAvailability`/`TimeRange` value objects, `normalized()`'s merge semantics,
  absence-as-not-available, and the whole `AvailabilityWeekFormType`/`DayAvailabilityFormType`/
  `TimeRangeFormType` subtree are used verbatim — but the *storage* is a new,
  column-identical `coach_availability_slot` table, not `player_availability_slot`.
  Reusing that table would mean either storing coaches in a column, FK, property and
  accessor all named `player` (and letting S4's roster filter sweep coaches into a player
  roster), or making both owner FKs nullable behind an exactly-one-of CHECK and adding
  `AND coach_id IS NULL` to `findRosterAvailableAt()` — S4's hottest shipped query, in a
  slice that must not touch it; renaming to a generic `availability_slot` is a frozen-code
  edit to files TASK-004 is modifying in parallel. The duplication paid is one entity and
  one repository of straight-line delete-and-insert; everything with logic in it is
  shared. **`ProfileCoach`:** the fourth `Profile` subtype (`profile_coach`, JOINED), one
  additive line in the discriminator map — the same change S3 made for `ProfilePlayer`,
  and already named in `Profile`'s own docblock — with `UNIQUE (user_id, type)` giving
  "one coach profile per coach" free. Because **no shipped path creates a coach profile**,
  it is created lazily on first save rather than backfilled: "no row" *is* AC-16's
  not-public default. **Credentials/certifications:** free `text`, matching
  `ProfileTrainer::description`, because nothing reads an individual certification (AC-13
  builds no public page) and a structured table would freeze a guess at fields the epic
  never names. **Override-log forward-compatibility:** **no `event_id` column now** — a
  nullable uuid with no referent is data nothing can validate. Omission is safe
  structurally, not hopefully: no unique constraint or index spans a column an event
  would join, the stored candidate `day_of_week`/`starts_at_minute`/`ends_at_minute` plus
  evaluated `coverage` already make every row self-describing so a later `event_id` only
  *narrows* meaning and can never contradict it, `event_id IS NULL` stays truthfully
  interpretable as "recorded before events existed", and the write surface is a DTO that
  gains a defaulted trailing parameter. Three new tables, two `AccountEventType` cases
  (coach profile edits reuse S2's `PROFILE_UPDATED`), one `CoachVoter` keyed on
  `TrainerCoachAssociation` with matching service guards, one new route
  (`/coach/availability`) plus one additive action on the existing `/profile` page and a
  summary on the existing `/trainer/coaches` roster — no email, no Messenger message, no
  rate limiter, no Composer package, and no edit to anything S1/S2/S3/S4 froze beyond the
  one discriminator-map line. AC-9/AC-10 stay unbuilt with no stub route and no invented
  `Event` entity; the override capability deliberately ships with **no** HTTP or CLI
  writer (a console command writing a compliance record from a hand-typed reason is a
  forgery primitive, not a test harness) and is exercised by tests only.
- S5 (`coach-features`, TASK-005) shipped and fully tested 2026-08-20 (AC-1…AC-16,
  38/38 plan tasks): `ProfileCoach` profile subtype, `coach_availability_slot`/
  `coach_assignment_override` tables, `CoachVoter`, coach profile fields on `/profile`,
  `/coach/availability` CRUD, and the `AvailabilitySummaryFormatter` adapter shared with
  S4 (zero S4 test edits). Combined code + security review found and fixed 1 Major
  (`ProfileController::editCoach()` was gated on a bare role check instead of
  `CoachVoter::EDIT_COACH_PROFILE`, skipping the active-account half of the guard) and
  1 Minor (a trainer-roster error re-render silently dropped real coach availability
  summaries behind a defensive default) — both fixed with regression tests. Full suite:
  597 tests, 2210 assertions, 596 green; the one remaining failure is a confirmed
  sandbox subprocess-spawning limitation (`AccountLifecycleFlowTest`'s concurrent-delete
  test), unrelated to S5. A second, separately-fixable S4 test-hygiene bug surfaced
  during this slice's review — `FamilyAndAvailabilityControllersTest` leaked one child
  `User` row per run because a controller-created child was never tracked for cleanup,
  deterministically breaking `ShareLinkRegistrationSourceThrottleTest`'s row-count
  assertion — fixed in that test's `tearDown()`.
- S6 (`admin-impersonation`, TASK-006) spec written 2026-08-20 (AC-1…AC-14, not yet
  built): confirmed against current source that no impersonation code and no
  `switch_user` firewall key exist anywhere in the repo today, and that `security.yaml`'s
  own `role_hierarchy` comment and `AccountEvent`'s own docblock both already name this
  slice as the intended home for Super-Admin-to-user impersonation and its audit report.
  This is the last remaining unbuilt story in Epic-01. The central open decision flagged
  for architecture, not yet locked: the requirements analysis recommends building on
  Symfony's native `switch_user` firewall listener (which gives BR-001/BR-002's
  who-can-switch-to-whom check, `SwitchUserToken`'s real-vs-impersonated distinction for
  FR-007's actor attribution, and `security.switch_user` events to hook for audit
  writes, largely for free) rather than a fully custom session swap — but `switch_user`
  gives no built-in TTL or persisted "is this active right now" state, so the 1-hour
  expiry (FR-005) and the history report's live state (NFR-001) still need either a
  session-stored timestamp or a dedicated `ImpersonationSession` row, and the choice
  touches the frozen-ish `security.yaml` firewall config three other slices depend on
  being stable — the architecture phase must explicitly confirm or override it before
  implementation begins.
- S6 (`admin-impersonation`, TASK-006) design written 2026-08-20, resolving both flagged
  decisions and the four further questions the spec delegated to this phase.
  **`switch_user` vs. custom (the central decision): confirmed — build on native
  `security.switch_user`**, verified against the actually installed Symfony 8.1.4 and
  against `SwitchUserListener`'s source rather than the docs. Four properties decided it:
  `SwitchUserToken` retains the original token (the only clean answer to FR-007's real-actor
  attribution), the listener's authorization call passes the **target user as the subject**
  (so BR-002's "never a Super Admin as target" lives inside the mechanism instead of beside
  it), `checkPostAuth()` runs S1's `AccountStatusChecker` on the target for free, and exit
  restores the original token from the object that owns it — the one operation where a
  hand-rolled version is a privilege bug waiting for its first edge case. A custom session
  swap is rejected as the second authentication path NFR-003 forbids. The firewall change is
  exactly one additive `switch_user` block (`role: ROLE_ALLOWED_TO_SWITCH` decided entirely
  by a new `ImpersonationVoter`; `target_route: app_home`, which is load-bearing — verified
  at `SwitchUserListener` line 114, an unset target redirects back to the `/admin/users`
  URI the freshly impersonated non-admin user is refused, so the feature's first page would
  403). **No `role_hierarchy` edit** — granting `ROLE_ALLOWED_TO_SWITCH` as a role is the
  documentation's quick-start and is exactly wrong here, since a held role is unconditional
  and BR-002 would have nowhere to live; a test asserts no role grants it. **No
  `access_control` line**, so `RouterSweepTest` is untouched. The one cost is named and paid,
  not accepted: the native trigger is a query parameter read *before routing*, so no
  controller of ours can CSRF-check it — closed by `ImpersonationGuardSubscriber` at
  `kernel.request` priority 32, requiring POST + a valid CSRF token + no nesting.
  **Event log vs. dedicated entity: both, with distinct jobs.** A mutable
  `impersonation_session` row is the authority and the report's source (partial unique index
  on `actor_user_id WHERE ended_at IS NULL` for one active session per admin — S3's proved
  pattern, making the nesting edge case a database refusal; a two-way CHECK making BR-003's
  "closed exactly once, with a reason" a database fact; stored `expires_at`; no stored
  duration), because `AccountEvent` is write-once by its own docblock and answering "active
  right now, expiring when" from it means a latest-START-with-no-matching-END scan on every
  impersonated request with no constraint able to stop a second END or a forever-open START.
  Two new `AccountEventType` cases (`IMPERSONATION_STARTED`/`IMPERSONATION_ENDED`) mirror
  the same two moments into the unified timeline, honoring `AccountEvent`'s own promise that
  S6 reports over it — the same division S5 recorded as its D3. The audit row is written
  from the `SwitchUserEvent` listener, not a controller, so "impersonated but unlogged" is
  unrepresentable (AC-11 by construction). **Expiry:** a `kernel.request` subscriber at
  priority 7 — just below the firewall's 8, the mirror image of `SessionIdleSubscriber`'s 32,
  with the "no priority can sit between `ContextListener` and `AccessListener`" constraint
  documented — short-circuiting with a 302 on one invariant: *an impersonation token with no
  open row, or one past `expires_at`, is force-exited*, so everything that must end a session
  (deadline, sweep, deactivation) does exactly one thing, close the row. Backed by an
  `app:impersonation:close-expired` bookkeeping command, because an admin who closes the tab
  never makes the next request that would close their row. Session cookie lifetime is
  rejected (governs the whole session, would sign the admin out an hour after impersonating);
  a Messenger/cron-only approach is rejected (cannot touch a live browser's token).
  **Banner:** the framework's own `is_granted('IS_IMPERSONATOR')` and
  `impersonation_exit_path()`, in one partial included once from `base.html.twig` — no
  session attribute, no Twig global, no response-injecting listener, so the banner cannot
  disagree with the live token; AC-5's non-color cue is the literal word "Impersonation".
  **AC-7** is closed in *one place*: an additive `impersonatorUserId` context merge inside
  S2's `AccountEventRecorder` via a tiny `ImpersonationContext`, so every existing and future
  audit writer inherits real-actor attribution with zero call-site edits — attribution that
  must be remembered per call site is attribution that will be forgotten. The remaining four
  delegated questions: in-flight deactivation of either party **force-ends** the session
  (with the honest consequence stated — S1's `isEqualTo()` signs the admin out entirely
  rather than returning them to their own session; fail closed, documented, not papered
  over); the report reuses S2's keyset `UserSearchCriteria`/`Page` shape with
  actor/subject/date-range filters; rate limiting is a reasoned **no** (every other limiter
  here defends an *unauthenticated* surface, and the partial unique index is a harder ceiling
  than any limiter); nested impersonation is **refused** at three layers rather than stacked
  or silently replaced. Refused attempts write **no** audit row by design (AC-11, and
  `?_switch_user=` is appendable by any authenticated user, so a row per attempt is an
  unbounded write primitive) — flagged in Risks with the right alternative named. One new
  table, two enum cases, one voter, three subscribers, one service, one repository, two
  routes, one console command; no new Composer package, no email, no Messenger message.

- S6 (`admin-impersonation`, TASK-006) shipped and fully tested 2026-08-20 (AC-1…AC-14,
  35/35 plan tasks): native Symfony `security.switch_user` plus an `ImpersonationSession`
  entity as the mutable authority for "active right now / expiring when," an
  `ImpersonationVoter` deciding who can switch to whom (target user as subject, so
  BR-002 lives in the mechanism), and three subscribers (the CSRF/POST/no-nesting guard,
  the priority-7 expiry check mirroring `SessionIdleSubscriber`, and the
  `SwitchUserEvent` audit writer) plus an `app:impersonation:close-expired` sweep
  command for sessions no live request ever closes. Combined code + security review
  found and fixed 3 real defects: a **High** CSRF/POST-guard bypass via the
  `_switch_user` request header — `SwitchUserListener`'s own fallback trigger path that
  the guard subscriber's query-parameter-only extraction missed; a **Medium**
  nesting-prevention gap where the "guard" check was dead code (it ran before the
  firewall's `TokenStorage` was populated) and the voter's own check was never reached
  either, because `SwitchUserListener` exits any existing switch before evaluating a new
  one; and a **Medium** cross-cutting regression this slice caused in S1's password-reset
  flow — `ImpersonationExpirySubscriber`'s unconditional `getToken()` call de-lazied the
  security firewall on public routes, defeating `ResetPasswordController`'s session
  invalidation. All three fixed with regression tests. Full suite: 788/788 tests green,
  schema clean. (Full detail: `tasks/TASK-006/writing-plans-plan.md`.)
- S7 (`trainer-branding`, TASK-007) spec written 2026-08-20 (AC-1…AC-12, not yet built):
  confirmed against current source that no branding code exists anywhere, and that this
  is the last remaining unbuilt item in Epic-01's in-scope MVP list — the epic's own §10
  Epic-Level Acceptance Criteria confirm ShareLinks (S3) and player Best Times (S4/S5)
  are already accounted for, and coach-assignment-to-events stays deferred to Epic-02 per
  S5's spec. Scoped to US-01.14 only: logo upload (PNG/JPG/SVG, max 2MB, preview,
  auto-resize) and primary brand color (hex picker, real-time preview, reset-to-default)
  on the existing `ProfileTrainer` entity (additive columns, no new `Profile` subtype),
  reusing S2's `FileStorage`/`PhotoController` authorization-checked upload pattern and
  S6's `base.html.twig` single-include precedent for page-wide rendering. Central open
  question flagged for architecture, not yet locked: where branding is rendered/read from
  given multi-trainer players and coaches — the epic assumes one trainer per portal and
  never names how "the active trainer" is resolved on a page not obviously scoped to one
  org, so a shared Twig global/request-scoped resolver and its resolution rule are an
  architecture decision, not assumed here. Three further gaps named rather than guessed
  at: `FileStorage`'s allow-list has no SVG case today and SVG is an XSS vector if
  rendered inline; its `MAX_BYTES` is a single 5MB constant shared by every caller,
  stricter than the epic's 2MB logo cap; and "auto-resize if larger than 200x200px"
  implies an image-processing dependency (GD/Imagick) not present anywhere in this
  codebase today.
- S7 (`trainer-branding`, TASK-007) design written 2026-08-20, resolving every open
  question the spec delegated to the architecture phase — two of them by a deliberate
  refusal rather than a mechanism. **Branding-context resolution (the central
  question):** re-verified against source that this codebase has **no "current trainer
  portal" concept at all** — every route is prefixed by the *viewer's role*
  (`/trainer`, `/coach`, `/player`, `/family`, `/admin`, `/join/{code}`) and never by a
  tenant, `base.html.twig` has no header or navigation chrome whatsoever, there is no
  `src/Twig/` directory, Twig extension or global anywhere in the project, and S4's
  `PlayerContextProvider` deliberately returns a *list* and never selects one. So no
  ambient "active trainer" exists to read, and this slice does not invent one: branding
  renders in three enumerated tiers — page chrome only where the viewer's own identity
  fixes exactly one trainer (a `TRAINER`; a `COACH`, guaranteed by S3's
  `uniq_trainer_coach_active_coach` partial unique index) or where a ShareLink code
  carries one (`/join/{code}`, the epic's own headline scenario), per-row branding
  wherever a *set* of trainers is rendered, and the platform default everywhere the
  answer would be a guess. Rejected: a Twig global or request-scoped resolver consulted
  by `base.html.twig` on every page (it needs a fallback, and every candidate fallback —
  first/most-recent/alphabetical association — renders one trainer's brand to a
  multi-trainer viewer, i.e. **AC-12's failure mode arriving by default rather than by
  bug**); a session-remembered "last trainer navigated through" (the same guess plus a
  real leak, since a stale value outliving an ended association keeps branding a former
  trainer); collapsing `PlayerContextProvider` to a selection (undoes S4 AC-11's
  never-merge invariant in a different file); and a tenant path prefix or subdomain
  (architecturally right for a true multi-tenant portal, and a slice of its own — it
  re-homes every shipped route, route name, redirect, `security.yaml` rule and S1's
  `RouterSweepTest`). The honest scope is stated rather than glossed: **a multi-trainer
  player never sees one trainer's brand as their own site chrome, by design.**
  **SVG: refused**, against US-01.14's own "Validation" wording, and flagged as a client
  question rather than hidden. The mitigation the spec floated ("render only via `<img>`,
  never inline `<svg>`") does not hold in this codebase: NFR-002 requires the logo to be
  served through a controller, that controller returns `FileStorage::read()`'s
  `BinaryFileResponse` with `Content-Disposition: inline` and **no `Content-Security-Policy`
  header anywhere in the project**, and the endpoint is a normal navigable same-origin
  URL that AC-7's authorised read exists precisely so other people fetch — opened
  directly, an SVG is a document whose embedded script runs as this origin with the
  viewer's session cookie. No sanitiser exists here (no `DOMDocument` scrubber, nothing
  in `composer.json`), so accepting SVG would ship the vector and promise the control
  later. Two-part condition recorded for adding it: a maintained sanitiser storing
  *sanitised* output, **plus** `Content-Security-Policy: default-src 'none'` and
  `X-Content-Type-Options: nosniff` on the logo response — either half alone is
  insufficient. **2MB vs. the shared 5MB constant:** two defaulted trailing parameters on
  `FileStorage::store()` (`?int $maxBytes = null, ?array $allowedMimeTypes = null`), so
  S2's `store($file, 'photos')` call is unchanged in text *and* behaviour (asserted by a
  regression test) and the limits become the caller's stated policy — rejecting a global
  cap lowering (a behaviour change to a shipped slice), a parallel `LogoStorage` (copies
  the sniffing/opaque-key/placement logic that *is* the class), and a prefix-keyed limit
  map (hides policy from the call site and couples a directory name to a rule).
  **Auto-resize:** **neither GD nor Imagick is installed** and `composer.json` requires
  only `ext-ctype`/`ext-iconv`, so a server-side resize would be a new required extension
  in every environment, bought for a 200px thumbnail CSS renders for free — instead any
  dimensions inside a sanity bound are accepted (AC-5 forbids rejecting on dimensions
  alone), rendering is CSS-constrained to 200×200, and a dependency-free `getimagesize()`
  guard (standard extension, needs no GD) refuses unparseable images and anything over
  4000px as a decompression-bomb check, giving a second independent decoder's opinion
  behind finfo's. **Platform default colour:** found, not invented —
  `:root { --color-primary: #0b5fae }` already exists in `public/css/app.css` and already
  drives every accent, so an override is two custom-property declarations with no cache
  (a generated stylesheet or build step would need one, and AC-11 forbids exactly that),
  and `--color-primary-contrast` is *derived per render* by a pure WCAG
  relative-luminance function so a pale brand colour cannot silently produce
  white-on-pale text — while `--color-focus` is deliberately left platform-controlled,
  because focus visibility is not a trainer-tunable property. **Storage shape:** two
  additive nullable columns on the existing `ProfileTrainer` beside
  `businessName`/`website`/`description`, with `NULL` in both as the entire
  reset-to-default mechanism (storing `#0b5fae` on reset would fail AC-10 literally and
  make the platform unable to change its own default); a `ProfileBranding` subtype would
  not even build against `UNIQUE (user_id, type)`, and a separate table adds a second
  emptiness state on top of the one AC-10 already needs. **Authorization:** one
  `BrandingVoter` — `EDIT_BRANDING` with an *explicit* Super Admin clause (the flat
  `role_hierarchy` means `#[IsGranted('ROLE_TRAINER')]` refuses an admin by itself, so
  AC-2's admin allowance has to live in the voter) plus a
  `BrandingActionNotPermittedException` service guard, and a deliberately broad
  association-based `VIEW_BRANDING` because "org-public" *is* a statement about
  associations; the anonymous read is a separate route nested at `GET /join/{code}/logo`,
  authorised by possession of S3's existing capability token rather than by a new signed
  URL or by making the id-keyed endpoint public (which would be an enumerable probe over
  trainer ids). Writes live in a new `TrainerBrandingService` rather than in S2's
  `ProfileService`, whose invariant — every method acts on the signed-in user's own
  profile — two shipped slices depend on and AC-2's admin path would break. Audit reuses
  `PROFILE_UPDATED` (S5's D6 reasoning) so no new `AccountEventType` case is needed.
  Three risks carried to implementation: **the logo file is not yet cleaned up by S2's
  GDPR deletion path** (a known-shape repeat of the orphaned-photo bug that slice already
  paid for once — decide and test it explicitly); an unresized 3000×3000 logo transfers
  2MB per uncached page view (mitigate with guidance text and a `Cache-Control` header
  before buying `ext-gd`); and tier A depends on S3's partial unique index continuing to
  guarantee one trainer per coach, so that precondition is named in code and asserted by
  a test rather than assumed.

- S7 (`trainer-branding`, TASK-007) shipped and fully tested 2026-08-20 (AC-1…AC-12,
  37/37 plan tasks): two additive nullable columns on the existing `ProfileTrainer`
  (`logo_key`, `primary_color_hex`) with `NULL` in both as the entire reset-to-default
  mechanism, `FileStorage::store()` widened with optional trailing `maxBytes`/
  `allowedMimeTypes` parameters so S2's existing photo-upload call site is unchanged in
  text and behaviour, SVG explicitly refused (no sanitiser and no CSP exists anywhere in
  this codebase to make an inline-rendered SVG safe), and a `TrainerBrandingResolver`
  deliberately built around three enumerated tiers — chrome branding only where the
  viewer's own identity fixes exactly one trainer, per-row branding wherever a set of
  trainers is rendered, and the platform default everywhere else — with no Twig global
  and no ambient "current trainer" concept, since this codebase has none and inventing
  one would leak one trainer's brand to a multi-trainer viewer. Backed by a
  `BrandingVoter` plus service guards, and a GDPR-deletion logo-cleanup fix closing a
  known-shape repeat of the orphaned-photo bug S2 already paid for once. Combined code +
  security review found and fixed 2 real bugs: a null-`branding`-variable crash in
  `_branding.html.twig` (500 on `RoleLandingTest` for a coach with no active trainer
  association) and an uncaught malformed-UUID crash in `BrandingLogoController::show()`
  (500 instead of 404) — the same sibling bug was also found and fixed in the
  pre-existing S2 `PhotoController::show()`. Full suite: 904 tests, 2819 assertions, 903
  green (1 known sandbox-only subprocess-timing failure, unrelated). (Full detail:
  `tasks/TASK-007/writing-plans-plan.md`.)

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
