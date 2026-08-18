# Design: Auth Foundation (Epic-01, slice S1)

> Answers *how*. The *what* and *why* live in `specs/auth-foundation-spec.md`
> (AC-1 … AC-25); this file does not restate them.
> Governed task: TASK-001. Branch: `feat/auth-foundation`.
> Written against the repo as it stands: PHP 8.5, Symfony 8.1.*, Doctrine ORM ^3.6,
> DoctrineBundle ^3.3, MigrationsBundle ^4.0, security-bundle, form, validator, twig;
> PostgreSQL 18 behind nginx in Docker; `src/` empty but for `Kernel.php` and
> `AppFixtures.php`; `security.yaml` still the Flex default with an in-memory provider.

## Approach

Stay on the framework's rails. S1 is a single stateful firewall using Symfony's built-in
`form_login` authenticator and an `entity` user provider, a Doctrine-backed `User` whose
**role is a scalar column, not an array**, and a Controller → Service → Repository split
in which controllers only translate HTTP and every decision that matters lives in a
service. Nothing bespoke is introduced in the authentication path itself; the bespoke
work is concentrated in four small, testable places: a `UserChecker` that folds
"Active + verified" into the credential decision, a failure handler that collapses every
rejection to one message, a composed rate limiter that expresses the G-22 thresholds, and
a stored single-use token service for email verification.

Three shaping choices carry the slice:

1. **One role, scalar.** `User.role` is a single non-null enum column. `getRoles()`
   returns exactly that one value. There is no `roles json` column, so a second role is
   not merely discouraged — it has nowhere to live (AC-15). Capability data that is not
   the security identity belongs to `Profile` records, whose contract is frozen below and
   whose tables S2 creates.
2. **One uniform failure.** "Wrong password", "unknown email", "not Active" and
   "not verified" are four different server-side facts and one client-side outcome. The
   account checks run in `checkPostAuth()` — after the password hash has already been
   computed — and the not-found path is padded with a dummy hash of identical cost, so the
   four cases match in message *and* in observable timing (AC-2).
3. **Invalidate by user state, not by session store.** Other sessions die because the
   `User` no longer compares equal to the one serialized in their token
   (`EquatableInterface`), not because someone swept a session table. This works with any
   session backend and covers reset completion (AC-12) and mid-session deactivation with
   the same mechanism.

Everything that could fail slowly or unreliably — email — leaves the request through
Messenger, so a dead SMTP host cannot change what the user sees (AC-11 and the delivery
edge case) and cannot leak existence through latency.

## Components

### Entities and schema

`user` is reserved in PostgreSQL; the table is `app_user`. All identifiers are **UUIDv7**
(`symfony/uid`, Doctrine `uuid` type, native Postgres `uuid` column) rather than the
`identity` preference in `config/packages/doctrine.yaml` — sequential integers leak account
counts and invite enumeration in a multi-tenant product, and UUIDv7 stays index-local.

**`App\Entity\User`** → `app_user`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7 |
| `email` | `varchar(180)` | stored already normalized: trimmed, `mb_strtolower` |
| `password_hash` | `varchar(255)` | non-null in S1; S2 may relax for invitations |
| `role` | `varchar(32)` | backed enum `UserRole` — **single value, never an array** |
| `status` | `varchar(32)` | backed enum `UserStatus` (`ACTIVE`, `DEACTIVATED`); S1 honours, S2 sets |
| `email_verified_at` | `timestamptz NULL` | verified ⇔ not null |
| `password_changed_at` | `timestamptz NULL` | part of the equality signature |
| `last_login_at` | `timestamptz NULL` | |
| `created_at` / `updated_at` | `timestamptz` | |

Constraints written by hand in the migration:

- `UNIQUE (email)` — the platform-wide uniqueness of BR-001.
- `CHECK (email = lower(email))` — makes unnormalized data *unstorable*, so the unique
  index is a case-insensitive one by construction. Doctrine DBAL does not model check
  constraints, so this produces no schema-diff noise.
- `CHECK (role IN ('ROLE_SUPER_ADMIN','ROLE_TRAINER','ROLE_COACH','ROLE_PLAYER'))`.
- `CHECK (status IN ('ACTIVE','DEACTIVATED'))`.

`User` implements `UserInterface`, `PasswordAuthenticatedUserInterface` and
`EquatableInterface`. `isEqualTo()` compares `id`, `role`, `status`, `password_hash` and
`email_verified_at`; any change to those makes every *other* live session fail its next
request. `getRoles()` returns `[$this->role->value]` and nothing else — `ROLE_USER` comes
from the hierarchy, not from the entity.

**`App\Entity\EmailVerificationToken`** → `email_verification_token`

`id uuid PK`, `user_id uuid FK ON DELETE CASCADE`, `selector varchar(24) UNIQUE`,
`hashed_verifier char(64)`, `expires_at timestamptz`, `created_at timestamptz`,
`consumed_at timestamptz NULL`. Index on `(user_id, consumed_at)`. The token handed to the
user is `selector . verifier`; only the selector is indexed and only a SHA-256 of the
verifier is stored, so a database read yields nothing usable (mirrors the selector/verifier
discipline of reset-password-bundle).

