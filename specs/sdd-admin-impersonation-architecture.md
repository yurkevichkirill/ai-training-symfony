# Design: Super Admin Impersonation and Audit (Epic-01, slice S6)

> Answers *how*. The *what* and *why* live in `specs/sdd-admin-impersonation-spec.md`
> (AC-1…AC-14); this file does not restate them. Governed task: TASK-006. Feature slug:
> `admin-impersonation`. This is the last unbuilt story in Epic-01 (US-01.07).
>
> Builds on five shipped slices — `specs/auth-foundation-architecture.md` (S1),
> `specs/sdd-user-management-architecture.md` (S2),
> `specs/sdd-sharelink-invitations-architecture.md` (S3),
> `specs/sdd-player-family-availability-architecture.md` (S4),
> `specs/sdd-coach-features-architecture.md` (S5). Nothing they froze changes shape:
> `User` (including `isEqualTo()`'s security signature), `Profile` and every subtype,
> `AccountEvent`'s columns, `UserRole`, `UserStatus`, `role_hierarchy`, and every existing
> `access_control` line are untouched. This slice adds **one** table, **two**
> `AccountEventType` cases, one enum, one voter, two event subscribers, one service, one
> repository plus its criteria/page pair, one controller with two routes, one console
> command, one Twig partial, and **one `switch_user` block in `security.yaml`**. It makes
> three small additive edits to shipped files (`base.html.twig`, `AccountEventRecorder`,
> `AccountLifecycleService`) and one to a shipped template
> (`templates/admin/user/index.html.twig`). **No new Composer package, no email, no
> Messenger message, no rate limiter.**
>
> **Ground truth re-verified against source and against `vendor/`, not against the docs.**
> Nine facts shape the design; five of them the spec could not have known:
> 1. **The installed framework is Symfony 8.1.4** (`bin/console --version`;
>    `symfony/security-bundle: 8.1.*`). `Symfony\Component\Security\Http\Firewall\SwitchUserListener`
>    is present, and `MainConfiguration` exposes the `switch_user` firewall node with
>    exactly four options: `provider`, `parameter` (default `_switch_user`), `role`
>    (default `ROLE_ALLOWED_TO_SWITCH`) and **`target_route`**. The native feature is fully
>    available on the installed version — D1's premise is verified, not assumed.
> 2. **`SwitchUserListener` calls the access decision manager with the *target user as the
>    subject*:** `$this->accessDecisionManager->decide($token, [$this->role], $user)`
>    (line 159). That is a voter call with a `User` subject, so BR-002's "never a Super
>    Admin as target" is expressible *inside the mechanism itself* rather than bolted in
>    front of it. This single line is what makes D1 and D5 fit together.
> 3. **`SwitchUserListener::supports()` accepts the parameter from the query string on any
>    method, from the request body on non-GET/HEAD, and from a header** (lines 68–72), and
>    it runs inside the firewall at `kernel.request` priority 8 — *before routing and
>    before any controller*. So a plain `GET /?_switch_user=victim@example.com` link is a
>    complete, CSRF-unprotected impersonation trigger, and **no controller of ours can
>    validate a token in front of it**. This is the reason for D5b's guard subscriber, and
>    it is a fact about the shipped listener, not a hypothetical.
> 4. **`SwitchUserListener` redirects to `$request->getUri()` after switching** unless
>    `target_route` is configured (line 114). Left unset, confirming an impersonation from
>    `/admin/users/…` would bounce the now-non-admin session straight back to an
>    admin-only URL and 403 on its own first page. `target_route: app_home` is therefore
>    load-bearing, not cosmetic (D3c).
> 5. **`SwitchUserListener` calls `$this->userChecker->checkPostAuth($user, $token)`**
>    (line 169) — which is S1's `App\Security\AccountStatusChecker`. A deactivated or
>    deleted target is refused by the framework before any token is created, for free.
> 6. **`SwitchUserEvent` is dispatched on both switch and exit** (lines 175–180 and
>    196–202), and the two are distinguishable with no state of our own: on a switch
>    `getToken()` is a `SwitchUserToken`; on an exit it is the restored original token and
>    `getTargetUser()` is the admin. That is the whole start/end audit hook (D2c).
> 7. **Twig already has the impersonation surface.** `SecurityExtension` registers
>    `impersonation_exit_path`/`impersonation_exit_url`/`impersonation_path`/`impersonation_url`,
>    and `AuthenticatedVoter` defines `IS_IMPERSONATOR`. The banner needs **no session
>    attribute of our own and no Twig global** (D3).
> 8. **`SessionIdleSubscriber` is the exact precedent for the expiry mechanism** — an
>    `EventSubscriberInterface` on `KernelEvents::REQUEST` with an explicit priority chosen
>    *relative to the firewall's priority 8*, reading a TTL from a
>    `%app.session_idle_seconds%` parameter, with the reasoning written into the class
>    docblock. S6's expiry is that pattern applied once more with the priority on the other
>    side of 8, and for the same reason (D4).
> 9. **`AccountEventRecorder` writes whatever actor it is handed**, on an independent
>    connection, from an `AccountEventRecord` whose `context` is
>    `array<string, scalar|null>`. Every controller hands it `$this->getUser()` — which
>    during impersonation *is the impersonated user*. AC-7 is therefore a real gap in the
>    shipped audit path, and it is closed in one place (D6b), not at ~20 call sites.
>
> Also verified: `role_hierarchy` is flat (`ROLE_SUPER_ADMIN: [ROLE_USER]`), so nobody
> holds `ROLE_ALLOWED_TO_SWITCH` by inheritance and no hierarchy edit is needed —
> the spec's "no `role_hierarchy` change" boundary is satisfied by construction.
> `account_event.type` is `varchar(64)`, so the two new cases need no DDL.
> `tests/Functional/RouterSweepTest.php` parses `security.yaml`'s `access_control` list
> only; this slice adds no `access_control` line, so that test is untouched.
>
> **Files owned by TASK-005 (S5), possibly still in flight, are not read or edited by this
> design:** no `ProfileCoach`, `CoachAvailabilitySlot`, `CoachAssignmentOverride`,
> `CoachAvailability*`, `CoachVoter`, `Controller/Coach/*` or `Trainer/CoachController`
> appears anywhere below.

## Approach

Five shaping choices carry the slice.

1. **Impersonation *is* Symfony's `switch_user`, configured — not re-implemented.** One
   `switch_user` block on the `main` firewall gives the session swap (AC-4), the
   real-vs-impersonated token (`SwitchUserToken`, AC-7), the who-may-switch-to-whom
   decision as a *voter call with the target as subject* (AC-3), the user-checker pass on
   the target, the start/end events (AC-10), and the banner's exit link and
   `IS_IMPERSONATOR` test (AC-5, AC-6). A custom session swap would have to rebuild all of
   it and would be a second authentication path, which NFR-003 forbids (**D1**).

2. **The `ImpersonationSession` row is the authority; the token is a cache.** The native
   token can say *that* a session is impersonated but not *when it started* or *whether it
   is still allowed*. So one mutable row per session carries `started_at`, `expires_at`,
   `ended_at` and `end_reason`, and the whole slice runs on one invariant:

   > **An impersonation token with no *open* `ImpersonationSession` row for its actor — or
   > one whose `expires_at` has passed — is force-exited on the next request.**

   Everything that must end an impersonation (the 1-hour deadline, a sweep of abandoned
   sessions, deactivation of either party) does exactly one thing: **close the row**. Token
   restoration is then a single mechanism with a single trigger, not four (**D2**, **D4**).

3. **Two new `AccountEventType` cases sit *beside* that row, not instead of it.** The row
   is the queryable, indexable, constraint-enforced compliance record the report reads;
   `IMPERSONATION_STARTED`/`IMPERSONATION_ENDED` put the same two moments into the unified
   account timeline every other slice writes to. This is the same "both, each doing what it
   is good at" split S5 recorded as D3 for its override log (**D2b**).

4. **One guard subscriber stands in front of the native listener, because the native
   trigger is a GET query parameter.** It runs *before* the firewall and refuses any
   non-`_exit` switch attempt that is not a POST with a valid CSRF token, or that would
   nest inside an active impersonation. This is the only place where the design adds
   machinery the framework does not have, and it exists because ground-truth fact 3 makes
   it impossible for a controller to do the job (**D5b**).

5. **Every rule exists twice — voter and service guard — per S3's Decision Q4 and S5's
   D4.** `ImpersonationVoter` renders the decision at the firewall edge (and, with the
   same call, in the Users-directory template, so UI and enforcement cannot drift);
   `ImpersonationService::start()` re-derives it and throws. Neither is decoration: the
   voter is what the framework consults, the guard is what survives a console command or a
   future writer that never passes the listener.

## Components

### Entities and schema

**`App\Entity\ImpersonationSession`** → `impersonation_session`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7 generated in the constructor (S1 convention) |
| `actor_user_id` | `uuid` NOT NULL FK `app_user` **ON DELETE RESTRICT** | the Super Admin (AC-10) |
| `subject_user_id` | `uuid` NOT NULL FK `app_user` **ON DELETE RESTRICT** | the impersonated user (AC-10) |
| `started_at` | `timestamptz` NOT NULL | AC-10 |
| `expires_at` | `timestamptz` NOT NULL | `started_at + %app.impersonation_ttl_seconds%`, **stored, not computed** (D4c) |
| `ended_at` | `timestamptz` NULL | NULL *is* "active right now" (NFR-001) |
| `end_reason` | `varchar(24)` NULL | `App\Enum\ImpersonationEndReason` |
| `actor_ip` | `inet` NULL | mirrors `account_event.ip`, written through S1's `IpTruncator` |

`App\Enum\ImpersonationEndReason`: `EXPLICIT_EXIT` (AC-6), `TIMEOUT` (AC-8),
`ACCOUNT_STATE_CHANGE` (either party deactivated/deleted mid-session — D7). Exactly three;
the enum is what makes AC-9's "by exactly one of two reasons" auditable rather than
inferred, and the third case is named rather than smuggled in as a `TIMEOUT`.

Hand-written constraints, in the same style as S4/S5's CHECKs:

- `CHECK ((ended_at IS NULL AND end_reason IS NULL) OR (ended_at IS NOT NULL AND end_reason IS NOT NULL))`
  — BR-003's "closed exactly once, with a reason" as a database fact. A half-closed row is
  unrepresentable, so the report can never show an end time with no reason or vice versa.
- `CHECK (expires_at > started_at)`
- `CHECK (ended_at IS NULL OR ended_at >= started_at)`
- **`CREATE UNIQUE INDEX uniq_impersonation_active_actor ON impersonation_session (actor_user_id) WHERE ended_at IS NULL`**
  — one active session per admin. This is S3's partial-unique-index pattern (proved out
  there for coach exclusivity) applied to make the spec's nested-impersonation edge case a
  *database* refusal rather than an app-level check, and to make "find the open session for
  this actor" a single-row indexed lookup (NFR-001).

