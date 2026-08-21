# TASK-006 — Epic-01 slice S6: Super Admin Impersonation and Audit

Design: `specs/sdd-admin-impersonation-architecture.md`. Spec:
`specs/sdd-admin-impersonation-spec.md`. Each task cites the acceptance
criteria (AC-N) it serves, or — for a schema/infrastructure/gate task with no
AC of its own — the Decision or Risk it protects. Mark `[x]` only once the
change is made, migrated, and (where a test task follows) proven.

This is the last unbuilt story in Epic-01 (US-01.07). This plan touches
**no** S5 (coach) file — no `ProfileCoach`, `CoachAvailabilitySlot`,
`CoachAssignmentOverride`, `CoachAvailability*`, `CoachVoter`,
`Controller/Coach/*`, or `Trainer/CoachController` appears in any task below.

**Sequencing risk called out up front.** The `security.yaml` `switch_user`
block is a shared-firewall change (architecture Risks, first bullet). Task 5
below is a standalone verification gate — run before any impersonation code
is written — that proves the bare config addition is inert against S1's own
regression suite. Do not start Task 6 onward until Task 5 is green.

## Schema

- [x] 1. Create `App\Enum\ImpersonationEndReason`: `EXPLICIT_EXIT`, `TIMEOUT`,
  `ACCOUNT_STATE_CHANGE` — exactly three cases, backed `varchar(24)`. (AC-9,
  D7)
- [x] 2. Create `App\Entity\ImpersonationSession` → `impersonation_session`:
  `id` UUIDv7 PK (constructor-generated, S1 convention); `actorUser` FK
  `app_user` **ON DELETE RESTRICT**, NOT NULL; `subjectUser` FK `app_user`
  **ON DELETE RESTRICT**, NOT NULL; `startedAt` timestamptz NOT NULL;
  `expiresAt` timestamptz NOT NULL (**stored**, not computed — D4c);
  `endedAt` timestamptz NULL (NULL *is* "active right now" — NFR-001);
  `endReason` varchar(24) NULL, backed by Task 1's enum; `actorIp` inet
  NULL, written through S1's `IpTruncator`. Add `getDuration(): ?\DateInterval`
  (null while `endedAt` is null — computed, no stored `duration_seconds`
  column) and `hasExpired(\DateTimeImmutable $now): bool` (per the Risks
  section's "time is compared in one place" mitigation — the entity takes
  "now" as an argument, never reads the clock itself). No `session_id` or
  session-hash column (the open row is keyed by actor; binding to a PHP
  session id would break on session-id rotation). (AC-10, D2, D4c)