**`ResetPasswordRequest`** → `reset_password_request`, the entity required by
`symfonycasts/reset-password-bundle` (`ResetPasswordRequestInterface`: `user`, `selector`,
`hashedToken`, `requestedAt`, `expiresAt`). We write the class and repository; the bundle
owns the crypto and the expiry check.

**`App\Entity\AuthEvent`** → `auth_event` (AC-24)

`id uuid`, `occurred_at timestamptz`, `type varchar(64)`, `outcome varchar(16)`,
`user_id uuid NULL FK ON DELETE SET NULL`, `identifier_attempted varchar(180) NULL`
(normalized email, for failures against unknown accounts), `ip inet`,
`user_agent varchar(255)`, `context jsonb`. Indexes: `(user_id, occurred_at DESC)`,
`(type, occurred_at DESC)`, `(ip, occurred_at DESC)`. A table rather than a log channel
because S6 has to *report* over impersonation history, and reports need queries.

**`messenger_messages`** — created by the Doctrine transport, for queued mail.

**Migration plan.** One migration, `Version…AuthFoundation`: `app_user`, then
`email_verification_token`, `reset_password_request`, `auth_event`, then
`messenger_messages`. No `INSERT` of any account anywhere in migration history (AC-25).
Down-migration drops in reverse order.

**Concurrent registration.** `UniqueEntity` on `User.email` gives the friendly path;
`UserAccountService::create()` additionally catches
`Doctrine\DBAL\Exception\UniqueConstraintViolationException` and rethrows
`EmailAlreadyInUseException`, which controllers map to a field-level form error and the
console command maps to exit code 1. Note for phase 3: after that violation the
`EntityManager` is **closed**, so the catch block must not touch it again — the service
returns and the caller re-renders.

### The User ↔ Profile shape (G-23, AC-15)

Frozen here, built in S2 (the spec puts profiles in Out of scope). The contract:

- **`Profile` is an abstract Doctrine entity with `JOINED` (class-table) inheritance.**
  Base table `profile`: `id uuid`, `user_id uuid FK ON DELETE CASCADE`,
  `type varchar(32)` discriminator, `created_at`, `updated_at`, `deleted_at NULL`, plus
  `UNIQUE (user_id, type)`.
- Concrete subclasses get their own tables — `profile_player`, `profile_coach`,
  `profile_trainer`, later `profile_child` — each holding only its own columns. Joined
  rather than single-table because the role-specific field sets barely overlap and a
  single table would become a wall of nullable columns; joined rather than independent
  entities because S2's Users directory and S4's context switcher both need
  "every profile of this user" as one query.
- **One profile per type per user, many types per user.** A parent who also plays is one
  `User` with `role = ROLE_PLAYER` and two profile rows. The `UNIQUE (user_id, type)`
  constraint is what makes that statement enforceable rather than aspirational.
- **The load-bearing invariant: profiles carry capability data, never authority.** No
  voter, no `access_control` rule, no `getRoles()` implementation may ever read a profile.
  Authorization reads `User.role` and (from S2) organization ownership. If a later slice
  needs a profile to grant access, that is a signal the role model is wrong, not a licence
  to bend this rule.

S1 ships none of these tables. What S1 ships is the scalar `role` column, which is the half
of AC-15 that is testable today; S2 adds the hierarchy additively, with no live data to
migrate.

### Authentication

`config/packages/security.yaml` replaces the Flex default wholesale.

- **Provider:** `entity` provider on `App\Entity\User`, with `UserRepository implements
  UserLoaderInterface`. `loadUserByIdentifier()` normalizes the submitted string
  (trim + lowercase) before the lookup, so `Ann@x.com ` and `ann@x.com` resolve to the same
  row without a `LOWER()` scan.
- **Authenticator:** the built-in `form_login`, not a custom `AbstractAuthenticator`.
  `enable_csrf: true` (token id `authenticate`, already declared in `config/packages/csrf.yaml`),
  `username_parameter: _username`, `password_parameter: _password`,
  `check_path: app_login`, `default_target_path: app_home`,
  `always_use_default_target_path: false` (a deep link intercepted by the entry point still
  returns the user where they were going; AC-16 governs only the no-target case).
- **The Active + verified + correct-password decision.**
  `App\Security\AccountStatusChecker implements UserCheckerInterface` puts **both** the
  status check and the verification check in **`checkPostAuth()`**, not `checkPreAuth()`.
  That ordering is deliberate: `checkPostAuth()` runs *after* `CheckCredentialsListener`
  has verified the password, so every failure against an existing account costs exactly one
  real Argon2id computation regardless of which fact rejected it. The checker throws
  distinct `CustomUserMessageAccountStatusException`s so the server knows why;
  `App\Security\UniformAuthenticationFailureHandler` (wired as `form_login.failure_handler`)
  discards the exception type and always renders the same single message, at the same
  status. Precise server-side, uniform client-side.
- **Timing for unknown accounts.** `hide_user_not_found: true` fixes the message but not the
  clock: a missing row never reaches the hasher. `App\Security\LoginTimingPaddingSubscriber`
  listens on `LoginFailureEvent`, and when the cause is `UserNotFoundException` performs one
  `PasswordHasherInterface::verify()` against a constant dummy hash produced with the same
  algorithm and parameters. Cost is then matched on every path (AC-2).