Indexes: `(actor_user_id, started_at)` and `(subject_user_id, started_at)` — the report's
two filter directions plus its keyset order (AC-13).

**No `duration_seconds` column.** Duration is `ended_at - started_at`, computed in the
entity (`getDuration(): ?\DateInterval`) and rendered by the template. A stored copy is a
second source of truth that can disagree with the timestamps it was derived from, and
nothing sorts or filters by it (AC-13 lists it, AC-14 displays it). **No `session_id` or
session-hash column** — the open row is keyed by actor and the partial unique index
guarantees uniqueness, so binding it to a PHP session id buys nothing and would break the
row the moment the session id rotated. **Both FKs are `RESTRICT`**, matching
`account_event.subject_user_id`'s reasoning: a compliance record must not vanish when an
account is removed, and S2's deletion path anonymizes in place rather than deleting rows.

**`App\Enum\AccountEventType`** gains exactly two cases (`type` is `varchar(64)` — no
migration):

- `IMPERSONATION_STARTED` — written post-commit by `ImpersonationService::start()`.
  Actor = the Super Admin, subject = the impersonated user (the canonical "one user acting
  on another" shape). Context `{impersonationSessionId, expiresAt, subjectRole}`.
- `IMPERSONATION_ENDED` — written post-commit by `ImpersonationService::end()`.
  Same actor/subject. Context `{impersonationSessionId, endReason, durationSeconds}`.

**No `IMPERSONATION_REFUSED` case.** See **D6c** — AC-11 wants a refusal to leave no
"started" record, and an event written on every refused attempt would be an unbounded,
authenticated-user-triggerable write path (any signed-in user can append
`?_switch_user=…`). Refusals surface as a 403 plus Symfony's own security log; this is
called out in Risks rather than left implicit.

**Migration.** One migration, `Version…ImpersonationSession`: `CREATE TABLE`, the two FKs,
the three CHECKs and the partial unique index as hand-written SQL (DBAL does not diff
either). Down drops the table. **No `ALTER TABLE`, no backfill, no data migration** — the
table is new and the two enum cases are PHP-only. Per S3's normalization trap,
`doctrine:schema:update --dump-sql` must report nothing on a **second** run.

### `security.yaml` — the only configuration change

```yaml
main:
    # ... everything already there, unchanged ...
    switch_user:
        # Default parameter name (`_switch_user`), stated explicitly because
        # ImpersonationGuardSubscriber and the confirmation form both hard-depend
        # on it and a silent default change would break them quietly.
        parameter: _switch_user
        # The attribute SwitchUserListener asks the access decision manager
        # about, with the *target user* as the subject. Nobody holds this role:
        # `role_hierarchy` is untouched and `User::getRoles()` returns only
        # ROLE_USER plus the single stored role. The decision comes entirely
        # from ImpersonationVoter (BR-001, BR-002).
        role: ROLE_ALLOWED_TO_SWITCH
        # Without this the listener redirects back to the URI the switch was
        # triggered from -- an /admin/users page the impersonated user is not
        # allowed to see, so the very first impersonated request would 403.
        target_route: app_home
```

That block is the whole firewall change. `role_hierarchy` is **not** edited; no
`access_control` line is added or reordered (the `^/` catch-all already covers
`/admin/impersonation-history` and `/admin/users/{id}/impersonate`), so S1's
`RouterSweepTest` — which parses `access_control` — keeps passing with zero edits, and no
other slice's firewall behavior changes.

### Controllers → routes

| Route | Controller | Notes |
|---|---|---|
| `GET\|POST /admin/users/{id}/impersonate` (`admin_impersonation_confirm`) | `Admin\ImpersonationController::confirm` (new, class-level `#[IsGranted('ROLE_SUPER_ADMIN')]`) | Renders AC-2's confirmation: "View platform as [User Name] ([Role])?" plus a POST form whose body carries `_switch_user=<target email>` and a CSRF token. `denyAccessUnlessGranted('ROLE_ALLOWED_TO_SWITCH', $target)` — the same call the listener will make — so a Super Admin target is refused **before** the page renders (AC-3). |
| `GET /admin/impersonation-history` (`admin_impersonation_history`) | `Admin\ImpersonationHistoryController::index` (new, class-level `#[IsGranted('ROLE_SUPER_ADMIN')]`, plus `denyAccessUnlessGranted('VIEW_IMPERSONATION_HISTORY')`) | AC-12, AC-13, AC-14. Read-only: no POST, no form, no action of any kind on the page. |

**There is no start action and no exit route.** Both are deliberate:

- **Start.** The POST from the confirmation page is intercepted by `SwitchUserListener` at
  `kernel.request` priority 8, before routing — so `confirm`'s own POST branch is reached
  *only* when the `_switch_user` parameter is absent or mangled, in which case it
  re-renders the page with an error. That is honest defensive behavior, not dead code, and
  it is asserted by a test. The audit row is written by the `SwitchUserEvent` listener
  (D2c), which is the only place both the actor and the subject are known with certainty.
- **Exit.** AC-6 is the native `?_switch_user=_exit`, generated in the banner by
  `impersonation_exit_path(path('app_home'))`. A custom exit route would have to
  re-implement token restoration from `SwitchUserToken::getOriginalToken()` — the one piece
  of this feature it would be most dangerous to get subtly wrong (**D3b**).

One console command: **`app:impersonation:close-expired`** (`ImpersonationCloseExpiredCommand`)
— closes every row with `ended_at IS NULL AND expires_at <= now()` as `TIMEOUT`, delegating
to `ImpersonationService::expire()` per row and printing a count. It is *bookkeeping only*:
it can never write a session, never picks a target, and its effect on a live browser is
exactly the invariant in Approach #2 (the row is closed, so the next request force-exits).
Without it, an admin who closes the tab forever leaves an open row, which would violate
AC-9/BR-003 and hold that admin's partial-unique slot indefinitely.

### Event subscribers

Three, each thin and each delegating to `ImpersonationService`.

**`ImpersonationGuardSubscriber`** — `KernelEvents::REQUEST`, priority **32** (the same
priority `SessionIdleSubscriber` uses, and for the same "must beat the firewall's 8"
reason). Fires only when the `_switch_user` parameter is present and its value is not
`_exit`, and then refuses — `AccessDeniedHttpException` — unless **all** of:

- the request method is `POST` (so the switch cannot be triggered by a link, an image tag,
  or a cross-site form GET);
- the body carries a CSRF token valid for the id `impersonate_<target id>`;
- the current token is not already a `SwitchUserToken` (no nesting — the native listener
  would otherwise silently exit and re-switch, producing two sessions from one click; the
  partial unique index would then reject the second row *after* the token had already
  changed).

It reads only the request and the token storage — no database — and it does **not**
duplicate the who-may-impersonate-whom rule, which belongs to the voter the listener
already consults. Exit (`_exit`) is deliberately exempt: it only ever *reduces* privilege,
and requiring a CSRF token to stop impersonating would leave a stuck session as the failure
mode.

**`ImpersonationSwitchSubscriber`** — `SecurityEvents::SWITCH_USER`. One branch, using
ground-truth fact 6 and no state of its own:

```
$event->getToken() instanceof SwitchUserToken
    ? start(actor: originalToken->getUser(), subject: $event->getTargetUser())
    : end(actor: $event->getTargetUser(), reason: EXPLICIT_EXIT)
```

`start()` is what writes the `impersonation_session` row and the `IMPERSONATION_STARTED`
event, so **AC-11 holds by construction**: there is no code path that creates a
`SwitchUserToken` without dispatching this event, and the row is written from inside the
switch, not from a controller that could be bypassed.

**`ImpersonationExpirySubscriber`** — `KernelEvents::REQUEST`, priority **7**: immediately
*after* the firewall's 8, which is the mirror image of `SessionIdleSubscriber`'s choice of
32, and is documented in its class docblock as such. The reasoning is explicit because the
priority is the design:

> The firewall runs `ContextListener` (restores the token from the session) and
> `AccessListener` (makes the access decision) back to back inside its single priority-8
> `kernel.request` call, so **no priority can interpose between them.** Above 8 there is no
> token yet to inspect; below 8 the access decision is already made. This subscriber sits
> just below 8 and does not try to fix the decision — it **short-circuits the request with
> a `RedirectResponse`**, so the expired impersonated token never reaches a controller and
> no impersonated action is ever executed on borrowed time. The already-made access
> decision was, at worst, a catch-all `ROLE_USER` grant for a request that now returns a
> 302.

On a main request whose token is a `SwitchUserToken`, it asks
`ImpersonationService::activeFor($actor)` (one indexed single-row lookup — and **only** on
requests that are already impersonated, so an ordinary request costs nothing) and:

- open row, `expires_at` in the future → stamp nothing, do nothing;
- open row, `expires_at` passed → `expire()` the row (`TIMEOUT`) and force-exit;
- **no open row** → force-exit (this is the branch that makes the sweep command and D7's
  forced ends work through one mechanism).

"Force-exit" is: put `SwitchUserToken::getOriginalToken()` back into token storage and
return a 302 to `app_home` — the same restoration the native exit performs, reached by the
same object, so there is no second restoration implementation (D3b's reasoning applies
here too).

### Services

**`ImpersonationService`** (new) — the only writer of `impersonation_session`.

- `start(User $actor, User $subject, ?string $ip): ImpersonationSession` — guards
  (defence in depth, S3 Q4 / S5 D4): `$actor` is an ACTIVE `SUPER_ADMIN`; `$subject` is
  **not** `SUPER_ADMIN`; `$subject` is ACTIVE; `$subject !== $actor`; no open row exists for
  `$actor`. Each failure throws `ImpersonationNotPermittedException`. One transaction, one
  insert, `expires_at = started_at + %app.impersonation_ttl_seconds%`. Post-commit:
  `IMPERSONATION_STARTED`. A concurrent duplicate insert is caught as a
  `UniqueConstraintViolationException` and re-thrown as the same typed exception, so the
  partial unique index — not a read-then-write check — is what actually serializes it.
- `end(User $actor, ImpersonationEndReason $reason): ?ImpersonationSession` — closes the
  open row for `$actor` if there is one. **Idempotent, at the database level**: the close is
  a single `UPDATE … SET ended_at = :now, end_reason = :reason WHERE id = :id AND ended_at IS NULL`,
  and a zero-affected-rows result means somebody else closed it first and is not an error.
  That is what makes BR-003's "never recorded as ended by both reasons" true even when the
  sweep command and a live request race. `IMPERSONATION_ENDED` is written **only** when the
  update actually closed the row, so the timeline can never carry two ends for one session.
- `expire(ImpersonationSession $session): void` — `end()` by row rather than by actor, for
  the sweep command and the expiry subscriber.
- `forceEndFor(User $user, ImpersonationEndReason $reason): void` — closes any open row
  where `$user` is **either** the actor or the subject. The single entry point for D7.
- `activeFor(User $actor): ?ImpersonationSession` — the NFR-001 lookup, one row by partial
  index.
- `search(ImpersonationSearchCriteria $criteria): ImpersonationSearchPage` — thin
  delegation to the repository, mirroring S2's `UserRepository::search()`.

**`ImpersonationContext`** (new, tiny, read-only): `impersonatorUserId(): ?Uuid` — returns
the original token's user id when the current token is a `SwitchUserToken`, else `null`.
Depends on `TokenStorageInterface` and nothing else.

**`AccountEventRecorder`** (S2, **one additive edit**): its constructor takes
`?ImpersonationContext` and `record()` merges
`['impersonatorUserId' => (string) $id]` into `$record->context` when one is present. That
is AC-7 for **every existing and every future `AccountEvent` writer at once**, with zero
call-site changes anywhere in S1–S5 — which is the whole point: attribution that has to be
remembered per call site is attribution that will be forgotten. `AuthEventRecorder` is
deliberately **not** changed: an `AuthEvent`'s actor and subject are the same person by
that entity's own docblock, and impersonation creates no `AuthEvent`.

**`AccountLifecycleService`** (S2, **one additive line each** in `deactivate()` and
`delete()`): call `ImpersonationService::forceEndFor($target, ACCOUNT_STATE_CHANGE)`
post-commit. See **D7** for what the browser experiences.

**`ImpersonationSearchCriteria`** / **`ImpersonationSearchPage`** (new, readonly DTOs,
modelled field-for-field on S2's `UserSearchCriteria`/`UserSearchPage`):
`{?Uuid actorId, ?Uuid subjectId, ?\DateTimeImmutable startedFrom, ?\DateTimeImmutable startedUntil, ?\DateTimeImmutable afterStartedAt, ?Uuid afterId}`.

### Repository

**`ImpersonationSessionRepository`** (new):

- `findOpenForActor(User $actor): ?ImpersonationSession` — `WHERE actor_user_id = :actor AND ended_at IS NULL`, served by the partial unique index.
- `findOpenFor(User $user): list<ImpersonationSession>` — actor **or** subject, for D7.
- `findExpiredOpen(\DateTimeImmutable $now, int $limit): list<ImpersonationSession>` — the sweep command's batch.
- `closeIfOpen(ImpersonationSession $session, \DateTimeImmutable $endedAt, ImpersonationEndReason $reason): bool` — the conditional `UPDATE`, returning whether it closed anything.
- `search(ImpersonationSearchCriteria $criteria): ImpersonationSearchPage` — keyset
  pagination on `(started_at DESC, id DESC)`, the same shape S2 proved for the Users
  directory (**D8**). Both filter columns are indexed; the date range is a half-open
  `started_at >= :from AND started_at < :until`.

Repositories never authorize; the service and the voter do.

### Frontend

**`templates/_impersonation_banner.html.twig`** (new) — the whole banner:

```twig
{% if is_granted('IS_IMPERSONATOR') %}
    <div class="impersonation-banner" role="status">
        <span class="impersonation-banner__label" aria-hidden="true">⚠</span>
        <strong>Impersonation</strong> — Viewing as {{ app.user.displayName }}
        ({{ app.user.role.value }})
        <a href="{{ impersonation_exit_path(path('app_home')) }}">Exit Impersonation</a>
    </div>
{% endif %}
```

`is_granted('IS_IMPERSONATOR')` is `AuthenticatedVoter`'s native attribute, so **no session
attribute, no Twig global and no listener injecting HTML** is involved (**D3**). AC-5's
"more than color alone" is the literal word **Impersonation** plus the glyph, with the glyph
`aria-hidden` so a screen reader hears the word once; `role="status"` announces the banner
when the page loads impersonated. The exit control is a plain link, so it is keyboard
operable with no JavaScript.

**`templates/base.html.twig`** (S1, **one line**): `{{ include('_impersonation_banner.html.twig') }}`
immediately after the skip link and before `<main>`. That single insertion is what makes
AC-5's "every authenticated route" true for pages this slice never touches, including any
future one — the alternative (per-template includes) is a rule someone will forget.

**`templates/admin/user/index.html.twig`** (S2, **one row action**): an "Impersonate" link
to `admin_impersonation_confirm`, wrapped in
`{% if is_granted('ROLE_ALLOWED_TO_SWITCH', user) %}`. Using the *voter* rather than a
hand-written `user.role.value != 'SUPER_ADMIN' and user.status.value == 'ACTIVE'` condition
is deliberate: the template asks the same question, of the same object, that
`SwitchUserListener` will ask, so **AC-1's visibility and AC-3's refusal cannot drift
apart** — the way they would if the rule were written twice in two languages.

**`templates/admin/impersonation/confirm.html.twig`** (new) — AC-2's confirmation, a plain
server-rendered page with a POST form (`_switch_user` hidden field + `_token`). The spec
leaves modal-vs-page to frontend design; this is the no-JS baseline the modal can later
progressively enhance, and it is what the guard subscriber's POST+CSRF requirement is
satisfied by.

**`templates/admin/impersonation/history.html.twig`** (new) — AC-13's table (actor,
subject, started, ended, duration, end reason) with `scope="col"` headers, S2's filter-form
and keyset "next page" shape, and an explicit **"In progress"** cell where `ended_at` is
null so AC-14's distinction is textual rather than an empty cell. An empty result renders
an explicit "No impersonation sessions match these filters." row, never an error (the
spec's last edge case).

### Authorization

One new voter. It reads `User::role`, `User::status`, `TokenStorage`'s token class and the
`impersonation_session` open-row lookup — **no `Profile`**, so S1's frozen "authorization
never reads a Profile" invariant holds.

| Voter | Attribute | Subject | Granted when |
|---|---|---|---|
| `ImpersonationVoter` | `ROLE_ALLOWED_TO_SWITCH` | `User` (the target) | the token user is an ACTIVE `SUPER_ADMIN`; **and** the token is not already a `SwitchUserToken`; **and** the subject is **not** `SUPER_ADMIN`; **and** the subject is ACTIVE; **and** the subject is not the token user (AC-1, AC-3, BR-001, BR-002) |
| `ImpersonationVoter` | `VIEW_IMPERSONATION_HISTORY` | none | the token user is an ACTIVE `SUPER_ADMIN` (AC-12) |

Three properties of this table are load-bearing:

- The attribute name is the framework's, on purpose. `SwitchUserListener` asks the access
  decision manager for exactly the string in `switch_user.role`, with the target as
  subject, so putting the rule in a voter on that attribute means **the framework enforces
  our rule** — there is no parallel check to keep in sync, and there is no configuration in
  which the listener switches without consulting it.
- **Nobody holds `ROLE_ALLOWED_TO_SWITCH` as a role.** `role_hierarchy` is untouched and
  `User::getRoles()` returns `ROLE_USER` plus the single stored role, so under the default
  affirmative strategy the only voter that can ever grant this attribute is ours. A test
  asserts this explicitly rather than trusting it, because the day someone adds
  `ROLE_ALLOWED_TO_SWITCH` to the hierarchy, BR-002 silently dies.
- **A Super Admin is refused as a target including themselves** — the two clauses
  ("subject is not SUPER_ADMIN" and "subject is not the token user") are separately
  sufficient for the spec's first edge case, which is why both are written.

Defence in depth: `ImpersonationService::start()` re-derives every clause and throws
`ImpersonationNotPermittedException`. The voter gives the clean 403 at the edge; the guard
is what survives the console command, a future API surface, or a caller that never passed
the listener. The report has both `#[IsGranted('ROLE_SUPER_ADMIN')]` at the class and
`VIEW_IMPERSONATION_HISTORY` in the action — and it is read-only, so there is nothing to
guard inside a service.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| Session swap, token restoration, exit | Framework | `SwitchUserListener` (configured, not written) |
| POST+CSRF+no-nesting precondition on the swap | Security / subscriber | `ImpersonationGuardSubscriber` (new) |
| Who may impersonate whom; who may read the report | Security | `ImpersonationVoter` (new) |
| Start/end audit hook | Subscriber | `ImpersonationSwitchSubscriber` (new) |
| 1-hour deadline enforcement + force-exit | Subscriber | `ImpersonationExpirySubscriber` (new) |
| Session row lifecycle, guards, TTL arithmetic | Service | `ImpersonationService` (new) |
| Real-actor attribution for every audit writer | Service | `ImpersonationContext` (new) + `AccountEventRecorder` (S2, one additive edit) |
| Forced end on deactivation/deletion | Service | `AccountLifecycleService` (S2, one additive call) |
| Audit write | Service | `AccountEventRecorder` (S2) |
| Confirmation page, history report | Controller | `Admin\ImpersonationController`, `Admin\ImpersonationHistoryController` (new) |
| Abandoned-session bookkeeping | Console | `ImpersonationCloseExpiredCommand` (new) |
| Queries, keyset pagination, conditional close | Repository | `ImpersonationSessionRepository` (new) |
| Banner presence test | Template | `is_granted('IS_IMPERSONATOR')` (framework) |

S1's boundary rules are unchanged: one transaction per service method, controllers never
`flush()`, services never return a `Response`, repositories never authorize, subscribers
hold no business logic (each of the three is a precondition check, a two-line branch, or a
delegation plus a redirect).

### Tests this slice must produce

Functional — **start**: a Super Admin opens the confirmation page for a Trainer and sees
the name and role in the prompt, and **no** session row exists yet (AC-2); submitting it
switches the session, lands on `app_home`, and every subsequent page renders as the
Trainer — asserted by reading a Trainer-only page **and** by getting a 403 from
`/admin/users` while impersonating (AC-4, NFR-002); exactly one `impersonation_session` row
and exactly one `IMPERSONATION_STARTED` event exist (AC-10, AC-11).

Functional — **refusals**: the Users directory shows no "Impersonate" action on a Super
Admin row, on the admin's own row, or on a deactivated row (AC-1); a forged
`POST …/impersonate` with `_switch_user` naming a Super Admin is **403** and creates no row
and no event (AC-3, AC-11); the same for the admin's own address (edge case 1); a
**`GET /?_switch_user=<trainer email>`** by a signed-in Super Admin is **403** (the guard
subscriber — this is the CSRF regression test, and it must stay red-if-removed); a POST with
a missing or wrong CSRF token is 403; a Trainer, Coach, Player and unauthenticated visitor
each get a refusal from the confirmation route, from a forged switch POST, and from
`/admin/impersonation-history` (AC-12).

Functional — **banner and exit**: the banner is present on a Trainer page, a Player page
and `/profile` while impersonating, and carries the literal word "Impersonation" plus the
target's display name (AC-5); it is absent on every page when not impersonating; following
"Exit Impersonation" restores the Super Admin's own token, lets `/admin/users` load again,
and closes the row with `end_reason = EXPLICIT_EXIT` plus exactly one
`IMPERSONATION_ENDED` event (AC-6, AC-9); a second exit attempt closes nothing and writes
no second event (BR-003).

Functional — **expiry**: with the TTL parameter overridden low (or `expires_at` written
into the past between requests, `SessionIdleExpiryTest`'s exact technique — **not** a
`sleep`), the next request 302s away from the impersonated view, the token is the Super
Admin's again, and the row is closed as `TIMEOUT` with a non-null duration (AC-8, AC-14);
the impersonated request that triggered the expiry **never reaches a controller** (asserted
by a page-content or profiler check, because "no impersonated action on borrowed time" is
the actual guarantee); an *unexpired* impersonated request is untouched.

Functional — **nesting and forced ends**: a second impersonation POST while one is active
is 403 and leaves the first session open and unchanged (edge case 3); deactivating the
subject mid-session closes the row as `ACCOUNT_STATE_CHANGE` and leaves the browser
unable to continue as that user (D7); deactivating the actor does the same; exiting and
immediately re-impersonating the same target creates a **second, independent** row (edge
case 7).

Functional — **report**: lists actor, subject, start, end, duration and reason for closed
sessions of both kinds (AC-13, AC-14); shows an in-progress session as "In progress" with
no end time; filters by actor, by subject and by date range, and a range with no matches
renders the empty-state row, not an error (edge case 8); keyset pagination returns disjoint
pages in a stable order; the page offers no write action of any kind.

Functional — **AC-7 attribution**: an action performed **while impersonating** that writes
an `AccountEvent` (a profile edit as the impersonated user is the shipped example) produces
a row whose `actor_user_id` is the impersonated user *and* whose `context` carries
`impersonatorUserId` = the Super Admin's id; the same action performed normally carries no
such key. This is the test that proves the one-place fix in `AccountEventRecorder` rather
than trusting it.

Unit: `ImpersonationVoter`'s truth table parameterized over every role × status ×
self/other/super-admin-target × plain-vs-`SwitchUserToken` combination, **including the
assertion that no role in `role_hierarchy` grants `ROLE_ALLOWED_TO_SWITCH`**;
`ImpersonationSession`'s duration computation, including a still-open row returning `null`;
`ImpersonationEndReason` coverage; `ImpersonationContext` returning `null` for a plain
token.

Integration, against the real database: the two-way CHECK refuses a direct insert of
`ended_at` without `end_reason` and of `end_reason` without `ended_at`; the partial unique
index refuses a second open row for one actor **and** permits many closed ones;
`closeIfOpen()` returns `false` on an already-closed row and writes nothing;
`findExpiredOpen()` ignores closed and unexpired rows; the sweep command closes an
abandoned row and is safe to run twice; `doctrine:schema:update --dump-sql` reports nothing
on a **second** run.

Regression: S1's AC-1…AC-25, S2's AC-1…AC-24, S3's AC-1…AC-21, S4's AC-1…AC-24 and S5's
AC-1…AC-16 must still hold — in particular `RouterSweepTest`, `CsrfProtectionTest`,
`SessionIdleExpiryTest` (the new subscriber must not disturb the idle mechanism),
`AdminUsersDirectoryTest`, `LogoutAndSessionRegenerationTest`, and every existing
`AccountEvent` assertion, which the recorder edit must leave passing **with zero test
edits** (the new context key appears only while impersonating).

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| Native `security.switch_user` on the existing `main` firewall | Symfony 8.1.4 (verified installed) | Over a custom session swap: the framework already ships the token swap, the original-token link, the target-as-subject authorization call, the user-checker pass on the target, the start/end events, the exit path and the Twig helpers. See **D1**. |
| A dedicated mutable `impersonation_session` table | — | Over answering "active right now, expiring when" from `AccountEvent`: that table is write-once by its own docblock and would require a latest-START-with-no-matching-END scan per request, with no constraint able to express "closed exactly once". See **D2**. |
| Partial unique index for "one active session per admin" | PostgreSQL | S3's proved pattern. Over an app-level read-then-write check, which loses to a double-submit; over no constraint, which turns the spec's nesting edge case into an app convention. |
| Two `AccountEventType` cases beside the table | — | Over either alone: the table is queryable and constraint-enforced, the events keep impersonation visible in the one timeline every other slice writes to. S5's D3 precedent. |
| `KernelEvents::REQUEST` subscriber for the deadline | — | Over session cookie lifetime (governs the *whole* session, so it would sign the admin out entirely and cannot be scoped to the impersonation window), over `gc_maxlifetime` (probabilistic), over a cron-only sweep (cannot change a live browser's token). See **D4**. |
| `%app.impersonation_ttl_seconds%: 3600` container parameter | — | Exactly `%app.session_idle_seconds%`'s precedent and rationale: an operator can shorten it per environment without a code deploy, and the tests can shorten it without sleeping. |
| No new Composer package | — | Every mechanism exists: `switch_user` and `SwitchUserToken` for the swap, `AuthenticatedVoter::IS_IMPERSONATOR` and the impersonation Twig functions for the banner, `AccountEventRecorder` for audit, PostgreSQL partial indexes and CHECKs for the invariants, S2's keyset pagination for the report. |

Not added: a rate limiter (**D9**); an email or Messenger message (no AC notifies anyone —
notifying an impersonated user is not in the epic and inventing it would answer an unasked
product question); a `remember_me`-style persistent impersonation cookie (the spec caps the
session at an hour, which is the opposite requirement); an admin-activity dashboard (out of
scope by the spec).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| **D1. (The spec's central flagged decision) `switch_user` vs. a custom session swap** | **Confirmed: build on native `security.switch_user`**, adding one firewall block and putting the entire policy in a voter on the attribute the listener already consults | (a) a fully custom session-swap service storing the real admin id in a session attribute and swapping the token by hand; (b) a second firewall with its own authenticator; (c) native `switch_user` with the policy in a listener *in front of* the listener rather than in a voter | The recommendation is confirmed **on this project's actually installed code, not on the docs**: Symfony 8.1.4 is installed, `SwitchUserListener` exists, and `MainConfiguration` exposes the `switch_user` node with `parameter`/`role`/`target_route`. What it gives is not a convenience — it is the four hardest parts of the feature: (i) `SwitchUserToken` retains the original token, which is the *only* clean answer to AC-7 and would otherwise be a session attribute nothing enforces; (ii) the authorization call passes the **target user as the subject**, so BR-002 lives inside the mechanism instead of beside it; (iii) `checkPostAuth()` runs S1's `AccountStatusChecker` on the target, so a deactivated target is refused with no code of ours; (iv) exit restores the original token from the object that holds it, so there is no hand-rolled "remember who I was" state to corrupt. (a) is a second authentication path, which NFR-003 explicitly forbids, and every one of those four properties would have to be re-derived and re-tested — the token-restoration half in particular is a privilege-escalation bug waiting for its first edge case. (b) multiplies the firewall config three slices depend on. (c) keeps the mechanism but moves the rule *outside* it, so a future config change could switch without consulting the rule. **The cost of the choice is stated honestly and paid in D5b:** the native trigger is a query parameter read before routing, which is a CSRF hole for a privileged state change, and the firewall config is genuinely shared — which is why the change is exactly one additive block, adds no `access_control` line, and does not touch `role_hierarchy`, so `RouterSweepTest` and every other slice's routes are provably unaffected. |
| **D2. (The spec's second flagged decision) `AccountEventType` extension vs. a dedicated entity** | **Both, with distinct jobs.** A mutable `impersonation_session` row is the authoritative session state and the report's source; two new `AccountEventType` cases mirror start and end into the unified timeline | (a) `AccountEvent` cases only, with the report deriving sessions by pairing STARTs with ENDs; (b) the table only, with no new event cases; (c) session-attribute-only state with events for the report | (a) fails NFR-001 on its own terms and fails BR-003 outright. `AccountEvent` is write-once by its own docblock, so "is this actor impersonating right now, and when does it expire" becomes "find the latest STARTED for this actor with no later ENDED" — a scan of a growing log on **every impersonated request**, with no index that answers it in one row, and no constraint anywhere that can stop a second ENDED or a forever-open START. "Closed exactly once, with a reason" is expressible as a CHECK on a mutable row and is not expressible at all over an append log. (b) would make impersonation the one privileged action in the platform that is invisible in the account timeline every other slice writes to, and would break `AccountEvent`'s own stated promise that S6 reports over it. (c) puts the compliance record in a PHP session — unreadable by the report, gone when the session is dropped, and unable to answer AC-14 for a session that ended by abandonment. The two together are the same division S5 recorded in its D3: the dedicated table is the queryable compliance record, the event row is the timeline entry, and neither pretends to be the other. |
| **D2b. What the report reads** | `impersonation_session` (via `search()`), **not** `account_event` | Reading the two event rows and joining them | One row already holds actor, subject, start, end, reason and enough to compute duration, with both filter columns indexed; a report over the event log would re-derive by pairing rows and would show nothing at all for an in-progress session (AC-14). The events remain the timeline, and the timeline is not a reporting join. |
| **D2c. Where the session row is written** | From `ImpersonationSwitchSubscriber` on `SecurityEvents::SWITCH_USER`, not from a controller | A `POST …/impersonate` controller action that writes the row and then redirects to the `?_switch_user=` URL | AC-11 says there is *no* path that starts impersonation without producing its audit record. The controller version has two paths — the intended POST and a bare `?_switch_user=` — and only the first writes a row, so the audit would be exactly as reliable as the guard in front of it. Writing from the event means the row is created *by the switch itself*: the listener cannot mint a `SwitchUserToken` without dispatching the event (verified in `SwitchUserListener`), so "impersonated but unlogged" is unrepresentable. It also avoids re-triggering the switch through a GET redirect, which is the CSRF hole D5b exists to close. |
| **D3. (AC-5) How the banner knows** | `is_granted('IS_IMPERSONATOR')` in a partial included once from `base.html.twig`, with the exit link from `impersonation_exit_path(path('app_home'))` | (a) our own `_impersonation` session attribute set at start and read via a Twig global; (b) a `kernel.response` listener injecting the markup into the HTML; (c) a Twig extension exposing `ImpersonationService::activeFor()` | (a) is a second source of truth for a state the token already carries perfectly, and it would survive a token change it did not cause — a banner that lies in either direction is worse than no banner. `IS_IMPERSONATOR` is `AuthenticatedVoter`'s own attribute, derived from the live token, so it cannot disagree with reality. (b) is string surgery on responses that breaks on any non-HTML route and is invisible to anyone reading the template. (c) adds a per-request query to render chrome that the token already answers for free. The one-line `base.html.twig` include is what makes AC-5's "every authenticated route" true for routes this slice never sees, including future ones. |
| **D3b. (AC-6) The exit control** | The native `?_switch_user=_exit`, generated by `impersonation_exit_path()` | A `POST /impersonation/exit` route of our own | Exiting means putting `SwitchUserToken::getOriginalToken()` back in token storage and re-persisting the session — the one operation in this feature where a subtle mistake is a privilege bug, and the framework already implements it in the class that owns the token. A custom route would also need its own CSRF story for an action whose only effect is *dropping* privilege. The row is still closed on exit, by the same `SWITCH_USER` event the framework dispatches on the exit path (ground-truth fact 6), so custom code buys no audit fidelity either. |
| **D3c. Where the browser lands after switching** | `switch_user.target_route: app_home` | Leaving it unset (the listener's default: redirect to the triggering URI) | Verified in `SwitchUserListener` line 114: unset, it redirects to `$request->getUri()` — which for us is `/admin/users/{id}/impersonate`, a route the freshly impersonated non-admin user is refused. The feature's very first impersonated page would be a 403. `app_home` is S1's role landing entry point, so the admin lands exactly where the impersonated user lands when they sign in — which is also what AC-4 describes. |
| **D4. (AC-8, FR-005) 1-hour expiry mechanism** | A `kernel.request` subscriber at priority 7 that force-exits when the row is missing or expired, **plus** an `app:impersonation:close-expired` command as bookkeeping. The **row is the authority; the token is a cache** | (a) session cookie lifetime / `gc_maxlifetime`; (b) a Messenger delayed message scheduled at start; (c) a cron sweep alone; (d) an `expires_at` timestamp in the PHP session, checked without a row | (a) governs the whole session, not the impersonation window: shortening it would sign the *Super Admin* out an hour after impersonating and cannot distinguish the two states at all; `gc_maxlifetime` is probabilistic besides. (b) and (c) cannot touch a live browser's token — a worker has no access to that session, so the admin would keep browsing as the target until they made a request that checked *something*, which is the subscriber again. But a sweep is still needed for the opposite case: an admin who closes the tab never makes that next request, and their row would stay open forever, violating AC-9 and holding their partial-unique slot — so the command exists purely to close rows, and the subscriber's "no open row ⇒ force exit" branch makes that closure take effect in the browser whenever it returns. (d) reintroduces D3's rejected second source of truth and cannot be read by the report or by the sweep. The chosen priority (7 — just below the firewall's 8) and the reason no priority can sit *between* `ContextListener` and `AccessListener` are documented in the class docblock, mirroring `SessionIdleSubscriber`'s treatment of the same constraint from the other side; the request is short-circuited with a 302 rather than trying to re-decide access, so no impersonated controller ever runs past the deadline. |
| **D4b. Ending exactly once** | A conditional `UPDATE … WHERE id = :id AND ended_at IS NULL`, with the `IMPERSONATION_ENDED` event written **only** when it affected a row | Reading the row, checking `ended_at`, then writing | BR-003 forbids a session recorded as ended by two reasons, and the sweep command, a live request and a deactivation can genuinely race. Read-then-write loses that race; the conditional update makes "closed exactly once" the database's answer, and gating the event on the affected-row count makes the timeline inherit the same guarantee instead of restating it. |
| **D4c. `expires_at` stored vs. computed** | Stored on the row | Computed as `started_at + %app.impersonation_ttl_seconds%` at read time | A stored deadline is the deadline the session was *granted*, which is what a compliance report must show. Computing it means shortening the parameter retroactively expires sessions that were legitimately granted an hour, lengthening it silently extends live ones, and the sweep's `WHERE` clause becomes parameter-dependent arithmetic instead of an indexable column comparison. |
| **D5. (AC-3, AC-12, BR-001, BR-002) Authorization shape** | One `ImpersonationVoter` with `ROLE_ALLOWED_TO_SWITCH` (subject: the target `User`) and `VIEW_IMPERSONATION_HISTORY`, **plus** matching guards in `ImpersonationService::start()` | (a) granting `ROLE_ALLOWED_TO_SWITCH` to `ROLE_SUPER_ADMIN` in `role_hierarchy`; (b) an `access_control` rule; (c) the check in a listener in front of the firewall; (d) the check only in the service | (a) is the documentation's quick-start and it is **exactly wrong here**: a held role is unconditional, so BR-002's "never a Super Admin as target" would have nowhere to live, and it edits the `role_hierarchy` the spec puts out of scope and whose flatness three slices depend on. (b) cannot express a rule about a *target object* at all. (c) duplicates a decision the listener already makes, and drifts the day someone changes the config. (d) is invisible to the framework: the listener would switch first and the service would refuse afterwards, with the token already changed. The voter is the only option where the rule sits on the attribute the framework itself asks about, with the target as subject — and it is reused verbatim in the Users-directory template, so AC-1's visibility and AC-3's refusal are one rule with one implementation. The service guard remains, per S3's Q4 and S5's D4 convention, for callers that never pass the listener. |
| **D5b. Closing the native trigger's CSRF hole** | `ImpersonationGuardSubscriber` at `kernel.request` priority 32: a non-`_exit` switch requires POST **and** a valid CSRF token **and** no active impersonation | (a) accept the GET trigger as-is; (b) a stateless CSRF check in the controller; (c) a random per-admin secret in the parameter value | (a) is not acceptable for this feature specifically: a signed-in Super Admin clicking an attacker's link would silently become another user, and the resulting audit row would show a real impersonation the admin never intended — the feature's audit trail would be *actively misleading*, which is worse than the missing check. (b) cannot work, and this is a fact about the shipped listener rather than a preference: `SwitchUserListener` runs at `kernel.request` priority 8, before routing, and responds with a redirect, so no controller of ours is ever reached on a successful switch (verified in `SwitchUserListener::authenticate()`). Only a subscriber above priority 8 can stand in front of it. (c) is a bearer token in a URL, which is the thing we are trying to stop. The nesting clause lives here too, because the native listener's own behavior on a second switch is to silently exit and re-switch — which would end one audited session and start another from a single click, and would then collide with the partial unique index *after* the token had already changed. |
| **D6. (AC-13) Where the report lives** | A new `Admin\ImpersonationHistoryController` at `/admin/impersonation-history` | An action on the existing `Admin\UserController` | `UserController`'s documented scope is the Users *directory* and account lifecycle, and every one of its actions is keyed on a `{id}` user. A cross-cutting, read-only compliance report over a different entity with its own criteria/pagination is a different resource; S2's own precedent is one controller per tool. The confirmation action, by contrast, *is* keyed on a user id and could have lived on `UserController` — it is split out only so that all impersonation HTTP surface is in one file, which is the file a reviewer will look for. |
| **D6b. (AC-7) Real-actor attribution** | `ImpersonationContext` + one additive merge inside `AccountEventRecorder::record()`, so every `AccountEvent` written during an impersonation carries `impersonatorUserId` in its context | (a) pass the real actor explicitly at every audit call site; (b) a new `impersonator_user_id` column on `account_event`; (c) rely on the two `IMPERSONATION_*` events to bracket the session in time | (a) is ~20 call sites across five shipped slices, each of which must be edited and none of which can be *made* to remember — the first new writer after this slice forgets, and AC-7 quietly stops being true. (b) is an `ALTER TABLE` on a frozen audit table plus an entity edit, to hold a value that is null in the overwhelming majority of rows; the `jsonb` context column exists exactly for this kind of qualifier, and S3/S4/S5 all use it for ids. (c) is what "attributable" must **not** mean: bracketing by timestamp asks a compliance reviewer to join two rows by time and hope no clock skew or concurrent session confuses it, when the fact can simply be recorded on the row. The chosen edit is one constructor parameter and one array merge, changes no signature and no call site, and is asserted by a test that the key appears only while impersonating. |
| **D6c. Auditing refused attempts** | **No event, no row.** A refusal is a 403 plus Symfony's security log | An `IMPERSONATION_REFUSED` `AccountEventType` case | AC-11 requires a refused attempt to produce no session and no started record, and a third case would sit awkwardly against that. Decisively, though, the refusal path is reachable by **any authenticated user** appending `?_switch_user=…` (they are refused by the voter, but the attempt happens), so writing a row per attempt is an unbounded log-growth primitive handed to every account on the platform. `AccountEvent` also requires a non-null subject, so a refusal naming a nonexistent identifier could not be recorded consistently anyway. **Flagged in Risks** — if refusal visibility is later wanted, the right shape is a rate-limited counter or a monitoring alert on the 403, not an unbounded audit row. |
| **D7. (Spec open question) In-flight deactivation or deletion of either party** | **Force-end.** `AccountLifecycleService::deactivate()`/`delete()` call `ImpersonationService::forceEndFor($target, ACCOUNT_STATE_CHANGE)` post-commit; the subscriber's "no open row ⇒ force exit" branch does the token side | (a) leave it to S1's `isEqualTo()` session invalidation alone; (b) a dedicated forced-exit code path per case; (c) leave the row open and let the deadline close it | (a) *does* stop the impersonation — the refreshed target no longer equals the token's user, so Symfony drops the whole session — but it leaves the `impersonation_session` row open forever, which violates BR-003 and makes the report wrong. That is the half the app has to supply, and it is one call in each of two methods. What the browser experiences is stated plainly rather than smoothed over: **the admin is signed out entirely, not returned to their own session**, because S1's session invalidation acts on the session as a whole. That fails closed, is consistent with S2's shipped deactivation behavior, and is documented here and in Risks instead of being papered over with a second restoration path. (b) multiplies mechanisms for one outcome; (c) leaves a closed account impersonable-on-paper for up to an hour of report time. |
| **D8. (Spec open question) Report filters and pagination** | Actor, subject and a half-open date range, with keyset pagination on `(started_at DESC, id DESC)` — S2's `UserSearchCriteria`/`UserSearchPage` shape reused | Offset pagination; no pagination; a full-text filter | AC-13's minimum is actor and/or subject and a date range, and both filter columns are already indexed for it. Keyset is chosen for the same reason S2 chose it — an append-mostly table where offset paging drifts as rows arrive — and reusing that slice's criteria/page shape means the controller, template and tests all follow a pattern this codebase already has. Nothing beyond those three filters is added: there is no free-text field to search (actor and subject are ids chosen from the directory), and inventing one would be guessing. |
| **D9. (Spec open question) Rate limiting the impersonate action** | **No rate limiter** | A per-admin limiter mirroring S3's mail limiter or S1's login throttling | Stated as an explicit "no", with the reasoning, rather than omitted: every other limiter in this codebase defends a surface reachable *without* authentication (login attempts, anonymous ShareLink registration, child-triggered mail). This action requires an authenticated `ROLE_SUPER_ADMIN`, a POST, a valid CSRF token, and a target that passes the voter — and the partial unique index already caps an admin at **one** active session at a time, which is a harder ceiling than any limiter. A limiter would throttle the platform's most accountable actor during exactly the incident-response work the feature exists for, while adding no defence against the only real threat model (stolen Super Admin credentials), where the attacker is already inside and every action is already audited. **Flagged in Risks** with the trigger for revisiting it. |

## Risks

- **The `switch_user` block is a shared-firewall change, and this design's whole safety
  argument for it is that it is additive.** It adds no `access_control` line, no
  `role_hierarchy` entry, no provider and no second firewall — but it *does* mean every
  request on the `main` firewall now passes `SwitchUserListener::supports()`. Cheapest
  early check: run S1's `RouterSweepTest`, `CsrfProtectionTest`,
  `LogoutAndSessionRegenerationTest` and `SignInTest` **before** writing any impersonation
  code, immediately after adding the block alone. If those four are green, the shared
  config change is provably inert.
- **`ROLE_ALLOWED_TO_SWITCH` in `role_hierarchy` would silently kill BR-002.** If anyone
  ever grants that role (to make a test pass, or from a tutorial), `RoleVoter` grants the
  attribute unconditionally under the affirmative strategy, and impersonating another Super
  Admin becomes possible with no code change and no failing test — unless the test that
  asserts no role grants it exists. That test is listed above and is the mitigation; it
  must not be deleted as redundant.
- **D7's forced end signs the admin out entirely rather than returning them to their own
  session.** Accepted and fail-closed, but it *is* surprising in the UI: an admin
  deactivating an account they are impersonating (an odd but reachable sequence) lands on
  the login page. Cheapest improvement if it ever matters: refuse the deactivation while an
  impersonation of that account is open, and say why. Not built, because the spec does not
  ask and refusing a security action to protect a convenience is the wrong default.
- **The expiry subscriber's 302 is not instantaneous by design** (the spec's sixth edge
  case), so an impersonated tab can *display* stale content past the hour. Nothing can act
  through it — the next request is intercepted — but a reviewer looking over a shoulder can
  see an out-of-window view. Deliberate; a client that needs a hard cutoff needs
  server-push, not a session design.
- **Refused impersonation attempts are not audited (D6c).** A Super Admin probing for
  which accounts are impersonable, or any authenticated user appending `?_switch_user=`,
  leaves nothing in `account_event`. If that visibility is wanted, add a rate-limited
  counter or alert on the 403 — **not** an unbounded audit row.
- **No rate limiter (D9).** With stolen Super Admin credentials the attacker gets one
  active impersonation at a time, each audited, and can cycle targets as fast as they can
  POST. Revisit if the platform ever has more than a handful of Super Admins, or if the
  history report starts being used to detect abuse rather than to review it.
- **`impersonation_session`'s `RESTRICT` FKs make a user with impersonation history
  undeletable at the row level** — the same situation S5 flagged for
  `coach_assignment_override`. S2's deletion path anonymizes in place rather than deleting
  `app_user` rows, so this is correct rather than blocking, but it should be *exercised*:
  run `AccountLifecycleService::delete()` against a user who was both an actor and a
  subject and confirm the anonymize-in-place path completes.
- **`AccountEventRecorder` is edited, and it is the audit writer every slice depends on.**
  The edit is additive (one nullable constructor dependency, one array merge on a context
  that is already `array<string, scalar|null>`), and it runs on the recorder's independent
  connection where a `TokenStorage` read is safe — but a mistake here degrades *all* audit
  writing, not just this slice's. Cheapest early check: land the recorder edit on its own
  and require every existing `AccountEvent` assertion in the suite green with zero test
  edits before any impersonation code calls it.
- **`app:impersonation:close-expired` has no scheduler in this repo.** It is bookkeeping,
  so nothing breaks while it is unscheduled — the subscriber still force-exits on the next
  request — but abandoned rows stay open and the report shows them as in progress until
  someone runs it. Name it in the deployment notes; a `* * * * *`-style cron or a Scheduler
  recipe is the fix, and neither is invented here.
- **Time is compared in one place and must stay there.** `expires_at` is `timestamptz` and
  every comparison goes through `ImpersonationService`; the moment a template or a
  repository does its own `new \DateTimeImmutable()` arithmetic, the deadline has two
  implementations. Mitigation: the entity exposes `hasExpired(\DateTimeImmutable $now)` and
  takes "now" as an argument rather than reading the clock, so it is testable and so the
  clock has exactly one caller.

## Traceability

| Component | Acceptance criteria |
|---|---|
| "Impersonate" row action in `templates/admin/user/index.html.twig` guarded by `is_granted('ROLE_ALLOWED_TO_SWITCH', user)` | AC-1 |
| `Admin\ImpersonationController::confirm` + `admin/impersonation/confirm.html.twig`'s POST form (no row written until the POST) | AC-2 |
| `ImpersonationVoter`'s "subject is not SUPER_ADMIN / not self" clauses, consulted by `SwitchUserListener` (line 159); the same voter hiding the action; `ImpersonationService::start()`'s guard; `ImpersonationGuardSubscriber`'s POST+CSRF refusal | AC-3, AC-11 |
| Native `switch_user` + `SwitchUserToken` (the impersonated user's own roles) + `target_route: app_home` | AC-4 |
| `_impersonation_banner.html.twig` on `is_granted('IS_IMPERSONATOR')`, included once from `base.html.twig`, with the word "Impersonation" plus an `aria-hidden` glyph | AC-5 |
| `impersonation_exit_path(path('app_home'))` → `SwitchUserListener`'s exit → `ImpersonationSwitchSubscriber`'s non-`SwitchUserToken` branch → `end(EXPLICIT_EXIT)` | AC-6 |
| `ImpersonationContext` + `AccountEventRecorder`'s `impersonatorUserId` context merge | AC-7 |
| `ImpersonationExpirySubscriber` (priority 7) + stored `expires_at` + `ImpersonationService::expire()`; `app:impersonation:close-expired` for sessions whose browser never returns | AC-8 |
| `CHECK ((ended_at IS NULL AND end_reason IS NULL) OR (both NOT NULL))` + `closeIfOpen()`'s conditional `UPDATE` + the event written only on an affected row + `ImpersonationEndReason`'s three cases | AC-9 |
| `impersonation_session`'s actor/subject/`started_at`/`ended_at` columns + `getDuration()`; `IMPERSONATION_STARTED`/`IMPERSONATION_ENDED` in the account timeline | AC-10 |
| `#[IsGranted('ROLE_SUPER_ADMIN')]` on both controllers + `VIEW_IMPERSONATION_HISTORY` + `ImpersonationVoter`'s actor clause (flat `role_hierarchy`, so no other role reaches either) | AC-12 |
| `Admin\ImpersonationHistoryController::index` + `ImpersonationSearchCriteria`/`Page` + `(actor_user_id, started_at)` / `(subject_user_id, started_at)` indexes + `history.html.twig` | AC-13 |
| `ended_at`/`end_reason` populated by both end paths; the template's explicit "In progress" cell for `ended_at IS NULL`; duration computed from the two timestamps | AC-14 |

Edge cases, in the spec's table order:

1. **A Super Admin clicks "Impersonate" on their own row** — refused twice over by
   `ImpersonationVoter` (the subject is a `SUPER_ADMIN`, *and* the subject is the token
   user), so the action is never rendered and a forged POST is 403.
2. **Two Super Admins impersonate the same target simultaneously** — both succeed as
   independent sessions. The partial unique index is keyed on `actor_user_id`, not on the
   subject, precisely so two admins are never serialized against each other, and neither
   attempt is dropped.
3. **A second "Impersonate" while one is active** — refused: `ImpersonationGuardSubscriber`
   rejects a switch attempt from a `SwitchUserToken` before the native listener can do its
   silent exit-and-re-switch, `ImpersonationVoter` refuses the same case, and
   `uniq_impersonation_active_actor` is the third layer. The first session stays open and
   untouched.
4. **The impersonated user is deactivated mid-session** — D7: the row is force-ended as
   `ACCOUNT_STATE_CHANGE`, and S1's `isEqualTo()` signature change ends the browser session
   entirely (fail closed, documented, not smoothed over).
5. **The actor is deactivated or logs out while impersonating** — same forced end for
   deactivation. On logout, `invalidate_session: true` drops the token and the row is closed
   by `app:impersonation:close-expired` at its deadline, since no further request from that
   browser exists to close it — which is exactly why that command is part of the design and
   not an optimization.
6. **The hour elapses on an idle tab** — closed on the next request by the subscriber
   (with a correct `ended_at` and duration), or by the sweep if no next request ever comes;
   never instantaneous, by design (see Risks).
7. **Exit, then immediately re-impersonate the same target** — a brand-new row and a
   brand-new `IMPERSONATION_STARTED`. Nothing reopens a closed row: `closeIfOpen()` only
   ever moves a row from open to closed, and `start()` only ever inserts.
8. **The report is filtered to an empty range** — the template's explicit empty-state row,
   not an error.

**The two decisions the spec flagged as central are resolved, with their costs named rather
than minimized.** `switch_user` is **confirmed** (D1), on the installed Symfony 8.1.4 and
against the listener's actual source — and the one thing it gets wrong for this feature, a
CSRF-triggerable GET switch, is closed by the single piece of custom machinery in the slice
(D5b) rather than being left as an accepted risk. The audit question is answered **both
ways with a division of labor** (D2): a mutable `impersonation_session` row is the
authority for "active right now, expiring when, ended how", because an append-only log
cannot answer that cheaply and cannot constrain "closed exactly once" at all; the two new
`AccountEventType` cases keep impersonation visible in the one timeline every other slice
writes to, honoring `AccountEvent`'s own docblock promise that S6 reports over it. The four
remaining questions the spec delegated here are each answered explicitly: in-flight
deactivation force-ends the session (**D7**), the report reuses S2's keyset criteria/page
shape with actor/subject/date-range filters (**D8**), rate limiting is a reasoned **no**
(**D9**), and nested impersonation is **refused** at three layers rather than stacked or
silently replaced.