- [x] 3. Create `App\Repository\ImpersonationSessionRepository`:
  `findOpenForActor(User $actor): ?ImpersonationSession` (`WHERE
  actor_user_id = :actor AND ended_at IS NULL`); `findOpenFor(User $user):
  list<ImpersonationSession>` (actor **or** subject — for D7's forced-end
  callers); `findExpiredOpen(\DateTimeImmutable $now, int $limit):
  list<ImpersonationSession>` (the sweep command's batch);
  `closeIfOpen(ImpersonationSession $session, \DateTimeImmutable $endedAt,
  ImpersonationEndReason $reason): bool` (the conditional `UPDATE … SET
  ended_at = :now, end_reason = :reason WHERE id = :id AND ended_at IS
  NULL`, returning whether it affected a row — D4b); `search
  (ImpersonationSearchCriteria $criteria): ImpersonationSearchPage` (keyset
  pagination on `(started_at DESC, id DESC)`, mirroring S2's
  `UserRepository::search()` — D8). Repositories never authorize. (AC-13,
  AC-14, D4b, D8)
- [x] 4. Add two cases to the existing `App\Enum\AccountEventType`:
  `IMPERSONATION_STARTED` (written by `ImpersonationService::start()`;
  actor = Super Admin, subject = impersonated user; context
  `{impersonationSessionId, expiresAt, subjectRole}`) and
  `IMPERSONATION_ENDED` (written by `ImpersonationService::end()`; same
  actor/subject; context `{impersonationSessionId, endReason,
  durationSeconds}`). `account_event.type` is `varchar(64)` — no migration
  needed for this addition alone. **No `IMPERSONATION_REFUSED` case** — see
  D6c; a refusal is a 403 plus Symfony's own security log, not an audit row.
  (AC-10, D2b, D6c)
- [x] 5. Generate one migration, `Version…ImpersonationSession`: `CREATE
  TABLE impersonation_session` with both FKs (`RESTRICT`), and the
  hand-written statements DBAL does not diff — `CHECK ((ended_at IS NULL AND
  end_reason IS NULL) OR (ended_at IS NOT NULL AND end_reason IS NOT NULL))`;
  `CHECK (expires_at > started_at)`; `CHECK (ended_at IS NULL OR ended_at >=
  started_at)`; the **pre-parenthesized** partial unique index `CREATE
  UNIQUE INDEX uniq_impersonation_active_actor ON impersonation_session
  (actor_user_id) WHERE (ended_at IS NULL)` (S3/S4's proven technique for
  `pg_get_expr` canonical-form stability); indexes `(actor_user_id,
  started_at)` and `(subject_user_id, started_at)`. Down drops the table.
  No `ALTER TABLE`, no backfill — the table is new and Task 4's enum cases
  are PHP-only. Run against dev + test DB; confirm `doctrine:schema:validate`
  is clean and `doctrine:schema:update --dump-sql` reports nothing to update
  on a **second** run (S3/S4/S5's normalization-trap check). (AC-9, AC-10,
  D2, D4c)

## Firewall configuration — the shared-file change, isolated as its own gate

- [x] 6. **Verification gate, before any impersonation code exists.** Add
  *only* the `switch_user` block under the `main` firewall in
  `config/packages/security.yaml`:
  ```yaml
  switch_user:
      parameter: _switch_user
      role: ROLE_ALLOWED_TO_SWITCH
      target_route: app_home
  ```
  No `access_control` line is added or reordered, and `role_hierarchy` is
  untouched. Immediately after, with no other new code in the tree, run
  `tests/Functional/RouterSweepTest.php`, `CsrfProtectionTest.php`,
  `LogoutAndSessionRegenerationTest.php`, and `SignInTest.php` (S1) and
  require all four green with **zero test edits** — the cheapest proof that
  a shared-firewall addition is provably inert (architecture Risks, first
  bullet). Do not proceed to Task 7 until this gate is green. (D1, D5,
  Risk: "the `switch_user` block is a shared-firewall change")

## Authorization

- [x] 7. Create `App\Security\Voter\ImpersonationVoter` with two attributes:
  `ROLE_ALLOWED_TO_SWITCH` (subject: `User`, the target — granted when the
  token user is an ACTIVE `SUPER_ADMIN`, **and** the current token is not
  already a `SwitchUserToken`, **and** the subject is **not**
  `SUPER_ADMIN`, **and** the subject is ACTIVE, **and** the subject is not
  the token user) and `VIEW_IMPERSONATION_HISTORY` (no subject — granted
  when the token user is an ACTIVE `SUPER_ADMIN`). Reads only `User` and
  the current token's class — no `Profile`, preserving S1's "authorization
  never reads a Profile" invariant. This is the attribute name
  `SwitchUserListener` itself asks the access decision manager about
  (`switch_user.role`), with the target as subject, so the framework
  enforces the rule with no parallel check to keep in sync. (AC-1, AC-3,
  AC-12, D5)
- [x] 8. Create `App\Exception\ImpersonationNotPermittedException` (plain
  domain exception). Do not wire it into any service yet — Task 11 does.
  (AC-3, D5)

## Services

- [x] 9. Create `App\Dto\ImpersonationSearchCriteria` (readonly DTO):
  `{?Uuid actorId, ?Uuid subjectId, ?\DateTimeImmutable startedFrom,
  ?\DateTimeImmutable startedUntil, ?\DateTimeImmutable afterStartedAt, ?Uuid
  afterId}`, and `App\Dto\ImpersonationSearchPage` (readonly DTO), both
  modelled field-for-field on S2's `UserSearchCriteria`/`UserSearchPage`.
  (AC-13, D8)
- [x] 10. Create `App\Security\ImpersonationContext` (final, tiny,
  read-only): `impersonatorUserId(): ?Uuid` — returns the original token's
  user id when the current token is a `SwitchUserToken`, else `null`.
  Depends only on `TokenStorageInterface`. (AC-7, D6b)
- [x] 11. Create `App\Service\ImpersonationService`, the only writer of
  `impersonation_session`:
  - `start(User $actor, User $subject, ?string $ip): ImpersonationSession` —
    guards (defence in depth, S3 Q4 / S5 D4 convention, re-deriving
    `ImpersonationVoter`'s clauses): `$actor` is an ACTIVE `SUPER_ADMIN`;
    `$subject` is **not** `SUPER_ADMIN`; `$subject` is ACTIVE; `$subject
    !== $actor`; no open row already exists for `$actor`. Each failure
    throws `ImpersonationNotPermittedException`. One transaction, one
    insert, `expiresAt = startedAt + %app.impersonation_ttl_seconds%`.
    Catches `UniqueConstraintViolationException` from the partial unique
    index and re-throws it as the same typed exception — the index, not a
    read-then-write check, is what actually serializes concurrent attempts.
    Post-commit: records `IMPERSONATION_STARTED` via `AccountEventRecorder`.
  - `end(User $actor, ImpersonationEndReason $reason): ?ImpersonationSession`
    — closes the open row for `$actor` via the repository's `closeIfOpen()`.
    A zero-affected-rows result is not an error (someone else closed it
    first). `IMPERSONATION_ENDED` is written **only** when the update
    actually closed the row (D4b).
  - `expire(ImpersonationSession $session): void` — `end()` by row rather
    than by actor, for the sweep command and the expiry subscriber.
  - `forceEndFor(User $user, ImpersonationEndReason $reason): void` —
    closes any open row where `$user` is **either** actor or subject. The
    single entry point for D7.
  - `activeFor(User $actor): ?ImpersonationSession` — the NFR-001 lookup,
    one row via the partial index.
  - `search(ImpersonationSearchCriteria $criteria): ImpersonationSearchPage`
    — thin delegation to the repository.
  Add the `%app.impersonation_ttl_seconds%: 3600` container parameter
  (mirroring `%app.session_idle_seconds%`'s precedent — an operator can
  shorten it per environment, tests can shorten it without sleeping). (AC-3,
  AC-8, AC-9, AC-10, AC-11, D2, D4, D4b, D4c)
- [x] 12. `App\Service\AccountEventRecorder` (S2, **one additive edit**):
  add a nullable `?ImpersonationContext` constructor dependency; in
  `record()`, merge `['impersonatorUserId' => (string) $id]` into
  `$record->context` when `ImpersonationContext::impersonatorUserId()`
  returns non-null. No existing call site changes. `AuthEventRecorder` is
  deliberately **not** touched — an `AuthEvent`'s actor and subject are the
  same person by that entity's own docblock, and impersonation creates no
  `AuthEvent`. Before any impersonation code calls this recorder, run every
  existing `AccountEvent` assertion in the suite and require it green with
  **zero test edits** (architecture Risks: "`AccountEventRecorder` is
  edited, and it is the audit writer every slice depends on"). (AC-7, D6b,
  Risk: "AccountEventRecorder is the audit writer every slice depends on")
- [x] 13. `App\Service\AccountLifecycleService` (S2, **one additive line
  each** in `deactivate()` and `delete()`): call
  `ImpersonationService::forceEndFor($target, ImpersonationEndReason::ACCOUNT_STATE_CHANGE)`
  post-commit. Document in each method's docblock the accepted, stated
  behavior: the affected browser session is invalidated *entirely* by S1's
  existing `isEqualTo()` mechanism (not returned to the admin's own
  session) — fail-closed, not smoothed over. (AC-9, D7)

## Event subscribers

- [x] 14. Create `App\EventSubscriber\ImpersonationGuardSubscriber` —
  `KernelEvents::REQUEST`, priority **32** (same priority
  `SessionIdleSubscriber` uses, and for the same "must beat the firewall's
  8" reason — documented in the class docblock). Fires only when the
  `_switch_user` parameter is present and its value is not `_exit`; refuses
  with `AccessDeniedHttpException` unless **all** of: the request method is
  `POST`; the request body carries a CSRF token valid for id
  `impersonate_<target id>`; the current token is not already a
  `SwitchUserToken` (no nesting). Reads only the request and token
  storage — no database — and does not duplicate the who-may-impersonate
  rule (that belongs to the voter). `_exit` is exempt from all three checks.
  (AC-3, AC-11, D5b, edge case 3)
- [x] 15. Create `App\EventSubscriber\ImpersonationSwitchSubscriber` — on
  `SecurityEvents::SWITCH_USER`. One branch, using no state of its own:
  `$event->getToken() instanceof SwitchUserToken` →
  `ImpersonationService::start(actor: $originalToken->getUser(), subject:
  $event->getTargetUser(), ip: ...)`; else →
  `ImpersonationService::end(actor: $event->getTargetUser(), reason:
  ImpersonationEndReason::EXPLICIT_EXIT)`. This is the only place the
  session row is written, and it cannot be bypassed: `SwitchUserListener`
  cannot mint a `SwitchUserToken` without dispatching this event, which is
  what makes AC-11 hold by construction. (AC-6, AC-7, AC-10, AC-11, D2c)
- [x] 16. Create `App\EventSubscriber\ImpersonationExpirySubscriber` —
  `KernelEvents::REQUEST`, priority **7** (immediately *after* the
  firewall's 8 — the mirror image of `SessionIdleSubscriber`'s choice of
  32; document the reasoning in the class docblock exactly as the
  architecture states it: no priority can interpose between the firewall's
  `ContextListener` and `AccessListener`, so this subscriber sits just
  below 8 and short-circuits with a `RedirectResponse` rather than trying
  to re-decide access). Only on a main request whose token is a
  `SwitchUserToken`, calls `ImpersonationService::activeFor($actor)` (one
  indexed lookup, so an ordinary non-impersonated request costs nothing)
  and: open row with `expiresAt` in the future → no-op; open row with
  `expiresAt` passed → `expire()` the row as `TIMEOUT` and force-exit; no
  open row at all → force-exit (this is the branch through which the sweep
  command and D7's forced ends take effect in the browser). "Force-exit" =
  put `SwitchUserToken::getOriginalToken()` back into token storage and
  302 to `app_home` — same restoration object the native exit uses, no
  second implementation. (AC-8, AC-9, D4, D7)

## Console

- [x] 17. Create `App\Command\ImpersonationCloseExpiredCommand`
  (`app:impersonation:close-expired`): loads
  `ImpersonationSessionRepository::findExpiredOpen($now, $limit)` in
  batches, calls `ImpersonationService::expire()` per row, prints a count.
  Bookkeeping only — never writes a session, never picks a target; its
  only effect on a live browser is through the invariant in Task 16 (the
  row being closed makes the next request force-exit). Safe to run
  repeatedly with no side effect on already-closed rows. Note in the
  command's help text (and this task) that no scheduler exists in this
  repo yet — name it in deployment notes, do not invent a cron/Scheduler
  recipe here. (AC-8, AC-9, D4, Risk: "no scheduler for the sweep command")

## Controllers → routes

- [x] 18. Create `App\Controller\Admin\ImpersonationController::confirm()`
  at `GET|POST /admin/users/{id}/impersonate`
  (`admin_impersonation_confirm`), class-level
  `#[IsGranted('ROLE_SUPER_ADMIN')]`. Renders AC-2's confirmation reading
  "View platform as [User Name] ([Role])?" with a POST form whose body
  carries `_switch_user=<target email>` plus a CSRF token for id
  `impersonate_<target id>` (matching Task 14's expected id). Calls
  `denyAccessUnlessGranted('ROLE_ALLOWED_TO_SWITCH', $target)` before
  rendering, so a Super Admin target — or the admin's own row — is refused
  before the page ever renders (AC-3). The controller's own POST branch is
  reached **only** when `_switch_user` is absent or malformed (the native
  listener intercepts a well-formed POST at priority 8, before routing) —
  in that case it re-renders with an error; this is asserted by a test, not
  dead code. No start action and no session write happens here — that is
  Task 15's job. (AC-1, AC-2, AC-3)
- [x] 19. Create
  `App\Controller\Admin\ImpersonationHistoryController::index()` at `GET
  /admin/impersonation-history` (`admin_impersonation_history`),
  class-level `#[IsGranted('ROLE_SUPER_ADMIN')]`, plus
  `denyAccessUnlessGranted('VIEW_IMPERSONATION_HISTORY')` in the action.
  Binds an `AvailabilityFilterFormType`-style filter form (actor, subject,
  date range) into `ImpersonationSearchCriteria`, calls
  `ImpersonationService::search()`, renders
  `templates/admin/impersonation/history.html.twig` (Task 22). Read-only:
  no POST, no form action beyond the GET filter, no write of any kind.
  (AC-12, AC-13, AC-14, D6)

No new route needs a `security.yaml` `access_control` entry — the existing
`^/` catch-all already covers both new paths, so `RouterSweepTest` (which
parses `access_control` only) needs no edit.

## Templates

- [x] 20. Create `templates/_impersonation_banner.html.twig`: guarded by
  `is_granted('IS_IMPERSONATOR')`; shows the literal word **"Impersonation"**
  plus an `aria-hidden` glyph (AC-5's "more than color alone"), "Viewing as
  {{ app.user.displayName }} ({{ app.user.role.value }})", and a plain
  `<a>` to `impersonation_exit_path(path('app_home'))` labeled "Exit
  Impersonation" — no JavaScript required. Wrap the outer element with
  `role="status"` so it announces on page load. Add the single include
  `{{ include('_impersonation_banner.html.twig') }}` to
  `templates/base.html.twig` immediately after the skip link and before
  `<main>` — this is the one edit that makes AC-5's "every authenticated
  route" true including every route this slice never touches. (AC-5, AC-6,
  D3)
- [x] 21. Extend `templates/admin/user/index.html.twig` (S2, **one row
  action**): add an "Impersonate" link to `admin_impersonation_confirm`,
  wrapped in `{% if is_granted('ROLE_ALLOWED_TO_SWITCH', user) %}` — using
  the voter, not a hand-written role/status condition, so AC-1's
  visibility and AC-3's refusal cannot drift apart. Create
  `templates/admin/impersonation/confirm.html.twig`: a plain
  server-rendered page with the "View platform as [User Name] ([Role])?"
  prompt and the POST form (`_switch_user` hidden field + CSRF `_token`
  field for id `impersonate_<target id>`). (AC-1, AC-2)
- [x] 22. Create `templates/admin/impersonation/history.html.twig`: a table
  with `scope="col"` headers — actor, subject, started, ended, duration,
  end reason — using S2's filter-form and keyset "next page" shape. An
  explicit **"In progress"** cell where `endedAt` is null (AC-14's textual
  distinction, not a blank cell). An empty result renders an explicit "No
  impersonation sessions match these filters." row, never an error (the
  spec's last edge case). No write action of any kind on the page. (AC-13,
  AC-14, edge case 8)

## Tests

- [x] 23. Functional — **start**:
  `tests/Functional/ImpersonationStartTest.php`. A Super Admin opens the
  confirmation page for a Trainer and sees the name and role in the
  prompt, and no `impersonation_session` row exists yet (AC-2); submitting
  it switches the session, lands on `app_home`, and every subsequent page
  renders as the Trainer — asserted by reading a Trainer-only page **and**
  by getting a 403 from `/admin/users` while impersonating (AC-4, NFR-002);
  exactly one `impersonation_session` row and exactly one
  `IMPERSONATION_STARTED` event exist after the switch (AC-10, AC-11).
  (AC-2, AC-4, AC-10, AC-11)
- [x] 24. Functional — **refusals**:
  `tests/Functional/ImpersonationRefusalTest.php`. The Users directory
  shows no "Impersonate" action on a Super Admin row, on the admin's own
  row, or on a deactivated row (AC-1); a forged `POST …/impersonate` with
  `_switch_user` naming a Super Admin is **403** and creates no row and no
  event (AC-3, AC-11); the same for the admin's own address (edge case 1);
  a **`GET /?_switch_user=<trainer email>`** by a signed-in Super Admin is
  **403** — this is the CSRF regression test and must stay red if the guard
  subscriber is ever removed; a POST with a missing or wrong CSRF token is
  403; a Trainer, a Coach, a Player, and an unauthenticated visitor each
  get a refusal from the confirmation route, from a forged switch POST,
  and from `/admin/impersonation-history` (AC-12). (AC-1, AC-3, AC-11,
  AC-12, edge case 1)
- [x] 25. Functional — **banner and exit**:
  `tests/Functional/ImpersonationBannerAndExitTest.php`. The banner is
  present on a Trainer page, a Player page, and `/profile` while
  impersonating, and carries the literal word "Impersonation" plus the
  target's display name (AC-5); it is absent on every page when not
  impersonating; following "Exit Impersonation" restores the Super Admin's
  own token, lets `/admin/users` load again, and closes the row with
  `end_reason = EXPLICIT_EXIT` plus exactly one `IMPERSONATION_ENDED` event
  (AC-6, AC-9); a second exit attempt closes nothing and writes no second
  event (BR-003 / AC-9). (AC-5, AC-6, AC-9)
- [x] 26. Functional — **expiry**:
  `tests/Functional/ImpersonationExpiryTest.php`. With the TTL parameter
  overridden low (or `expires_at` written into the past between requests —
  `SessionIdleExpiryTest`'s exact technique, **not** a `sleep`), the next
  request 302s away from the impersonated view, the token is the Super
  Admin's again, and the row is closed as `TIMEOUT` with a non-null
  duration (AC-8, AC-14); the impersonated request that triggered the
  expiry **never reaches a controller** (asserted by a page-content or
  profiler check); an *unexpired* impersonated request is untouched. (AC-8,
  AC-14, edge case 6)
- [x] 27. Functional — **nesting and forced ends**:
  `tests/Functional/ImpersonationNestingAndForcedEndTest.php`. A second
  impersonation POST while one is active is 403 and leaves the first
  session open and unchanged (edge case 3); deactivating the subject
  mid-session closes the row as `ACCOUNT_STATE_CHANGE` and the browser
  cannot continue as that user (D7); deactivating the actor does the same;
  exiting and immediately re-impersonating the same target creates a
  **second, independent** row and a second `IMPERSONATION_STARTED` event
  (edge case 7). (AC-9, edge cases 3, 4, 5, 7)
- [x] 28. Functional — **report**:
  `tests/Functional/ImpersonationHistoryReportTest.php`. Lists actor,
  subject, start, end, duration, and reason for closed sessions of both
  kinds (AC-13, AC-14); shows an in-progress session as "In progress" with
  no end time; filters by actor, by subject, and by date range, and a
  range with no matches renders the empty-state row, not an error (edge
  case 8); keyset pagination returns disjoint pages in a stable order; the
  page offers no write action of any kind. (AC-13, AC-14, edge case 8)
- [x] 29. Functional — **AC-7 attribution**: extend
  `tests/Functional/ImpersonationStartTest.php` or a sibling file. An
  action performed **while impersonating** that writes an `AccountEvent` (a
  profile edit as the impersonated user is the shipped example) produces a
  row whose `actor_user_id` is the impersonated user *and* whose `context`
  carries `impersonatorUserId` equal to the Super Admin's id; the same
  action performed normally (not impersonating) carries no such key. This
  is the test that proves Task 12's one-place fix rather than trusting it.
  (AC-7)
- [x] 30. Unit test,
  `tests/Unit/Security/Voter/ImpersonationVoterTest.php`: parameterized
  over every role × active/deactivated × self/other/super-admin-target ×
  plain-vs-`SwitchUserToken` combination, matching `ShareLinkVoterTest`'s
  data-provider shape — **including the explicit assertion that no role in
  `role_hierarchy` grants `ROLE_ALLOWED_TO_SWITCH`** (the mitigation named
  in the architecture's Risks section; it must not be deleted as
  redundant). (AC-1, AC-3, AC-12, D5, Risk: "`ROLE_ALLOWED_TO_SWITCH` in
  `role_hierarchy` would silently kill BR-002")
- [x] 31. Unit tests: `tests/Unit/Entity/ImpersonationSessionTest.php` —
  `getDuration()` for a closed row and `null` for a still-open row;
  `hasExpired()` against an injected "now" on both sides of the boundary.
  `tests/Unit/Enum/ImpersonationEndReasonTest.php` — the three-case
  coverage. `tests/Unit/Security/ImpersonationContextTest.php` —
  `impersonatorUserId()` returns `null` for a plain token and the original
  user's id for a `SwitchUserToken`. (AC-9, AC-10, AC-14, D4c)
- [x] 32. Repository/schema integration test, against the real database,
  `tests/Repository/ImpersonationSessionConstraintsTest.php`: the two-way
  `CHECK` refuses a direct insert of `ended_at` without `end_reason` and of
  `end_reason` without `ended_at`; `CHECK (expires_at > started_at)` and
  `CHECK (ended_at IS NULL OR ended_at >= started_at)` each refuse a direct
  bad insert; the partial unique index `uniq_impersonation_active_actor`
  refuses a second open row for one actor **and** permits many closed ones
  for that same actor; `closeIfOpen()` returns `false` on an already-closed
  row and writes nothing; `findExpiredOpen()` ignores closed and unexpired
  rows; `doctrine:schema:update --dump-sql` reports nothing to update on a
  **second** run. (AC-9, AC-10, D2, D4b, D4c)
- [x] 33. Console-command test,
  `tests/Command/ImpersonationCloseExpiredCommandTest.php`: with one
  abandoned expired-but-open row and one still-active open row seeded, the
  command closes exactly the expired one as `TIMEOUT`, writes exactly one
  `IMPERSONATION_ENDED` event, leaves the active row untouched, prints a
  count, and is safe to run a second time immediately after with no further
  effect and no error. (AC-8, AC-9, D4)

## Review and verification

- [x] 34. `code-reviewer` + `security-reviewer` pass over the full slice,
  with explicit attention to: the `security.yaml` `switch_user` block
  (confirm no `access_control` or `role_hierarchy` line was touched), the
  `ImpersonationGuardSubscriber`'s POST+CSRF+no-nesting logic against
  ground-truth fact 3 (the GET-trigger CSRF hole), `ImpersonationVoter`'s
  truth table against the flat `role_hierarchy`, the two `RESTRICT` FKs
  against S2's anonymize-in-place deletion path (exercise
  `AccountLifecycleService::delete()` against a user who was both an actor
  and a subject and confirm the anonymize path completes — architecture
  Risks), and a specific diff review of `AccountEventRecorder` confirming
  every existing call site and signature is unchanged.
- [x] 35. Full regression: `bin/phpunit` — S1's AC-1…AC-25, S2's AC-1…AC-24,
  S3's AC-1…AC-21, S4's AC-1…AC-24, and S5's AC-1…AC-16 must still hold,
  with particular attention to `RouterSweepTest`, `CsrfProtectionTest`,
  `SessionIdleExpiryTest` (the new expiry subscriber must not disturb the
  idle-session mechanism it sits beside), `AdminUsersDirectoryTest`,
  `LogoutAndSessionRegenerationTest`, and every existing `AccountEvent`
  assertion, which Task 12's recorder edit must leave passing with **zero
  test edits**. Confirm `doctrine:schema:validate` is clean and
  `schema:update --dump-sql` reports "Nothing to update" twice in a row.

## Coverage check

**Every AC cited by at least one task** (mechanically re-derived from the
`(AC-N, ...)` citations actually printed in each task above):
AC-1: 7, 18, 21, 24, 30. AC-2: 18, 21, 23.
AC-3: 7, 8, 11, 14, 18, 24, 30. AC-4: 23.
AC-5: 20, 25. AC-6: 15, 20, 25.
AC-7: 10, 12, 29. AC-8: 11, 16, 17, 26, 33.
AC-9: 1, 11, 13, 16, 17, 25, 27, 30, 31, 32, 33.
AC-10: 2, 4, 11, 15, 23, 31, 32. AC-11: 11, 14, 15, 18, 23, 24.
AC-12: 7, 19, 24, 30. AC-13: 3, 9, 19, 22, 28.
AC-14: 3, 19, 22, 26, 28, 31.

Every one of AC-1…AC-14 is cited by at least one task. No criterion is
unclaimed.

**Every task cites at least one real AC, or a named Decision/Risk:** true
for all 35 tasks above. Seven cite a Decision or Risk instead of (or in
addition to) an AC because they are schema/infrastructure/gate/review tasks
with no criterion strictly their own: Task 5 (migration mechanics — D2,
D4c), Task 6 (the flagged shared-firewall verification gate — D1, D5, and
the named Risk), Task 12 (the audit-writer-every-slice-depends-on Risk),
Task 17 (the unscheduled-sweep-command Risk), Task 30 (the
`role_hierarchy`-would-kill-BR-002 Risk, matching S5's Task 32 precedent for
the same shape of test), Task 34 and Task 35 (the dual review/regression
gate, the same shape as TASK-004's Tasks 46–47 and TASK-005's Tasks 37–38).

**The two decisions the spec flagged as central are each implemented by
named tasks, not left implicit:** D1 (native `switch_user` over a custom
session swap) — Tasks 6, 18 (no start/exit routes of our own). D2/D2b
(dedicated mutable `impersonation_session` row **and** two `AccountEventType`
cases, each doing what it is good at) — Tasks 2–4, 9, 11. D5b (closing the
native GET trigger's CSRF hole) — Task 14, proven by Task 24's regression
test that must stay red if the subscriber is ever removed. D7 (in-flight
deactivation force-ends the session, fails closed) — Task 13, proven by
Task 27.

**The shared-firewall sequencing risk is addressed by a named task, not
left implicit:** Task 6 is that task, and it is a hard gate — Task 7 onward
must not start until Task 6's four-test regression check is green with zero
edits.

**No gap found in either direction** during this planning pass: every AC-1
…AC-14 is claimed by at least one task, every task above cites at least one
AC or names the Decision/Risk it protects, and no task in this plan reads
or edits an S5 (coach) file.

## Implementation note (this batch: Tasks 1–13)

Tasks 1–13 are implemented, migrated, and verified (see commit history /
working tree). Tasks 14–17 (`ImpersonationGuardSubscriber`,
`ImpersonationSwitchSubscriber`, `ImpersonationExpirySubscriber`,
`ImpersonationCloseExpiredCommand`) were **not** implemented in this batch,
per this batch's explicit instruction to stop before any
subscriber/listener/controller/banner/test code — even though they are
numbered ≤17. Left unmarked `[ ]` for the next batch.

Two deviations from the literal namespaces this plan's prose names, to
match this repo's actual existing conventions (verified against
`src/Security/*Voter.php` and `src/Service/Exception/*.php`, not against
the plan text):

- Task 7's `ImpersonationVoter` is `App\Security\ImpersonationVoter`, not
  `App\Security\Voter\ImpersonationVoter` — every existing voter
  (`ShareLinkVoter`, `PlayerActionVoter`, `AvailabilityVoter`, `FamilyVoter`,
  `CoachVoter`) lives directly under `App\Security`, with no `Voter`
  sub-namespace anywhere in the codebase.
- Task 8's `ImpersonationNotPermittedException` and Task 9's
  `ImpersonationSearchCriteria`/`ImpersonationSearchPage` are
  `App\Service\Exception\ImpersonationNotPermittedException` and
  `App\Service\ImpersonationSearchCriteria`/`ImpersonationSearchPage`, not
  under a new `App\Exception`/`App\Dto` namespace — every existing
  service-layer domain exception lives in `App\Service\Exception`, and
  every existing search-criteria/page pair (`UserSearchCriteria`,
  `UserSearchPage`) lives directly in `App\Service`, with no `App\Dto`
  namespace anywhere in the codebase.

One additional fix, required to keep the existing regression suite green
with `AccountLifecycleService`'s new constructor parameter (Task 13):
`tests/Functional/fixtures/account-lifecycle-delete-subprocess.php`
(a manual-construction fixture, not an assertion) was updated to build the
new `ImpersonationService` collaborator, and — a pre-existing gap unrelated
to this slice, surfaced only because the constructor signature changed —
was also updated to build `ChildAccountResolver`, which the fixture had
never constructed even though `AccountLifecycleService::delete()` already
required it. No test assertion was edited.