- **Session (AC-7, AC-8).** `framework.session`: `cookie_secure: auto` (`true` pinned in
  prod), `cookie_httponly: true`, `cookie_samesite: lax` (email links are top-level GETs and
  must survive), `cookie_lifetime: 0`, `gc_maxlifetime: 28800`, and in prod
  `cookie_name: __Host-SESSID` (dev keeps a plain name, since `__Host-` requires HTTPS).
  Inactivity is enforced deterministically by `App\EventSubscriber\SessionIdleSubscriber`
  on `kernel.request` — it stamps `_last_activity` and invalidates past
  `%app.session_idle_seconds%` (default `28800`, i.e. Q-01.07's 8 hours) — because garbage
  collection is probabilistic and AC-7 is not. Regeneration on sign-in is `form_login`'s
  own behaviour; regeneration on password change is explicit (see below).
- **Logout (AC-6).** Built-in `logout: { path: app_logout, invalidate_session: true,
  enable_csrf: true }`, POST-only. Destroying the session server-side is what makes a
  replayed identifier resolve to an empty, unauthenticated session.
- **Deleted user / deactivated mid-session.** `EntityUserProvider::refreshUser()` throws
  `UserNotFoundException` when the row is gone; `EquatableInterface` catches the status
  change. Both end the session at the *next* request, as the spec's edge cases require.

### Authorization

- **Flat role hierarchy.** `role_hierarchy` maps each of the four roles to `ROLE_USER` and
  to nothing else. `ROLE_SUPER_ADMIN` deliberately does **not** inherit `ROLE_TRAINER`:
  reaching a trainer's workspace is impersonation, which is S6 and is audited, not a side
  effect of hierarchy.
- **Default deny, then an explicit allow-list.** `access_control` in order:
  `^/(login|logout)$`, `^/reset-password`, `^/verify-email`, `^/(css|js|images)/`,
  `^/favicon.ico` → `PUBLIC_ACCESS`; final catch-all `^/` → `ROLE_USER`. Because only the
  first matching rule applies, the catch-all makes every future route private until someone
  deliberately opens it (AC-18).
- **Belt and braces.** Every non-public controller class also carries `#[IsGranted]` with
  its role. `access_control` is a path net and a new route prefix slips through it; the
  attribute states the requirement where the code is. A functional test walks the router
  and asserts every route is either in the public list above or answers 302/403 to an
  anonymous request — that test, not the config, is what actually holds AC-18 over time.
- **No voters in S1.** There are no owned objects yet; S1 establishes the role, not
  organization ownership. Voters arrive in S2 with `Organization` (an `OrganizationVoter`
  plus a Doctrine filter for tenant scoping). Writing one now would freeze a tenancy model
  that S2 owns.
- **Dashboards (AC-16).** Four routes — `/admin`, `/trainer`, `/coach`, `/player` — each a
  thin controller with `#[IsGranted]`. `HomeController` at `/` delegates to
  `App\Security\RoleLandingResolver::routeFor(UserRole): string` and redirects. One resolver,
  one test, four rows.
- **AC-17 is a server statement.** Twig hides links for tidiness; the refusal comes from
  `access_control` and `#[IsGranted]`. Tests assert the refusal with the navigation absent
  *and* present.

### Password reset

`App\Service\PasswordResetService`, over reset-password-bundle.

- `request(string $emailInput)`: normalize → consume rate limiters (below) → look up the
  user → if found, `ResetPasswordRequestRepository::removeRequests($user)` **first** (this
  is what makes the spec's "earlier outstanding tokens are refused" edge case true; the
  bundle does not do it for you), then `ResetPasswordHelper::generateResetToken()`, then
  dispatch the mail. Whether or not a user was found, the controller renders the identical
  `check-email` page (AC-11).
- The bundle's own throttle is **disabled** (`throttle_limit: 0`) so that every limit in the
  slice is expressed once, in `symfony/rate-limiter`, at the G-22 numbers.
- `complete(string $token, string $plainPassword)`: one transaction — validate the token via
  `ResetPasswordHelper::validateTokenAndFetchUser()`, `removeResetRequest()`, set the new
  hash, set `password_changed_at = now()`, and `removeRequests($user)` to kill siblings.
  Because `password_changed_at` and the hash are both in the equality signature, every other
  session for that user fails at its next request (AC-12).
- The controller then calls `$session->invalidate()` and redirects to `/login`. That single
  move satisfies AC-8's regeneration-on-password-change *and* the "reset link opened while
  signed in as a different user" edge case: the visitor's session is discarded before the
  reset is applied, and the reset always acts on the token's subject.
- Lifetime `3600` (AC-10). Second use fails because the row is gone.

### Email verification

`App\Service\EmailVerificationService` + `App\Service\EmailVerificationTokenService`.

- `issue(User)`: `random_bytes(9)` → base64url selector (24 chars), `random_bytes(32)` →
  verifier; store `hash('sha256', $verifier)` and `expires_at = now + 24h`; invalidate the
  user's outstanding tokens first. Returns `selector.verifier` for the link.
- `consume(string $token)`: split, `SELECT … FOR UPDATE` by selector, `hash_equals()` on the
  verifier, reject if `consumed_at` is set or `expires_at` has passed, otherwise set
  `consumed_at` and `email_verified_at` in one transaction. The row lock is what makes
  "single use" survive two simultaneous clicks.
- **Idempotent re-verification.** The controller reports success whenever the token's
  subject ends the request verified — including when the token was already consumed. A
  replayed link is therefore a no-op success, matching the spec's edge case, while a
  consumed token for a still-unverified subject is refused.
- `resend`: a **public** endpoint (`/verify-email/resend`), because an unverified user
  cannot sign in and so cannot request it from inside the account. Same uniform response and
  same limiter shape as reset (AC-11 by analogy, AC-20 literally).

### Rate limiting and CSRF

- `App\Security\LoginRateLimiter extends AbstractRequestRateLimiter`, wired as
  `firewalls.main.login_throttling.limiter`. It composes two factories rather than accepting
  Symfony's defaults, because G-22's numbers are not the defaults:
  `login_account` — `sliding_window`, 5 / 15 minutes, key `hash('sha256', $normalizedEmail . $appSecret)`;
  `login_source` — `sliding_window`, 20 / hour, key the client IP truncated to /24 (IPv4)
  or /64 (IPv6), so rotating a single host's addresses does not reset the counter.
  Keying on the *submitted* identifier — not on a found user — is what keeps throttling from
  being an enumeration oracle (AC-19), and it is also why a correct password after the limit
  is still refused: the limiter runs before authentication.
- `password_reset_account` — 3 / hour, keyed on the normalized email;
  `password_reset_source` — 10 / hour, keyed on the truncated IP. Both are consumed by
  `PasswordResetService` and `EmailVerificationService`.
  **An exhausted *account* limiter must still render the generic check-email page**, never a
  429 — a 429 there would announce that the address exists. Only the *source* limiter may
  surface a 429, since it is independent of any account.
- Storage: a dedicated `cache.rate_limiter` pool. Filesystem by default, which is correct for
  one node and wrong for several — see Risks.
- **Trusted proxies.** The app sits behind nginx in `docker-compose.yml`. Without
  `framework.trusted_proxies: '%env(TRUSTED_PROXIES)%'` and
  `trusted_headers: ['x-forwarded-for','x-forwarded-proto','x-forwarded-host']`, every request
  reports the proxy's IP, `cookie_secure: auto` misfires, and the per-source limit becomes a
  global one that a single abuser can use to lock out everybody. This config is not optional
  decoration; AC-19 depends on it.
- **CSRF (AC-21).** `config/packages/csrf.yaml` already enables stateless CSRF with token ids
  `submit`, `authenticate`, `logout`. S1 adds no configuration — it adds the obligation that
  every state-changing route is a Symfony Form (which picks up `submit` automatically) or the
  login/logout form (which uses `authenticate`/`logout`), and tests that a missing or altered
  token is refused.

### Mail delivery

`symfony/mailer` + `symfony/messenger` + `symfony/doctrine-messenger`. `SendEmailMessage` is
routed to an `async` Doctrine transport with `retry_strategy: { max_retries: 3, delay: 5000,
multiplier: 3 }` and a `failed` transport. Two consequences the spec asks for directly: the
user-facing response cannot change when SMTP is down (the dispatch succeeds regardless), and
failures are retried and then parked somewhere an operator can see (`messenger:failed:show`,
plus a `messenger` Monolog channel).

Mail is dispatched **after** the surrounding transaction commits — `DispatchAfterCurrentBusMiddleware`
for messages raised inside a handler, and in the services simply "flush, then dispatch" — so a
rolled-back reset never produces a live link in someone's inbox.

Templated emails (`TemplatedEmail`, Twig): `emails/reset_password.html.twig`,
`emails/verify_email.html.twig`, each with a plain-text alternative. Transport comes from
`MAILER_DSN`; dev/test use `null://null` or Mailpit. The production provider is still an open
external dependency (Q-01.04).

### Auth event logging (AC-24)

`App\Service\AuthEventRecorder::record(AuthEventRecord $record)` persists and flushes in its
own transaction, independent of any business transaction, so a failed sign-in — which has no
other transaction — is still recorded, and a rolled-back reset still leaves its "requested"
trace.

`App\EventSubscriber\AuthEventSubscriber` is a thin adapter over Symfony's
`LoginSuccessEvent`, `LoginFailureEvent` and `LogoutEvent`; the reset, verification and
bootstrap events are recorded by their own services. Types: `LOGIN_SUCCEEDED`,
`LOGIN_FAILED`, `LOGGED_OUT`, `PASSWORD_RESET_REQUESTED`, `PASSWORD_RESET_COMPLETED`,
`EMAIL_VERIFIED`, `SUPER_ADMIN_BOOTSTRAPPED`.

Secrets are kept out **structurally, not by discipline**: `AuthEventRecord` is a readonly DTO
whose constructor accepts only the enumerated fields above. There is no `$request`, no array
spread, no free-form payload that a password or token could ride in on. `context` is a typed
`array<string,scalar>` assembled by the caller from named values.

### Console bootstrap (AC-25)

`App\Command\CreateSuperAdminCommand` (`app:create-super-admin`, `#[AsCommand]`), delegating
to `UserAccountService`. Interactive: `SymfonyStyle` prompts for email and for a hidden,
confirmed password. Non-interactive: falls back to `SUPER_ADMIN_EMAIL` and
`SUPER_ADMIN_PASSWORD` from the real environment — never from a committed `.env`. It applies
the same password policy as the web flow, never echoes the password, and returns 0 / 1 / 2.

If a Super Admin already exists it requires explicit confirmation (or `--force`
non-interactively), because the command is also the documented recovery path when every Super
Admin has been lost.

**It sets `email_verified_at = now()`.** An operator creating an account at a shell has
already proven possession out of band, and Q-01.05 would otherwise make the bootstrap account
unable to sign in until mail infrastructure works — a first-boot deadlock. This is the single
exception to "verification precedes sign-in", and it is confined to one code path with no HTTP
surface.

### Frontend surface (AC-22, AC-23)

Server-rendered Twig and Symfony Forms; no JS build step in S1 (no AssetMapper or Webpack
Encore is installed, and none is added — S2's photo upload and S8's branding are where that
argument belongs). One stylesheet under `public/css/`.

Templates: `security/login.html.twig`, `reset_password/request.html.twig`,
`reset_password/check_email.html.twig`, `reset_password/reset.html.twig`,
`verify_email/resend.html.twig`, `verify_email/result.html.twig`, four dashboard stubs, and
`base.html.twig`.

Forms: `ResetPasswordRequestFormType` (email), `ChangePasswordFormType` (`RepeatedType`,
`PasswordType`), `ResendVerificationFormType`. The login form stays plain semantic HTML,
because `form_login` reads `_username` / `_password` / `_csrf_token` from the raw request.

Password policy as reusable constraints on the DTO, not scattered in controllers:
`NotBlank`, `Length(min: 12, max: 4096, countUnits: COUNT_BYTES)` — bytes, so the 4096 limit is
the documented one and a multi-byte password is never silently truncated —
`NotCompromisedPassword` (HIBP k-anonymity; already anticipated by the `when@test` block in
`config/packages/validator.yaml`), and a custom `NotBlocklistedPassword` backed by an offline
top-100k list so the rule still bites when HIBP is unreachable. No composition rules, no
rotation (G-22).

Accessibility and mobile are **structural, not a later pass**. A project form theme,
`templates/form/theme.html.twig` extending `form_div_layout.html.twig`, emits
`aria-invalid="true"` and an `aria-describedby` pointing at the field's error node for every
widget, so no individual template can forget. On top of that: one `<h1>` per page; a
`role="alert"` error summary at the top of each form linking to the offending fields;
`<label for>` on every control (never placeholder-as-label); `autocomplete="email"`,
`current-password`, `new-password`; `type="email"` with `inputmode="email"`; visible
`:focus-visible` outline at ≥3:1 against its background; errors carried by text and icon, never
colour alone; 4.5:1 body contrast; `<meta name="viewport">`, single-column layout that holds at
320 px, and interactive targets ≥44×44 CSS px with ≥8 px spacing.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| HTTP translation, redirects, flashes | Controller | `SecurityController`, `ResetPasswordController`, `EmailVerificationController`, `HomeController`, `Dashboard\*Controller` |
| Account creation, normalization, unique-violation mapping | Service | `UserAccountService` |
| Reset request / completion workflow | Service | `PasswordResetService` |
| Verification issue / resend / consume | Service | `EmailVerificationService` |
| Token crypto and single-use enforcement | Service | `EmailVerificationTokenService` |
| Post-login destination | Service | `RoleLandingResolver` |
| Audit write | Service | `AuthEventRecorder` |
| Account status + verification gate | Security | `AccountStatusChecker` |
| Uniform rejection | Security | `UniformAuthenticationFailureHandler`, `LoginTimingPaddingSubscriber` |
| Throttling | Security | `LoginRateLimiter` |
| Idle expiry | Subscriber | `SessionIdleSubscriber` |
| Framework auth events → audit | Subscriber | `AuthEventSubscriber` |
| Queries and persistence | Repository | `UserRepository` (also `UserLoaderInterface`, `PasswordUpgraderInterface`), `EmailVerificationTokenRepository`, `ResetPasswordRequestRepository`, `AuthEventRepository` |
| Operator bootstrap | Command | `CreateSuperAdminCommand` |

Transaction boundary: one per service method (`EntityManager::wrapInTransaction`). Controllers
never call `flush()`. Services never return a `Response`. Repositories never authorize.

### Tests phase 3 must produce

Functional: the sign-in matrix (correct / wrong password / unknown / deactivated / unverified),
including a statistical timing assertion for AC-2; the router sweep for AC-18; CSRF rejection;
throttle behaviour including "correct password after the limit"; logout replay; idle expiry;
the full reset and verification flows including replay, expiry, sibling invalidation, and the
signed-in-as-another-user case. Repository integration: case-insensitive `loadUserByIdentifier`,
the unique violation, `FOR UPDATE` single-use under concurrency. Unit: token service, role
landing, email normalizer, password constraints. Console: the bootstrap command, both modes.

## Stack

Only what S1 adds or pins. Symfony components are pinned `8.1.*` to match the existing
constraint block.

| Choice | Version | Over the alternative, because |
|---|---|---|
| `symfony/uid` | `8.1.*` | UUIDv7 primary keys over the `identity` integers preferred in `doctrine.yaml`: sequential ids publish account counts and make enumeration free, and this is a multi-tenant product where that matters. Time-ordered, so B-tree locality survives. |
| `symfony/rate-limiter` | `8.1.*` | Native `login_throttling` integration and a `sliding_window` policy over a hand-rolled counter table; the firewall hook is the only place that can throttle *before* authentication, which is what AC-19 requires. |
| `symfony/mailer` | `8.1.*` | The framework's transport abstraction over raw SMTP, so `MAILER_DSN` swaps Mailpit for a provider without touching code. |
| `symfony/messenger` | `8.1.*` | Queued mail is the only way the user-facing response can be provably independent of SMTP health, and the only way "retried, then operator-visible" is true rather than aspirational. |
| `symfony/doctrine-messenger` | `8.1.*` | A Postgres-backed transport over Redis/AMQP: the database is already there, S1 does not justify a broker, and the transport swaps later by DSN. |
| `symfony/http-client` | `8.1.*` | Required by `NotCompromisedPassword`, which `config/packages/validator.yaml` already anticipates by disabling it in the test env. |
| `symfony/monolog-bundle` | `^3.10` | "Operator-visible failure" for mail and a `security` channel need a real logger; the framework has none configured today. |
| `symfonycasts/reset-password-bundle` | `^1.25` | **Buy.** Verified against packagist: v1.25.0 declares `symfony/config ^5.4\|^6.0\|^7.0\|^8.0` and `php >=8.1.10`, so it runs on Symfony 8.1 / PHP 8.5. It already implements selector/verifier split, hashed-at-rest verifiers, constant-time comparison, expiry and a cleanup command. Hand-rolling the flow whose token compromise equals account takeover is the wrong place to be original. Note the `2.x` branch is currently *behind* (`^6.4\|^7.0` only) — pin 1.x. |
| `symfonycasts/verify-email-bundle` | — **rejected** | **Build.** The bundle is a stateless signed-URI scheme: nothing is stored, so a verification link cannot be revoked, cannot be made single-use, and cannot be invalidated when a resend supersedes it — three things AC-13, AC-14 and the resend flow ask for. A stored token also gives S3 (ShareLinks) the countable, revocable link model it needs anyway, so the ~120 lines are not spent twice. Mitigated by copying reset-password-bundle's selector/verifier discipline rather than inventing one, and by a mandatory `security-reviewer` pass (see Risks). |
| Offline common-password list | bundled asset | Alongside `NotCompromisedPassword`, so the G-22 blocklist rule still applies when HIBP is unreachable (`skipOnError` otherwise silently passes everything). |

Not added, deliberately: `symfony/lock` (the `sliding_window` policy does not require it;
revisit with a shared backend), AssetMapper / Encore (no JS build in S1), `symfony/security-csrf`
extras (`csrf.yaml` already covers it).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| Role storage | Scalar `role varchar(32)` + PHP enum; `getRoles()` returns one element | `roles json` (the Symfony/MakerBundle default); a `user_role` join table | AC-15 demands a second role be *unrepresentable*. An array column represents it perfectly well; a scalar column cannot. |
| Role hierarchy | Flat — each role inherits `ROLE_USER` only | `ROLE_SUPER_ADMIN: [ROLE_TRAINER, …]` | Inheritance would give Super Admins silent, unaudited reach into a trainer's workspace. That reach is impersonation, which is S6 and is logged. |
| Profile shape | Abstract `Profile`, `JOINED` inheritance, base table with `UNIQUE (user_id, type)`; contract frozen in S1, tables built in S2 | Single-table inheritance; independent unrelated entities; building the tables in S1 | STI would be a wall of nullable columns as role-specific fields land in S2. Independent entities lose "all profiles of this user" as one query, which S2's directory and S4's context switcher both need. Building the tables now contradicts the spec's Out of scope, and S2 can add them additively since no data exists yet. |
| Profiles and authority | Profiles carry capability data only; authorization never reads them | Deriving effective permissions from profile presence | It is the only invariant that keeps "one role" true as S2–S6 add capabilities. A profile that grants access is a second role wearing a different hat. |
| Primary keys | UUIDv7 | `bigint` identity (the `doctrine.yaml` preference) | Enumeration resistance and merge-safety across tenants, at negligible index cost with v7's time ordering. |
| Case-insensitive uniqueness | Store lowercase + `UNIQUE (email)` + `CHECK (email = lower(email))` | `citext` extension; `UNIQUE INDEX ON (lower(email))`; nondeterministic ICU collation | The CHECK makes unnormalized rows unstorable, so a plain unique index *is* case-insensitive. DBAL does not model check constraints, so unlike a functional index it produces no perpetual schema-diff noise, and unlike citext it needs no extension or custom Doctrine type. |
| Authenticator | Built-in `form_login` | Custom `AbstractAuthenticator` | Every custom authenticator is a new security-critical code path. Everything S1 needs hangs off documented extension points: user checker, failure handler, login throttling. |
| Where the Active/verified gate lives | `UserCheckerInterface::checkPostAuth()` | `checkPreAuth()`; a check inside the controller | `checkPostAuth()` runs after the password has been hashed, so a rejected-but-existing account costs the same as a wrong password. `checkPreAuth()` short-circuits and hands an attacker a timing oracle for AC-2. |
| Uniform rejection | Distinct exceptions server-side, collapsed to one message by a failure handler; dummy hash on the not-found path | One generic exception thrown everywhere | AC-24 needs to know *why* a sign-in failed; AC-2 needs the caller not to. Splitting the two lets both be true. |
| Invalidating other sessions | `EquatableInterface` over `id`, `role`, `status`, `password_hash`, `email_verified_at` | Sweeping the session store for that user | Works with any session backend, needs no session-to-user index, and covers reset (AC-12) and mid-session deactivation with one mechanism. |
| Verification token single-use | Stored token, `SELECT … FOR UPDATE`, `consumed_at` | Optimistic check-then-write | Two simultaneous clicks on the same link are ordinary, not exotic; the row lock is what makes "single use" a fact rather than a race. |
| Reset throttling | Bundle throttle disabled (`throttle_limit: 0`); all limits in `symfony/rate-limiter` | Bundle throttle (1/hour) plus rate limiter | Two throttles at different numbers means two places to look and one G-22 violation. |
| Throttle rejection surface | Account-limiter rejection renders the same generic page; only source-limiter rejection may 429 | 429 on both | A 429 keyed to an address announces that the address exists, undoing AC-11 through AC-20's front door. |
| Mail | Async via Messenger Doctrine transport, dispatched post-commit | Synchronous send | The spec requires the user-facing response to be unchanged when delivery fails; with a synchronous send it is not, in status *or* in latency. Post-commit dispatch stops rolled-back work from mailing live links. |
| Auth event storage | `auth_event` table + `security` Monolog channel | Log file only | S6 must *report* over this data. Reports need queries, and grep is not one. |
| Secret exclusion from logs | Readonly DTO with enumerated fields only | Reviewer discipline / redaction filters | A redaction filter fails open the first time someone adds a field. A DTO with no field capable of holding a secret cannot. |
| Bootstrap account verification | Command sets `email_verified_at` directly | Sending a verification mail from the command | Q-01.05 plus a mail-dependent bootstrap is a first-boot deadlock. Confined to one command, no HTTP surface. |
| Frontend | Server-rendered Twig + Forms, accessible form theme, no JS build | AssetMapper / Encore in S1 | Four forms do not justify an asset pipeline. The theme is what makes AC-22 structural — templates cannot forget what the theme emits. |
| Session inactivity | Explicit `SessionIdleSubscriber` + `gc_maxlifetime` backstop | `gc_maxlifetime` alone | GC is probabilistic; AC-7 is not. |

## Risks

- **A Messenger worker that nobody runs.** If `messenger:consume` is not deployed, no reset
  or verification mail is ever sent and every user-facing response still says "check your
  email" — a silent, total failure of two flows. Cheapest early detection: a smoke test in
  staging that asserts `messenger:stats` drains, plus an alert on queue depth. Deployment
  docs for the worker are phase 4 work and must not be skipped.
- **Rate limiter and session storage are per-node.** The default filesystem cache pool means
  5 failures per node, not per cluster, and file sessions do not survive a second app
  container. Single-node deploys are fine; the moment a second one appears, AC-19 quietly
  weakens. Find out early by declaring the limit in the deployment doc now and moving the
  pool to Redis before the second node, not after.
- **Trusted proxies are load-bearing.** Miss `TRUSTED_PROXIES` behind the nginx container and
  the per-source limit collapses onto the proxy's IP — one abuser locks out every user, and
  `cookie_secure: auto` resolves wrongly. Detect with a functional test asserting the resolved
  client IP under an `X-Forwarded-For` header.
- **Timing uniformity is empirical and regresses silently.** The dummy-hash padding matches
  cost only while the hasher config matches; a future `password_hashers` change or an early
  `return` in the checker reopens the oracle. The AC-2 test must compare distributions across
  all four failure paths, not just assert equal messages, and must run in CI.
- **Hand-rolled verification tokens.** The rejection of verify-email-bundle puts ~120 lines of
  security-relevant code in our repo. Mitigation is non-negotiable: `security-reviewer` on that
  file specifically, `random_bytes` only, `hash_equals` only, and property tests covering
  expiry, replay, sibling invalidation and concurrent consume.
- **`symfonycasts/reset-password-bundle` 2.x is behind 1.x on Symfony support** (2.x-dev
  currently declares `^6.4|^7.0`; 1.25.0 declares `^8.0`). We pin `^1.25`. If upstream moves
  and 1.x stops receiving fixes before 2.x supports Symfony 8, we inherit an unmaintained
  security dependency. Watch it; the fallback is to vendor the ~300 lines we use.
- **HIBP in the password-set path.** `NotCompromisedPassword` makes an outbound call while a
  user is changing their password. `skipOnError: true` means an outage silently weakens the
  policy — which is why the offline list exists. Verify the offline path by testing with the
  HTTP client mocked to fail.
- **Stateless CSRF behaviour needs confirmation, not assumption.** `config/packages/csrf.yaml`
  already uses Symfony's stateless token ids; the exact cookie/field interaction should be
  proven by a functional test that strips the token and asserts refusal, before four forms are
  built on an assumed mechanism. `context7` was unavailable during this design pass (monthly
  quota exhausted), so no API detail here was confirmed against current docs — phase 3 should
  re-verify the security and CSRF configuration keys against the installed 8.1 sources.
- **`auth_event` grows without bound.** Every failed sign-in on the internet writes a row.
  A retention/purge policy is S6's audit work, but the index plan and a `--older-than` purge
  command should be considered before the table is large enough to make adding indexes painful.
- **Session lifetime must stay a config value.** Q-01.07 is unanswered; the 8-hour default
  lives in `%app.session_idle_seconds%` and `gc_maxlifetime` and nowhere else. If any code
  hard-codes 28800 or branches on it, answering the question stops being a config change and
  becomes a design change — which the spec explicitly says it must not be.
- **`SUPER_ADMIN_PASSWORD` must never reach `.env`.** `.env` is committed in this repo. The
  command reads the real environment or `.env.local`; a phase-3 reviewer should check that no
  default for that variable is added to a tracked file (AC-25).
- **`EntityManager` closes after a unique violation.** The concurrent-registration path must
  not touch the manager after catching it, or the "validation error, not a 500" outcome turns
  back into a 500 one layer up.
- **AC-13 has no producer inside S1** — see Traceability. The flow ships exercised only by
  tests and the resend endpoint. Risk: it rots undetected until S2 uses it. Mitigation: full
  functional coverage of `EmailVerificationService` now, driven directly, plus an explicit
  note in the S2 plan that account creation must call `issue()`.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `security.yaml` firewall, `form_login`, `UserRepository` as `UserLoaderInterface` | AC-1, AC-3, AC-5 |
| `AccountStatusChecker` (`checkPostAuth`), `UniformAuthenticationFailureHandler`, `LoginTimingPaddingSubscriber` | AC-1, AC-2 |
| `password_hashers: auto` (Argon2id), `AuthEventRecord` field enumeration | AC-4 |
| `app_user` `UNIQUE (email)` + `CHECK (email = lower(email))`, email normalizer, `UserAccountService` violation mapping | AC-5 |
| `logout` config (`invalidate_session`, `enable_csrf`, POST) | AC-6 |
| `framework.session` cookie flags, `SessionIdleSubscriber` | AC-7 |
| `form_login` regeneration; `$session->invalidate()` on reset completion | AC-8 |
| `PasswordResetService::request`, reset-password-bundle helper | AC-9 |
| Bundle `lifetime: 3600`, `removeResetRequest()` | AC-10 |
| Uniform `check-email` response; account-limiter rejection renders the same page | AC-11 |
| `PasswordResetService::complete` + `EquatableInterface` + `removeRequests()` | AC-12 |
| `EmailVerificationTokenService::issue/consume`, `EmailVerificationToken` | AC-13, AC-14 |
| `User.role` scalar column + `CHECK`, `getRoles()`, frozen Profile contract | AC-15 |
| `RoleLandingResolver`, `HomeController`, four dashboard controllers | AC-16 |
| `#[IsGranted]` on every non-public controller; refusal tests with navigation present | AC-17 |
| `access_control` allow-list + `^/` catch-all; router-sweep test | AC-18 |
| `LoginRateLimiter` (`login_account` 5/15min, `login_source` 20/hr), trusted proxies | AC-19 |
| `password_reset_account` 3/hr, `password_reset_source` 10/hr, consumed by both services | AC-20 |
| `csrf.yaml` stateless ids; Forms for every state-changing route | AC-21 |
| `templates/form/theme.html.twig`, error summary, labels, autocomplete, focus-visible, contrast | AC-22 |
| Viewport meta, single-column at 320 px, ≥44 px targets | AC-23 |
| `AuthEventRecorder`, `AuthEventSubscriber`, `auth_event` table, `AuthEventRecord` DTO | AC-24 |
| `CreateSuperAdminCommand`, no account INSERT in any migration | AC-25 |

**One criterion has no in-slice producer.** AC-13 says "account creation issues a
verification link", but S1 deliberately has no account-creation path other than
`app:create-super-admin`, which marks the address verified directly. The issue/consume
services and the resend endpoint are built and fully covered by tests, and the resend
endpoint gives AC-13's token a live producer; the *account-creation* trigger arrives with
S2's Super-Admin-creates-trainer flow and S3's ShareLink registration. Phase 3 should build
the flow; phase 4 should not expect to demonstrate it from a registration screen, because no
registration screen exists in S1.

No other criterion is uncovered.
