# Plan: Auth Foundation (Epic-01, slice S1) — TASK-001

> Executes `specs/auth-foundation-spec.md` (AC-1…AC-25) per
> `specs/auth-foundation-architecture.md`. The architecture is settled — this
> plan sequences it into commits; it does not redesign it. Branch:
> `feat/auth-foundation`. Phase 4 (coder, test-generator) executes these
> checkboxes in order and updates this file as the execution record.

## Current state (verified 2026-08-18)

- `src/` has only `Kernel.php` and `DataFixtures/AppFixtures.php` (empty
  `load()`). No entities, controllers, repositories, security classes.
- `config/packages/security.yaml` is the Flex default: in-memory provider, no
  authenticator, empty `access_control`.
- `config/packages/csrf.yaml` already configures stateless CSRF
  (`stateless_token_ids: submit, authenticate, logout`) — do not recreate it.
- `config/packages/framework.yaml` sets only `secret` and `session: true`; no
  cookie flags, no `trusted_proxies`.
- `config/packages/doctrine.yaml` uses `identity` PK generation for
  PostgreSQL — the architecture overrides this to UUIDv7 per entity via
  `#[ORM\GeneratedValue(strategy: 'CUSTOM')]` / `#[ORM\CustomIdGenerator]`,
  not by editing the global `identity_generation_preferences`.
- Not installed: `symfony/uid`, `symfony/rate-limiter`, `symfony/mailer`,
  `symfony/messenger`, `symfony/doctrine-messenger`, `symfony/http-client`,
  `symfony/monolog-bundle`, `symfonycasts/reset-password-bundle`.
- `migrations/` is empty (only `.gitignore`). `config/routes/security.yaml`
  and `config/routes/framework.yaml` exist (Flex-generated, framework
  internals) — new app routes are declared via `#[Route]` attributes on
  controllers, picked up by `config/routes.yaml`'s `controllers` import.
- `tests/bootstrap.php` exists; no test cases yet. `phpunit.xml.dist` /
  test runner presence must be confirmed in Task 1 (report `N/A` if absent).

## Non-goals (carried from spec's Out of scope — do not build here)

- Self-registration, ShareLinks, User CRUD/directory, profile tables,
  multi-tenancy isolation, family accounts, impersonation, portal branding,
  MFA/SSO, self-service email change. `Profile` is a **frozen contract, not
  built** — no profile table, no profile entity ships in S1.
- No account-creation trigger for AC-13 in S1 (see the Coverage table's note
  on AC-13 — this is a decided boundary, not an omission).

## Assumptions still needing proof in Task 1 / Task 2

- Exact `form_login` / `login_throttling` / `access_control` YAML keys for
  Symfony 8.1, since `context7` was unavailable during design (architecture
  Risks section). Confirm against **installed** `vendor/` sources, not memory.
- Exact `stateless_token_ids` cookie/field interaction for the login and
  logout forms (raw HTML, not a Symfony Form) — confirm before wiring
  `form_login`'s `enable_csrf: true`.
- `symfonycasts/reset-password-bundle ^1.25` config keys
  (`reset_password.yaml`: `request_password_repository`, `lifetime`,
  `throttle_limit`) against the version actually resolved by Composer.
- Whether a test runner (PHPUnit) and a `_test` database are already
  reachable in this environment — Task 1 must report this, not assume it.

## Execution environment (established 2026-08-18, answers the assumption above)

- **Test runner: present and working.** PHPUnit 13.3.1, config
  `phpunit.dist.xml` (note: *not* `phpunit.xml.dist`), bootstrap
  `tests/bootstrap.php`, run with `php bin/phpunit`. It sets
  `failOnDeprecation`/`failOnNotice`/`failOnWarning` — any deprecation emitted
  by code written here fails the suite, so no task may leave one behind.
- **The host PHP cannot reach the database.** `/usr/bin/php8.5` has no
  `pdo_pgsql` (`could not find driver`), and `DATABASE_URL`'s host is
  `database`, which only resolves inside the Compose network. Every
  DB-touching command must run in the container:
  `docker compose exec -T php php bin/console <cmd>`, or with
  `-e APP_ENV=test` for the test environment. Verified: the container has
  `pdo_pgsql` and reaches **PostgreSQL 18.6**.
- **Containers are up**: `database` (healthy, published on 5432), `nginx`
  (8080), `php`. Bridge subnet 172.22.0.0/16.
- **The `_test` database does not exist yet.** `config/packages/doctrine.yaml`
  sets `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` under `when@test`,
  so the target is `app_test`, and it currently errors with
  `FATAL: database "app_test" does not exist`. **Task 9 must create it**
  (`docker compose exec -T -e APP_ENV=test php php bin/console
  doctrine:database:create`) before any repository or integration test in
  Tasks 10, 11, 17, 19, 21, 23, 28, 32, 33, 35, 36 can run.
- Tests that only boot the kernel without touching Doctrine (e.g. Task 3's
  `TrustedProxyTest`) run fine on the host runner.

## Compatibility constraints

- `config/packages/csrf.yaml` is not to be modified — it already has what S1
  needs.
- `config/packages/doctrine.yaml`'s `identity_generation_preferences` for
  PostgreSQL stays as-is (S1 does not force UUIDs project-wide); UUID PKs are
  declared per-entity.
- Do not edit `.env` with any secret value (`SUPER_ADMIN_PASSWORD`, mailer
  credentials, etc.) — non-secret defaults only (e.g. `TRUSTED_PROXIES`,
  `MAILER_DSN=null://null`) may go in `.env`; secrets go in `.env.local`
  (untracked) or real environment variables, per AGENTS.md and the
  architecture's Risks section.

---

## Tasks

- [x] 1. **Install and pin the S1 Composer dependencies.**
  Run `composer require symfony/uid:8.1.* symfony/rate-limiter:8.1.*
  symfony/mailer:8.1.* symfony/messenger:8.1.* symfony/doctrine-messenger:8.1.*
  symfony/http-client:8.1.* symfony/monolog-bundle:^3.10
  symfonycasts/reset-password-bundle:^1.25`. Let Symfony Flex apply its
  recipes (creates/updates `config/packages/messenger.yaml`,
  `config/packages/mailer.yaml`, `config/packages/monolog.yaml`,
  `config/packages/reset_password.yaml`, adds entries to
  `config/bundles.php`). Do not hand-edit the recipe-generated files yet —
  later tasks own their content.
  Verify: `composer validate --strict`; `composer install` exits 0; `git diff
  --stat` shows `composer.json`, `composer.lock`, `config/bundles.php`, and
  the new `config/packages/*.yaml` recipe files; `php bin/console
  about` runs without a fatal error.
  (Supports AC-4, AC-9, AC-10, AC-11, AC-12, AC-13, AC-14, AC-19, AC-20,
  AC-24 — no criterion is satisfied by this task alone.)

  **Done 2026-08-18.** Deviation: `symfony/monolog-bundle:^3.10` is
  uninstallable on Symfony 8.1 — 3.11.x requires `symfony/config ^6.4 || ^7.0`
  and 3.10.0 requires `symfony/monolog-bridge ^5.4 || ^6.0 || ^7.0`. Installed
  `symfony/monolog-bundle:^4.0` (resolved v4.0.2, requires
  `symfony/config ^7.3 || ^8.0`) instead; all other pins landed as written.
  Resolved: `symfony/uid` v8.1.4, `symfony/rate-limiter` v8.1.4,
  `symfony/mailer` v8.1.4, `symfony/messenger` v8.1.4,
  `symfony/doctrine-messenger` v8.1.4, `symfony/http-client` v8.1.4,
  `symfonycasts/reset-password-bundle` v1.25.0.
  Verify results: `composer install` exits 0; `php bin/console about` runs
  clean (Symfony v8.1.4, PHP 8.5.9); `composer audit` reports no advisories;
  recipes created `config/packages/{messenger,mailer,monolog,reset_password}.yaml`,
  `compose.override.yaml` (mailpit), added `MESSENGER_TRANSPORT_DSN` and
  `MAILER_DSN=null://null` to `.env` (non-secret), and registered
  `MonologBundle` + `SymfonyCastsResetPasswordBundle` in `config/bundles.php`.
  `composer validate --strict` exits 2 on `name`/`description` missing — a
  **pre-existing** property of the Flex skeleton's `composer.json`, unrelated
  to this task and not changed here.

- [x] 2. **Verify security, CSRF, rate-limiter and messenger config keys
  against installed `vendor/` sources — before any form is built.**
  Read the actual installed reference config, not memory or training data:
  `vendor/symfony/security-bundle/Resources/config/schema/security-1.0.xsd`
  (or the bundle's `Configuration.php`) for `form_login`, `login_throttling`,
  `access_control`, `logout`, `role_hierarchy`, `password_hashers` keys;
  `vendor/symfony/rate-limiter/*` and
  `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php`
  for the `rate_limiter:` tree (`policy`, `limit`, `interval`,
  `cache_pool`) and `AbstractRequestRateLimiter`'s constructor contract;
  `vendor/symfony/messenger/*` and the Flex-generated
  `config/packages/messenger.yaml` for the Doctrine transport,
  `retry_strategy`, `failure_transport` keys; and
  `vendor/symfonycasts/reset-password-bundle/config/packages/reset_password.yaml`
  (or the bundle's own recipe output) for
  `request_password_repository`, `lifetime`, `throttle_limit`. Cross-check
  `enable_csrf: true` under `form_login` against
  `config/packages/csrf.yaml`'s `stateless_token_ids` to confirm the field
  name the login form must submit and that `logout` also needs
  `enable_csrf: true` with the `logout` token id.
  Write findings as a short dated note appended to this plan file under a
  new `## Config verification notes (Task 2)` heading: each confirmed key
  quoted with its source file path/line. Any key that cannot be confirmed
  from installed sources is flagged there, not guessed.
  Verify: the note exists, cites a real path under `vendor/` for every key
  it confirms, and every later task that writes one of these config blocks
  (Tasks 12, 13, 22, 29, 30) is written to match — no task past this point
  invents an unconfirmed key.
  (Directly de-risks AC-21; also the basis for AC-9, AC-10, AC-13, AC-14,
  AC-19, AC-20 being implemented correctly. Cited fully as AC-21.)

  **Done 2026-08-18.** Findings written to `## Config verification notes
  (Task 2)` at the end of this file. Every key Tasks 12/13/22/29/30 need was
  confirmed in an installed source; nothing was left unconfirmed. Note there
  is no `security-1.0.xsd` in this installation — SecurityBundle ships PHP
  configuration classes only, so `MainConfiguration.php` and the
  `Security/Factory/*.php` classes are the authority used.

- [x] 3. **Configure `TRUSTED_PROXIES` for the nginx-fronted Docker stack.**
  Edit `config/packages/framework.yaml`: add
  `framework.trusted_proxies: '%env(TRUSTED_PROXIES)%'` and
  `framework.trusted_headers: ['x-forwarded-for','x-forwarded-proto','x-forwarded-host']`.
  Add `TRUSTED_PROXIES` to `.env` with a non-secret default matching the
  Docker Compose network (e.g. the `nginx` service's reachable CIDR, such as
  `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` for the private ranges Docker
  bridge networks use — confirm the actual bridge subnet with `docker network
  inspect` against `docker-compose.yml` rather than guessing a value that
  doesn't match this stack). Do not put anything secret in this variable.
  Write `tests/Functional/TrustedProxyTest.php`: a request through the test
  client carrying `X-Forwarded-For: <client-ip>` from an allowed proxy
  resolves `Request::getClientIp()` to `<client-ip>`, not the proxy's.
  Verify: `php bin/phpunit tests/Functional/TrustedProxyTest.php`; `php bin/console
  lint:yaml config/packages/framework.yaml`; `php bin/console debug:config
  framework | grep -A2 trusted_proxies` shows the resolved value.
  (AC-19, AC-7 — without this, the per-source throttle and `cookie_secure:
  auto` both misresolve to the proxy's address per the architecture's Risks
  section.)

  **Done 2026-08-18.** `config/packages/framework.yaml` now sets
  `trusted_proxies: '%env(TRUSTED_PROXIES)%'` and the three `trusted_headers`;
  `.env` gains `TRUSTED_PROXIES=private_ranges` (non-secret).
  Deviation from the sketch, per Task 2's finding: the value is
  `private_ranges`, not a hand-copied CIDR list. `docker network inspect
  ai-training-symfony_default` reports the bridge subnet as **172.22.0.0/16**
  — assigned dynamically by Docker, so pinning it would break whenever the
  network is recreated. `private_ranges` expands to `IpUtils::PRIVATE_SUBNETS`
  (`Request::setTrustedProxies()`,
  `vendor/symfony/http-foundation/Request.php:648-651`, which honours the
  literal at runtime as well as in config normalization) and covers
  172.16.0.0/12 and every other pool Docker can hand out.
  `tests/Functional/TrustedProxyTest.php` covers three cases and passes
  (3 tests, 4 assertions): X-Forwarded-For from a bridge address resolves to
  the real client; X-Forwarded-Proto/Host make `isSecure()` true and
  `getSchemeAndHttpHost()` the public origin the CSRF check needs; and a
  spoofed X-Forwarded-For from an untrusted peer is ignored.
  `lint:yaml` clean; `debug:config framework trusted_headers` lists all three.

  **Trap for Tasks 22, 23 and any later per-IP test.**
  `IpUtils::PRIVATE_SUBNETS` contains the RFC 5737 documentation ranges —
  `192.0.2.0/24`, `198.51.100.0/24` and `203.0.113.0/24` — the very addresses
  tests reach for as "fake public IPs". Under `private_ranges` those are
  **trusted proxies**, so a test that sets `REMOTE_ADDR` to one of them has
  its `X-Forwarded-For` honoured and `getClientIp()` will not be the value the
  test expects. This cost one failing assertion here before it was diagnosed.
  Per-IP throttle tests must drive distinct clients via `X-Forwarded-For`
  behind a bridge-range `REMOTE_ADDR`, or use genuinely public literals
  (e.g. `8.8.8.8`, `93.184.216.34`) for the untrusted side.

- [x] 4. **Create `UserRole` and `UserStatus` backed enums.**
  New files `src/Enum/UserRole.php` (`ROLE_SUPER_ADMIN`, `ROLE_TRAINER`,
  `ROLE_COACH`, `ROLE_PLAYER`, string-backed) and
  `src/Enum/UserStatus.php` (`ACTIVE`, `DEACTIVATED`, string-backed).
  `declare(strict_types=1)`.
  Verify: `php -l src/Enum/UserRole.php src/Enum/UserStatus.php`.
  (AC-15 — the enum is half of "a second role is unrepresentable"; the other
  half is the schema `CHECK` in Task 9.)

  **Done 2026-08-18.** `src/Enum/UserRole.php` (cases `SUPER_ADMIN`,
  `TRAINER`, `COACH`, `PLAYER`, backed by the literal Symfony role strings
  `ROLE_SUPER_ADMIN`/`ROLE_TRAINER`/`ROLE_COACH`/`ROLE_PLAYER`, so
  `getRoles()` needs no mapping table) and `src/Enum/UserStatus.php`
  (`ACTIVE`, `DEACTIVATED`). `ROLE_USER` is deliberately not a case — it comes
  from `role_hierarchy` (Task 12). `php -l` clean on both.

- [x] 5. **Create the `User` entity.**
  New file `src/Entity/User.php`, table `app_user`, mapped per the
  architecture's column table: `id` (`uuid`, UUIDv7 via `symfony/uid`'s
  `UuidV7` + a custom Doctrine ID generator or `#[ORM\Column(type:
  UuidType::NAME)]` with generation in the constructor —
  follow whichever pattern Task 2's verification confirms for Doctrine
  ORM ^3.6), `email` (`varchar(180)`, stored trimmed + `mb_strtolower`ed —
  normalize in a setter, not at the call site, so no caller can bypass it),
  `password_hash` (`varchar(255)`, non-null), `role` (`varchar(32)`, backed
  by `UserRole`), `status` (`varchar(32)`, backed by `UserStatus`),
  `email_verified_at`, `password_changed_at`, `last_login_at` (all
  `timestamptz`, nullable), `created_at`, `updated_at` (`timestamptz`,
  non-null). Implements `UserInterface`, `PasswordAuthenticatedUserInterface`,
  and `EquatableInterface`. `getRoles()` returns `[$this->role->value]` only
  — no `ROLE_USER` literal here, that comes from `role_hierarchy` (Task 12).
  `isEqualTo()` compares `id`, `role`, `status`, `password_hash`,
  `email_verified_at` — nothing else. No setter mutates `id`.
  Verify: `php -l src/Entity/User.php`; `php bin/console
  doctrine:mapping:info` lists `App\Entity\User` without error (will only
  fully validate once the migration in Task 9 runs, but mapping-info catches
  attribute mistakes early).
  (AC-4 — hash column shape, no plaintext column; AC-5 — normalized email
  storage; AC-15 — scalar `role`.)

  **Done 2026-08-18.** `src/Entity/User.php` on `app_user` with every column
  of the architecture's table, `timestamptz` mapped as
  `datetimetz_immutable`, `role`/`status` as `enumType` columns, and
  `UNIQUE (email)` declared as `uniq_app_user_email`.
  Implements `UserInterface`, `PasswordAuthenticatedUserInterface`,
  `EquatableInterface`; `getRoles()` returns `[$this->role->value]` only;
  `isEqualTo()` compares exactly `id`, `role`, `status`, `password_hash`
  (via `hash_equals`) and `email_verified_at`; `$id` is `readonly` so nothing
  can mutate it.

  Two decisions the plan left open, resolved:
  1. **UUID generation happens in the constructor** (`new UuidV7()`), not via
     `#[ORM\CustomIdGenerator]`. Both were allowed by the task; the
     constructor wins because an entity then has its identity *before* flush,
     which `AuthEventRecorder` (Task 34) needs when it records an event about
     a user in the same unit of work.
  2. **Email normalization lives in `User::normalizeEmail()`**, a static the
     constructor and `setEmail()` both route through — so no call site can
     bypass it, as the task required. Task 24's `UserAccountService` and
     Task 11's `UserRepository` reuse the same static rather than
     re-implementing `mb_strtolower(trim(...))`.

  **Extra change this task had to make (no task owned it):** nothing
  registered symfony/uid's DBAL types — `Type::hasType('uuid')` was `false`,
  since neither DoctrineBundle nor the `symfony/uid` Flex recipe adds them.
  `config/packages/doctrine.yaml` now declares
  `dbal.types.uuid: Symfony\Bridge\Doctrine\Types\UuidType`. Without it
  every entity below fails to map.

  **Also created early:** `src/Repository/UserRepository.php` as a bare
  `ServiceEntityRepository<User>`, because `#[ORM\Entity(repositoryClass:)]`
  cannot point at a class that does not exist. Task 11 still owns giving it
  `UserLoaderInterface`.

  Verify: `php -l` clean; `doctrine:mapping:info` reports
  `[OK] App\Entity\User` (run in the `php` container — see Execution
  environment).

- [x] 6. **Create the `EmailVerificationToken` entity.**
  New file `src/Entity/EmailVerificationToken.php`, table
  `email_verification_token`: `id` (`uuid` PK), `user` (`ManyToOne` to
  `User`, `user_id uuid`, `onDelete: CASCADE`), `selector` (`varchar(24)`,
  unique), `hashedVerifier` (`char(64)`), `expiresAt` (`timestamptz`),
  `createdAt` (`timestamptz`), `consumedAt` (`timestamptz`, nullable).
  Verify: `php -l src/Entity/EmailVerificationToken.php`.
  (AC-13, AC-14 — schema for the stored single-use token.)

  **Done 2026-08-18.** `src/Entity/EmailVerificationToken.php` with every
  column and both indexes from the architecture (unique `selector`, plus
  `(user_id, consumed_at)`). `hashed_verifier` is declared
  `length: 64, options: ['fixed' => true]` so it emits `CHAR(64)` — SHA-256
  hex is always exactly that width. All fields except `consumedAt` are
  `readonly`; `consume()` uses `??=`, so replaying a request cannot move the
  timestamp and a second consumption is a no-op rather than a silent success.
  Also created `src/Repository/EmailVerificationTokenRepository.php` (bare
  `ServiceEntityRepository`) — the entity's `repositoryClass` cannot reference
  a class that does not exist; Task 26 owns its query methods.

- [x] 7. **Create the `ResetPasswordRequest` entity implementing the
  bundle's interface.**
  New file `src/Entity/ResetPasswordRequest.php`, table
  `reset_password_request`, implementing
  `SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface`
  (per Task 2's confirmed method signatures for the installed `^1.25`
  version): `user` (`ManyToOne` to `User`), `selector`, `hashedToken`,
  `requestedAt`, `expiresAt`. Use the trait the bundle ships
  (`ResetPasswordRequestTrait`) if Task 2 confirms it exists for this
  version, rather than reimplementing the interface by hand.
  Also create `src/Repository/ResetPasswordRequestRepository.php` extending
  `ServiceEntityRepository` and implementing
  `ResetPasswordRequestRepositoryInterface` (the bundle's contract for
  `createResetPasswordRequest`, `getUserIdentifier`, `persist`, `remove`,
  `removeExpiredResetPasswordRequests`).
  Verify: `php -l` on both files.
  (AC-9, AC-10, AC-11, AC-12 — schema and repository the bundle needs.)

  **Done 2026-08-18.** Both bundle traits exist in v1.25.0 and both are used,
  as the task preferred:
  `SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait`
  supplies `selector`, `hashedToken`, `requestedAt`, `expiresAt` plus the
  entire read side of `ResetPasswordRequestInterface`
  (`getRequestedAt`, `isExpired`, `getExpiresAt`, `getHashedToken`), and
  `…\Persistence\Repository\ResetPasswordRequestRepositoryTrait` supplies
  six of the seven repository methods. Only `createResetPasswordRequest()` is
  hand-written, because it alone has to know our constructor.
  `src/Entity/ResetPasswordRequest.php` adds the UUIDv7 `id` and the
  `ManyToOne` to `User` (`ON DELETE CASCADE`); everything else comes from the
  trait.

  **Recorded deviation from the architecture's column table:** the trait's own
  mapping is `#[ORM\Column(type: Types::DATETIME_IMMUTABLE)]` for both
  `requested_at` and `expires_at`, i.e. `TIMESTAMP WITHOUT TIME ZONE`, not the
  `timestamptz` the architecture specifies for its own tables. That mapping
  lives in vendor code and cannot be overridden without abandoning the trait
  and reimplementing the interface by hand — which the task explicitly told us
  not to do. It is safe: the bundle writes and compares these values through
  `Clock::get()->now()` and `getTimestamp()`, so a single consistent zone is
  all it requires. Only these two columns differ; `app_user`,
  `email_verification_token` and `auth_event` are all `timestamptz`.

- [x] 8. **Create the `AuthEvent` entity.**
  New file `src/Entity/AuthEvent.php`, table `auth_event`: `id` (`uuid`
  PK), `occurredAt` (`timestamptz`), `type` (`varchar(64)`), `outcome`
  (`varchar(16)`), `user` (`ManyToOne` to `User`, nullable,
  `onDelete: SET NULL`), `identifierAttempted` (`varchar(180)`, nullable),
  `ip` (Doctrine `string` mapped to Postgres `inet` via a custom DBAL type
  or plain `varchar(45)` if Task 2 finds no first-class `inet` type
  available — record which choice was made and why in a code comment),
  `userAgent` (`varchar(255)`), `context` (`jsonb`, `array<string,scalar>`).
  No setters beyond construction — this entity is write-once.
  Verify: `php -l src/Entity/AuthEvent.php`.
  (AC-24 — the audit schema.)

  **Done 2026-08-18.** `src/Entity/AuthEvent.php` with the architecture's
  columns and all three composite indexes (`(user_id, occurred_at)`,
  `(type, occurred_at)`, `(ip, occurred_at)`). Genuinely write-once: every
  property is `readonly` and the class has no setter at all, so no reachable
  code path can edit an audit row. `user` is nullable with
  `onDelete: 'SET NULL'`, so deleting an account does not destroy its trail.
  `context` maps to `jsonb`. `userAgent` is truncated to 255 chars in the
  constructor rather than letting an over-long header throw at flush.

  **Decision on `ip`, which the task asked to be recorded: a custom DBAL
  type.** `src/Doctrine/Type/InetType.php` (~50 lines) maps PostgreSQL's
  native `inet` to a PHP string and is registered as
  `doctrine.dbal.types.inet`. Rejected alternatives: `varchar(45)` would drop
  PostgreSQL's own address validation and the network operators S6's audit
  reports will want; a `columnDefinition` override would keep the column
  outside Doctrine's schema model and leave permanent
  `doctrine:schema:validate` noise. With a real type the column round-trips
  and diffs cleanly. The rationale is repeated as a class docblock, per the
  task's instruction to record the choice in a code comment.
  `src/Repository/AuthEventRepository.php` created for the same
  `repositoryClass` reason as above; Task 34 owns its behavior.

  Verify (Tasks 6-8 together): `php -l` clean on all six files;
  `doctrine:mapping:info` reports `[OK]` for all four entities —
  `AuthEvent`, `EmailVerificationToken`, `ResetPasswordRequest`, `User`.

- [x] 9. **Generate and hand-finish the auth-foundation migration.**
  Run `php bin/console make:migration` to get a scaffold from the five
  entities mapped so far, then hand-edit the generated
  `migrations/Version*.php` to add what Doctrine DBAL cannot express:
  `ALTER TABLE app_user ADD CONSTRAINT app_user_email_lower_ck CHECK (email
  = lower(email))`; `ALTER TABLE app_user ADD CONSTRAINT app_user_role_ck
  CHECK (role IN ('ROLE_SUPER_ADMIN','ROLE_TRAINER','ROLE_COACH',
  'ROLE_PLAYER'))`; `ALTER TABLE app_user ADD CONSTRAINT app_user_status_ck
  CHECK (status IN ('ACTIVE','DEACTIVATED'))`; the `UNIQUE (email)` index
  (Doctrine's `#[ORM\Column(unique: true)]` on `email` in Task 5 should
  already generate this — confirm it appears in the diff, add it by hand
  only if missing); indexes `(user_id, consumed_at)` on
  `email_verification_token`, `(user_id, occurred_at DESC)`,
  `(type, occurred_at DESC)`, `(ip, occurred_at DESC)` on `auth_event`.
  Write the `down()` method as the exact reverse (drop tables/constraints
  in reverse order of `up()`). Confirm no `INSERT` statement of any kind
  appears anywhere in the migration.
  Verify: `php bin/console doctrine:migrations:migrate --no-interaction`
  then `php bin/console doctrine:schema:validate --skip-sync` (mapping
  matches; the CHECK constraints are invisible to this command by design,
  confirm them separately with `\d+ app_user` via `php bin/console
  dbal:run-sql "SELECT conname FROM pg_constraint WHERE conrelid =
  'app_user'::regclass"`); then `php bin/console
  doctrine:migrations:migrate prev --no-interaction` and back `up` again to
  prove the down-migration is clean; `grep -i insert
  migrations/Version*.php` returns nothing.
  (AC-4, AC-5, AC-15 — the database enforces what the entities declare;
  AC-25 — proves no account row is created here.)

  **Done 2026-08-18.** `migrations/Version20260818151509.php`.

  **Deviation from the task text:** `make:migration` is unavailable —
  `symfony/maker-bundle` is not installed and is not on the S1 dependency list
  from Task 1. Used `doctrine:migrations:diff` (doctrine-migrations-bundle,
  already installed), which produces the same scaffold. The class keeps the
  standard `VersionYYYYMMDDHHMMSS` name rather than the architecture's
  sketched `Version…AuthFoundation`, because Doctrine derives ordering from
  that name; the intent is carried by `getDescription()` and the class
  docblock instead.

  Hand-finished as specified. The `UNIQUE (email)` index was already in the
  diff as `uniq_app_user_email` (from Task 5's `#[ORM\UniqueConstraint]`), so
  it was confirmed rather than added. All three CHECK constraints were written
  by hand and are live in the database — verified with
  `SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid
  = 'app_user'::regclass`:
  `app_user_email_lower_ck CHECK (email = lower(email))`,
  `app_user_role_ck CHECK (role IN (…four roles…))`,
  `app_user_status_ck CHECK (status IN ('ACTIVE','DEACTIVATED'))`.
  `down()` is the exact reverse in reverse order (FKs, dependent tables, CHECK
  constraints, `app_user` last).

  **A fix this task had to make:** `doctrine:schema:validate` failed against
  the freshly migrated schema with `ALTER TABLE auth_event ALTER ip TYPE
  INET` — introspection read the `inet` column back as an unknown DB type, so
  Doctrine saw permanent drift against a schema that was in fact correct.
  `config/packages/doctrine.yaml` now also declares
  `dbal.mapping_types.inet: inet`. Registering a custom type (Task 8) is not
  enough on its own; the *reverse* mapping is what keeps introspection honest.

  Verify results, all green:
  - `doctrine:migrations:migrate` — 1 migration, 19 queries, OK.
  - `doctrine:schema:validate` — mapping correct **and** database in sync, on
    both the `app` and `app_test` databases.
  - `doctrine:migrations:migrate prev` — down to version 0, leaving only
    `doctrine_migration_versions` in `public`; then back up clean, proving the
    down-migration reverses fully.
  - `doctrine:migrations:diff` afterwards — `No changes detected in your
    mapping information`, i.e. the migration and the mapping agree exactly.
  - `grep -i insert` matches only the docblock sentence stating that no INSERT
    exists; there is no `INSERT` statement (AC-25).

  **`app_test` created here** (`doctrine:database:create` + `migrate` under
  `APP_ENV=test`), which unblocks every DB-touching test from Task 10 onward.

  **Deferred, deliberately: `messenger_messages`.** The architecture's
  migration plan lists it in this migration, but it cannot be diffed yet —
  `config/packages/messenger.yaml` still has only `sync://`, so no Doctrine
  transport is registered and the schema listener contributes no table. Since
  `MESSENGER_TRANSPORT_DSN` carries `auto_setup=0` (Task 2's note), the table
  will not appear by itself either. **Task 29 must generate a second migration
  for it** after it configures the `async` transport. Flagged rather than
  fixed here, because configuring that transport is Task 29's scope and doing
  it now would break the plan's dependency order.

- [x] 10. **Build test data builders for the four roles × status ×
  verification combinations.**
  New file `tests/Support/UserFactory.php` (a plain object-mother-style
  builder, not Foundry — Foundry is not in `composer.json` and this plan
  does not add it for four fixed combinations): static methods
  `activeVerified(UserRole $role): User`,
  `activeUnverified(UserRole $role): User`, `deactivated(UserRole $role):
  User`, each producing a persist-ready `User` with a real Argon2id hash of
  a fixed known test password (`'a-valid-test-password-12'`, ≥12 chars) so
  functional tests can sign in as any of them. Update
  `src/DataFixtures/AppFixtures.php` only if phase 4 needs a fixture-loaded
  fixed dataset for manual/browser verification (optional — do not block
  this task on it; if skipped, note that decision in the task's commit
  message).
  Verify: `php -l tests/Support/UserFactory.php`; a throwaway unit test
  `tests/Support/UserFactoryTest.php` asserting `activeVerified()` returns
  a `User` with `status === UserStatus::ACTIVE` and non-null
  `emailVerifiedAt`, run via `php bin/phpunit tests/Support/UserFactoryTest.php`.
  (Enables the test tasks from here on for AC-1, AC-2, AC-3, AC-16, AC-17 —
  cited here as infrastructure, not as a standalone AC delivery.)

  **Done 2026-08-18.** `tests/Support/UserFactory.php` with
  `activeVerified()`, `activeUnverified()`, `deactivated()`, each taking a
  `UserRole` and an optional explicit email, plus `passwordHash()` and a
  sequence-backed `email()` so several persisted users never collide on
  `UNIQUE (email)`. Password is `'a-valid-test-password-12'` as specified,
  hashed with real Argon2id (confirmed available: `PASSWORD_ARGON2ID` is
  defined and `sodium` loaded, on host and in the container).

  Two judgement calls worth recording:
  - **The hash is computed once per process** and memoized in a static.
    Argon2id is deliberately expensive; hashing per constructed user would add
    seconds to every functional test file for no coverage.
  - **`deactivated()` returns a *verified* user.** Otherwise a "deactivated
    account cannot sign in" test would pass even if the deactivation check
    were missing entirely, because the unverified check would refuse it first.

  `src/DataFixtures/AppFixtures.php` was left untouched — the optional half of
  this task. Nothing in phase 4 so far needs a fixture-loaded dataset;
  functional tests build their own users through the factory. If Task 38's
  manual browser pass wants a fixed dataset, that is the point to add it.

  Verify: `php -l` clean; `tests/Support/UserFactoryTest.php` goes beyond the
  throwaway test the task asked for and covers all three builders across all
  four roles, the Argon2id hash actually verifying against the known
  plaintext, and email uniqueness/normalization — **14 tests, 44 assertions,
  green**.

- [x] 11. **`UserRepository` as the entity provider's user loader.**
  New file `src/Repository/UserRepository.php` extending
  `ServiceEntityRepository`, implementing `UserLoaderInterface` and
  `PasswordUpgraderInterface`. `loadUserByIdentifier(string $identifier):
  ?UserInterface` normalizes the input (`trim` + `mb_strtolower`) before
  querying `WHERE email = :normalized`, so the lookup itself never needs a
  `LOWER()` scan. `upgradePassword()` persists a rehashed hash when the
  hasher reports one is needed.
  New file `tests/Repository/UserRepositoryTest.php` (Doctrine
  integration test against the `_test` database): a user stored as
  `ann@x.com` is found via `loadUserByIdentifier('Ann@x.com ')` (mixed
  case, trailing space) — proves the edge case table's normalization
  requirement end to end, not just at the entity's setter.
  Verify: `php bin/phpunit tests/Repository/UserRepositoryTest.php`.
  (AC-5 — case-insensitive matching at the query boundary; AC-1, AC-3 —
  this is the lookup every sign-in attempt goes through.)

  **Done 2026-08-18.** `src/Repository/UserRepository.php` (created bare in
  Task 5, given its behavior here) now implements `UserLoaderInterface` and
  `PasswordUpgraderInterface`.

  **Correction to the task text:** `UserLoaderInterface` is **not** in
  `Symfony\Component\Security\Core\User` — that namespace has no such
  interface in Symfony 8.1, and referencing it produced
  `Error: Interface "…\Core\User\UserLoaderInterface" not found` at
  container compile time. The correct FQCN is
  `Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface`, which is
  consistent with Task 2's finding that the `entity` provider ships in
  doctrine-bridge.

  `loadUserByIdentifier()` normalizes through `User::normalizeEmail()` (the
  same static the entity uses, not a re-implementation) and then does a plain
  equality lookup, so it uses `uniq_app_user_email`. A `LOWER(email) = :x`
  predicate would have been correct too but cannot use that index and would
  make every sign-in a sequential scan; relying on input normalization is safe
  precisely because `app_user_email_lower_ck` makes an unnormalized row
  impossible. `upgradePassword()` preserves the existing
  `password_changed_at` — a rehash under new hasher parameters is not a
  password change, and overwriting it would corrupt the timestamp the reset
  flow reasons about.

  `tests/Repository/UserRepositoryTest.php` is a real integration test against
  `app_test`, isolated by a per-test transaction rolled back in `tearDown()`
  (doctrine-test-bundle is not a project dependency; this is the same
  isolation by hand). It covers the spec's normalization edge case through a
  data provider — `ann@x.com`, `Ann@x.com`, `Ann@x.com ` (trailing space),
  `  ANN@X.COM  ` — plus unknown-account null, normalization on persist, and
  the password upgrade round-trip. **7 tests, 17 assertions, green.**
  Note it must be run in the container (`docker compose exec -T php php
  bin/phpunit …`) — the host PHP has no `pdo_pgsql`.

- [x] 12. **Rewrite `config/packages/security.yaml` for the real firewall.**
  Replace the Flex default wholesale, using only keys Task 2 confirmed:
  `password_hashers: { Symfony\Component\Security\Core\User\
  PasswordAuthenticatedUserInterface: 'auto' }` (keep the existing
  `when@test` cost-reduction block); `providers.app_user_provider: { entity:
  { class: App\Entity\User, property: email } }` — actually route
  identifier resolution through `UserRepository::loadUserByIdentifier()` by
  registering it as the provider (per Task 2's confirmed `entity` provider
  syntax: either `property: email` or a repository-method provider,
  whichever Task 2 confirms is compatible with normalization happening
  inside the repository); `firewalls.main`: `lazy: true`, `provider:
  app_user_provider`, `user_checker: App\Security\AccountStatusChecker`
  (class from Task 14 — this task references it; Task 14 creates it —
  wiring here will not pass a container lint until Task 14 exists, so land
  Tasks 12 and 14 in the same commit if the lint must stay green
  continuously, or accept a temporarily red `lint:container` between them
  and fix by the end of Task 14), `form_login: { enable_csrf: true,
  username_parameter: _username, password_parameter: _password, check_path:
  app_login, default_target_path: app_home, always_use_default_target_path:
  false, failure_handler: App\Security\UniformAuthenticationFailureHandler }`
  (failure handler from Task 16 — same landing note applies), `logout: {
  path: app_logout, invalidate_session: true, enable_csrf: true }`,
  `login_throttling: { limiter: App\Security\LoginRateLimiter }` (from
  Task 22 — same landing note). Add `role_hierarchy`: each of
  `ROLE_SUPER_ADMIN`, `ROLE_TRAINER`, `ROLE_COACH`, `ROLE_PLAYER` maps to
  `[ROLE_USER]` only — no cross-role inheritance. Add `access_control`, in
  order: `{ path: ^/(login|logout)$, roles: PUBLIC_ACCESS }`,
  `{ path: ^/reset-password, roles: PUBLIC_ACCESS }`, `{ path:
  ^/verify-email, roles: PUBLIC_ACCESS }`, `{ path: ^/(css|js|images)/,
  roles: PUBLIC_ACCESS }`, `{ path: ^/favicon.ico, roles: PUBLIC_ACCESS }`,
  then the catch-all `{ path: ^/, roles: ROLE_USER }` last.
  Verify: `php bin/console lint:yaml config/packages/security.yaml`; `php
  bin/console lint:container` (expected to fully pass only once Tasks 14,
  16, 22 land — re-run at the end of Task 22 and treat that as this task's
  true completion gate for the container check specifically).
  (AC-1, AC-3 — one firewall, all four roles; AC-6 — logout config; AC-7,
  AC-8 — session pieces this file doesn't directly own but the firewall
  enables; AC-16, AC-17, AC-18 — `role_hierarchy` and `access_control`;
  AC-21 — `enable_csrf` on both `form_login` and `logout`.)

  **Done 2026-08-18.** `config/packages/security.yaml` rewritten; the
  `when@test` cost-reduction block was kept verbatim, and the `dev` firewall
  kept as-is.

  **Resolved the alternative the task left open: the entity provider is
  declared with NO `property` key.** Task 2 confirmed that is exactly the mode
  in which `EntityUserProvider` delegates to
  `UserRepository::loadUserByIdentifier()`. `property: email` would do the
  opposite — compare the raw typed identifier against the stored column, so
  `Ann@X.com` would not match the stored `ann@x.com` and AC-5 would fail at
  the firewall even though the repository handles it correctly.

  **Sequencing deviation, deliberate.** The task anticipated a red
  `lint:container` between Tasks 12 and 22 because it wires three
  not-yet-existing classes. Instead:
  - `user_checker: App\Security\AccountStatusChecker` is wired here, with
    Task 14's class landed alongside it — the option the task itself
    preferred.
  - `form_login.failure_handler` and `login_throttling.limiter` are **not**
    written here. Task 16 and Task 22 each add their own line when they create
    the class it names. The end state is identical to what this task
    specifies, but the container stays lintable and the whole test suite stays
    runnable at every intermediate step, instead of going dark for ten tasks.
    **Tasks 16 and 22 must not skip their config line.**

  Everything else landed as written: flat `role_hierarchy` (each of the four
  roles to `[ROLE_USER]`, no cross-role inheritance), and `access_control` in
  the specified order with the `^/` catch-all last.
  Verify: `lint:yaml` OK; **`lint:container` OK already** — the forward
  references that would have broken it were the two deferred lines.
  (The `app_login`/`app_logout`/`app_home` route names it references are
  resolved at runtime, not at lint time; Task 20 creates them.)

- [x] 13. **Configure session cookie flags and the idle-timeout parameter.**
  Edit `config/packages/framework.yaml`: under `framework.session`, set
  `cookie_secure: auto`, `cookie_httponly: true`, `cookie_samesite: lax`,
  `cookie_lifetime: 0`, `gc_maxlifetime: 28800`. Add a `when@prod` block
  setting `cookie_name: __Host-SESSID` (dev/test keep the default cookie
  name, since `__Host-` requires HTTPS which local dev/test do not have).
  Add a new parameter `app.session_idle_seconds: 28800` — either in
  `config/services.yaml`'s `parameters:` block or a new
  `config/packages/app.yaml`, whichever this project's convention favors
  (there is no existing `app.yaml`; default to adding it under
  `config/services.yaml` unless Task 2's notes say otherwise).
  Verify: `php bin/console lint:yaml config/packages/framework.yaml`; `php
  bin/console debug:container --parameter=app.session_idle_seconds` prints
  `28800`.
  (AC-7 — cookie flags and the idle-seconds value that Task 18's subscriber
  reads.)

  **Done 2026-08-18.** `config/packages/framework.yaml` sets
  `cookie_secure: auto`, `cookie_httponly: true`, `cookie_samesite: lax`,
  `cookie_lifetime: 0`, `gc_maxlifetime: 28800` under `framework.session`, and
  a new `when@prod` block sets `cookie_name: __Host-SESSID` (prod-only because
  `__Host-` requires HTTPS, which dev and test do not have — elsewhere the
  browser would reject the cookie and no one could stay signed in). Task 2
  confirmed all five keys and both enum domains.
  The parameter went into `config/services.yaml`'s `parameters:` block, the
  task's stated default; Task 2's notes gave no reason to prefer a new
  `app.yaml`.
  Note `cookie_secure: auto` is a second reason Task 3 had to land first —
  behind nginx the request only *looks* secure once `X-Forwarded-Proto` is
  honoured.
  Verify: `lint:yaml` OK on both files;
  `debug:container --parameter=app.session_idle_seconds` prints `28800`.

- [x] 14. **`AccountStatusChecker` — the Active + verified gate in
  `checkPostAuth()`.**
  New file `src/Security/AccountStatusChecker.php` implementing
  `UserCheckerInterface`. `checkPreAuth()` is empty (deliberately — see
  architecture Decisions). `checkPostAuth(UserInterface $user)`: if
  `$user->getStatus() !== UserStatus::ACTIVE`, throw a distinct
  `CustomUserMessageAccountStatusException` (e.g.
  `AccountDeactivatedException` in `src/Security/Exception/`); if
  `$user->getEmailVerifiedAt() === null`, throw a different distinct
  exception (e.g. `EmailNotVerifiedException`). Both extend
  `CustomUserMessageAccountStatusException` but carry distinct class
  identity so Task 34's `AuthEventSubscriber` can tell them apart for
  logging, even though Task 16 collapses their message to the caller.
  New file `tests/Security/AccountStatusCheckerTest.php` (unit, no HTTP):
  asserts `checkPostAuth()` throws the deactivated exception for a
  deactivated user, the unverified exception for an unverified user, and
  throws nothing for an active-verified user.
  Verify: `php bin/phpunit tests/Security/AccountStatusCheckerTest.php`.
  Also wire `user_checker: App\Security\AccountStatusChecker` into
  `security.yaml` from Task 12 now if not already done there.
  (AC-1, AC-2 — the two rejectable-but-existing-account facts, kept
  distinct server-side.)

  **Done 2026-08-18.** `src/Security/AccountStatusChecker.php` plus
  `src/Security/Exception/{AccountDeactivatedException,EmailNotVerifiedException}.php`,
  both extending `CustomUserMessageAccountStatusException` with distinct class
  identity so Task 34 can tell them apart while Task 16 collapses their
  message. `checkPreAuth()` is empty, with the reason in the docblock rather
  than left as a bare stub.

  **Correction to the task text:** `UserCheckerInterface::checkPostAuth()` in
  Symfony 8.1 is
  `checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void` —
  the second parameter is required in the signature
  (`vendor/symfony/security-core/User/UserCheckerInterface.php:39`), not the
  one-argument form the task shows.

  Two additions beyond the task's three cases, both to stop the test passing
  for the wrong reason: deactivation is asserted to be reported *ahead of*
  verification when both apply (so a deactivated account never leaks the
  second fact), and a foreign user class (`InMemoryUser`) is asserted to pass
  through rather than fatal — the checker is registered firewall-wide.
  Verify: **18 tests, 9 assertions, green** (the three main cases run across
  all four roles via a data provider).

- [x] 15. **`LoginTimingPaddingSubscriber` — equalize the unknown-account
  timing cost.**
  New file `src/EventSubscriber/LoginTimingPaddingSubscriber.php`,
  subscribing to `LoginFailureEvent`. When
  `$event->getException()->getPrevious()` (or the top-level exception,
  depending on what Symfony 8.1 actually wraps here — confirm the exact
  exception shape by writing a failing assertion first and reading the
  real exception class from the test failure, not from assumption) is a
  `UserNotFoundException`, call `PasswordHasherInterface::verify()` (via
  autowired `PasswordHasherFactoryInterface`) against a constant dummy hash
  generated once at construction time with the same algorithm/cost as the
  real hasher, discarding the boolean result.
  New file `tests/Security/LoginTimingPaddingSubscriberTest.php`: asserts
  the subscriber is invoked and calls the hasher exactly once for an
  unknown-email failure (mock `PasswordHasherFactoryInterface`, assert the
  call), and does **not** call it again for an already-hashed real-account
  failure (that cost already happened in `CheckCredentialsListener`).
  Verify: `php bin/phpunit tests/Security/LoginTimingPaddingSubscriberTest.php`.
  (AC-2 — the timing half of "indistinguishable in message and observable
  timing"; a statistical cross-path timing test is Task 17's job, this
  task proves the mechanism fires.)

  **Done 2026-08-18.** `src/EventSubscriber/LoginTimingPaddingSubscriber.php`.

  **The exception shape the task told us to determine empirically, resolved
  from the installed source rather than guessed:**
  `AuthenticatorManager::handleAuthenticationFailure()`
  (`vendor/symfony/security-http/Authentication/AuthenticatorManager.php:252`)
  wraps the original in `new BadCredentialsException('Bad credentials.', 0,
  $original)` whenever `isSensitiveException()` is true, and that is true for
  `UserNotFoundException` under any `expose_security_errors` except `All`
  (line 270). The bundle's default is `None`
  (`MainConfiguration.php:65-69`), so in this app **the cause is
  `$event->getException()->getPrevious()`**. The subscriber accepts the
  top-level shape too, so the protection does not silently vanish if that
  setting is ever changed — and both shapes are covered by tests.

  Worth noting for Task 34: our own `AccountDeactivatedException` and
  `EmailNotVerifiedException` are **not** wrapped, because line 274 exempts
  `CustomUserMessageAccountStatusException`. They arrive at the top level.

  The dummy hash is built once at construction from the *configured* hasher,
  so it tracks the real algorithm and cost automatically, including the
  reduced `when@test` cost — the padding stays honest without slowing the
  suite. A test pins that construction-time behavior, because hashing per
  failure would make the padded path the slow one and just flip the sign of
  the signal.
  Verify: **5 tests, 5 assertions, green** — wrapped unknown account pads,
  unwrapped unknown account pads, known-account failure does not pad again
  (that cost already happened in `CheckCredentialsListener`), subscription
  registered, hash computed once.

  Note the test was rewritten once to use `createStub()` for the
  collaborators that carry no expectations: PHPUnit 13 emits a notice for
  mocks without expectations, and `phpunit.dist.xml` sets
  `failOnNotice="true"`.

- [x] 16. **`UniformAuthenticationFailureHandler` — one message, every
  path.**
  New file `src/Security/UniformAuthenticationFailureHandler.php`
  implementing `AuthenticationFailureHandlerInterface`.
  `onAuthenticationFailure()` ignores the exception's concrete type and
  always redirects to `app_login` with one fixed flash message (e.g.
  "Invalid email or password.") and the same HTTP status/redirect shape
  regardless of whether the cause was wrong password, unknown email,
  deactivated, or unverified.
  New file `tests/Security/UniformAuthenticationFailureHandlerTest.php`
  (unit): construct the handler with a stub request/exception for each of
  the four causes, assert identical flash message text and identical
  response shape (status code, redirect target) for all four.
  Verify: `php bin/phpunit tests/Security/UniformAuthenticationFailureHandlerTest.php`.
  Wire `failure_handler` into `security.yaml`'s `form_login` block from
  Task 12 now if not already done there.
  (AC-2 — the message half.)

  **Done 2026-08-18.** `src/Security/UniformAuthenticationFailureHandler.php`
  returns a 303 redirect to `app_login` with the single flash
  `'Invalid email or password.'` (exposed as `FAILURE_MESSAGE` so tests assert
  against the constant, not a copy of the string). The exception's concrete
  type is never consulted — not narrowed, not mapped, not logged here — since
  any branch on it is a channel for learning which accounts exist. It also
  deliberately does **not** store the exception in the session the way
  Symfony's default handler does, which would let the login template render a
  cause-specific message and undo the whole thing.

  **`failure_handler` is now wired into `security.yaml`'s `form_login`
  block**, closing the first of the two lines Task 12 deferred.
  `lint:yaml` and `lint:container` both green.

  `tests/Security/UniformAuthenticationFailureHandlerTest.php` goes past
  asserting each cause against a fixed expectation: it collects the response
  signature (status, redirect target, flash bag) for all four causes and
  asserts `array_unique` over them has exactly **one** element. A change that
  made one cause differ would have to be replicated four times to escape the
  test. A third test asserts the message text contains none of "deactivated",
  "verif", "not found", "unknown", "no such".
  Verify: **6 tests, 23 assertions, green.**

- [x] 17. **Sign-in matrix functional test.**
  New file `tests/Functional/SignInTest.php`, using `UserFactory` (Task 10)
  to seed one user per case: correct password (expect redirect to the
  role's dashboard per Task 20 — acceptable to assert only "not the login
  page" here if Task 20 hasn't landed yet, then extend once it has), wrong
  password, unknown email, deactivated account, unverified account. Assert
  the four failure cases render byte-identical flash message text and
  identical HTTP status. Add a statistical timing assertion: run each of
  the four failure cases N times (e.g. 30), record wall-clock duration per
  attempt, and assert no case's mean differs from the others' by more than
  a documented tolerance (this is the empirical proof the architecture's
  Risks section calls for — do not settle for an equal-message assertion
  alone). Also assert all four roles from `UserFactory` can authenticate
  through the same `/login` route with no role-specific path (AC-3).
  Verify: `php bin/phpunit tests/Functional/SignInTest.php` — run it
  several times in CI-like conditions (`--repeat 3` or a loop) since it is
  a timing test and flakiness must be triaged, not ignored, per AGENTS.md's
  Failure Handling steps.
  (AC-1, AC-2, AC-3 — end-to-end proof of the whole gate.)

  **Done 2026-08-18.** `tests/Functional/SignInTest.php` — **10 tests, 29
  assertions, green**, and green on repeated runs.

  **Gap in the plan, filled here: no task owned the `/login` route.** Tasks 12
  and 16 both reference `app_login`, Task 37 does the form *theme*, but
  nothing created the controller or template, so this test could not run at
  all. Added `src/Controller/SecurityController.php` (`app_login` GET|POST,
  `app_logout` POST as the route the firewall intercepts) and
  `templates/security/login.html.twig`. Note the controller deliberately does
  **not** use `AuthenticationUtils::getLastAuthenticationError()`, the Symfony
  default: that helper surfaces the exception's own message, and the four
  causes have different messages, so rendering it would quietly undo Task 16.
  The uniform message reaches the page as a flash instead. Task 37 restyles
  this template; it must not reintroduce `getLastAuthenticationError()`.

  **Three defects this task's tests caught, all real:**

  1. **Kernel reboot broke fixture visibility.** `WebTestCase` reboots the
     kernel between requests, so each request got a *fresh* Doctrine
     connection that could not see the uncommitted fixture rows. Every sign-in
     failed as "unknown account" — and, worse, the four failure assertions
     still passed, because all four causes had collapsed into the same one.
     Fixed with `$client->disableReboot()`, with the reason recorded in the
     test. **Every functional test that seeds data must do the same** (Task 20
     already does).
  2. **The timing test caught a genuine 40x signal**, exactly the thing it
     exists to catch: unknown email returned in 5.4 ms against 218 ms for a
     wrong password. Root cause was in the fixtures, not the subscriber —
     `UserFactory` hashed at full production strength while the app's
     `when@test` hasher is configured cheap (`time_cost: 3`,
     `memory_cost: 10`), so verifying a real account cost ~40x what the
     padding could ever cost. Fixed by giving `UserFactory` the same reduced
     parameters, with a comment on both sides tying them together. This is a
     test-only artifact: in production both paths use `auto` at full strength
     and already matched. After the fix the ratio is within tolerance.
  3. `default_target_path: app_home` needed Task 20's route to exist; the
     success case failed with `Unable to generate a URL for the named route
     "app_home"` until Task 20 landed.

  **Deviation from the task's tolerance instruction, deliberate:** medians
  rather than means, and a **3x** bound rather than a small percentage. This
  runs on shared hardware where scheduling noise dwarfs the effect; a tight
  bound produces flakes that get muted rather than triaged, which is worse
  than no test. What the bound must catch is the padding being *removed* — an
  order-of-magnitude gap — and it demonstrably does: the pre-fix state failed
  it at 40.6x. The reasoning is in the test's docblock so a future reader does
  not "tighten" it back into flakiness.

  Also asserted beyond the task text: that no failure cause produces an
  authenticated token (so "uniform response" cannot be satisfied by uniformly
  *succeeding*), and the four causes compared against each other via
  `array_unique` over their response signatures rather than each against a
  fixed expectation.

- [ ] 18. **`SessionIdleSubscriber` — deterministic 8-hour inactivity
  expiry.**
  New file `src/EventSubscriber/SessionIdleSubscriber.php`, subscribing to
  `kernel.request` (only on the main request, only for authenticated
  sessions). On each request it reads a `_last_activity` session value; if
  `now - _last_activity > %app.session_idle_seconds%`, invalidates the
  session (forcing re-authentication) rather than letting the request
  proceed as authenticated; otherwise updates `_last_activity` to now.
  New file `tests/Functional/SessionIdleExpiryTest.php`: sign in, manually
  age the session's `_last_activity` past the threshold (directly
  manipulate the test session storage, not `sleep()` for 8 hours), issue a
  request, assert the response is unauthenticated (redirected to login /
  401, matching whatever the entry point does for this firewall).
  Verify: `php bin/phpunit tests/Functional/SessionIdleExpiryTest.php`.
  (AC-7 — the deterministic half of inactivity expiry, on top of
  `gc_maxlifetime`'s probabilistic backstop from Task 13.)

- [ ] 19. **Logout replay and sign-in session-regeneration test.**
  New file `tests/Functional/LogoutAndSessionRegenerationTest.php`: (a)
  sign in, capture the session cookie, POST to `/logout` with a valid CSRF
  token, then replay the pre-logout session cookie on a protected route —
  assert unauthenticated (AC-6); (b) capture the session cookie **before**
  sign-in, sign in, assert the session identifier used afterward differs
  from the pre-sign-in one (AC-8's sign-in half — `form_login`'s built-in
  behavior, this task is the proof, not new production code).
  Verify: `php bin/phpunit tests/Functional/LogoutAndSessionRegenerationTest.php`.
  (AC-6, AC-8.)

- [x] 20. **`RoleLandingResolver`, `HomeController`, and the four dashboard
  controllers.**
  New file `src/Security/RoleLandingResolver.php`: one method
  `routeFor(UserRole $role): string` returning `admin_dashboard`,
  `trainer_dashboard`, `coach_dashboard`, or `player_dashboard`. New files
  `src/Controller/HomeController.php` (`#[Route('/', name: 'app_home')]`,
  `#[IsGranted('ROLE_USER')]`, delegates to `RoleLandingResolver` and
  redirects — no business logic beyond that), and four thin controllers
  under `src/Controller/Dashboard/`: `AdminDashboardController`
  (`#[Route('/admin', name: 'admin_dashboard')]`,
  `#[IsGranted('ROLE_SUPER_ADMIN')]`), `TrainerDashboardController`
  (`/trainer`, `ROLE_TRAINER`), `CoachDashboardController` (`/coach`,
  `ROLE_COACH`), `PlayerDashboardController` (`/player`, `ROLE_PLAYER`) —
  each renders a minimal stub template (Task 37 makes them accessible).
  New file `tests/Functional/RoleLandingTest.php`: sign in as each of the
  four `UserFactory` roles, assert redirect from `/` lands on that role's
  dashboard; separately assert a `ROLE_PLAYER` user requesting `/admin`
  gets refused (403) even though no navigation link to it exists anywhere
  in the rendered player dashboard (AC-17's "absence of a link is not
  enforcement" — assert both the refusal and the link's absence in the
  same test, not two separate tests that could each pass for the wrong
  reason).
  Verify: `php bin/phpunit tests/Functional/RoleLandingTest.php`; `php
  bin/console debug:router | grep -E 'app_home|admin_dashboard|trainer_dashboard|coach_dashboard|player_dashboard'`
  shows all five routes.
  (AC-16, AC-17.)

  **Done 2026-08-18.** `src/Security/RoleLandingResolver.php` (a `match` that
  is total over `UserRole`, so a fifth role becomes a visible gap here rather
  than a silent fall-through), `src/Controller/HomeController.php`, and the
  four controllers under `src/Controller/Dashboard/` with minimal templates
  under `templates/dashboard/`. Each dashboard template carries the CSRF-token
  logout form, so Task 19 has something to exercise.
  `debug:router` shows all five routes: `app_home`, `admin_dashboard`,
  `trainer_dashboard`, `coach_dashboard`, `player_dashboard`.

  `tests/Functional/RoleLandingTest.php` — **17 tests, 50 assertions, green.**
  The AC-17 case is generated as **all twelve** role x foreign-dashboard
  pairs rather than the single `ROLE_PLAYER -> /admin` case the task names, so
  no combination is left to chance. Each pair asserts, in one test as the task
  required, both that the role's own dashboard renders no link to the foreign
  path *and* that requesting that path directly returns 403 — the failure
  message spells out that a passing link-absence check with a failing refusal
  means the missing link was the only thing stopping them. Added beyond the
  task: an anonymous request to `/` redirects to `/login`, which is the
  catch-all `access_control` rule seen from the other side.

- [ ] 21. **Router-sweep test for default-deny (AC-18).**
  New file `tests/Functional/RouterSweepTest.php`: read every registered
  route from the router (`php bin/console debug:router --format=json` via
  a shelled call, or autowire the `RouterInterface` directly in the test
  and iterate `getRouteCollection()`), exclude framework-internal routes
  (`_profiler`, `_wdt`, anything under `dev`/`test` firewalls), and for
  every remaining route assert it is either matched by the public
  `access_control` allow-list from Task 12 or answers 302 (redirect to
  login) or 403 to an anonymous request — never 200 and never 500. This
  test is what the architecture explicitly says "holds AC-18 over time",
  not the YAML by itself, so keep it running every future route through
  the same loop rather than hand-listing routes.
  Verify: `php bin/phpunit tests/Functional/RouterSweepTest.php`.
  (AC-18.)

- [ ] 22. **Rate limiter configuration — login, reset, and verification
  limiters.**
  New file `config/packages/rate_limiter.yaml` (keys confirmed by Task 2)
  declaring four limiters: `login_account` (`sliding_window`, 5 per 15
  minutes), `login_source` (`sliding_window`, 20 per hour),
  `password_reset_account` (`sliding_window`, 3 per hour),
  `password_reset_source` (`sliding_window`, 10 per hour) — the same
  `password_reset_*` pair is reused for verification resend, per the
  architecture (identical G-22 numbers, one pair of limiters, not four).
  Add a dedicated `cache.rate_limiter` pool (filesystem adapter, per the
  architecture's accepted single-node risk — do not silently switch to
  Redis, that is future work flagged in Risks). New file
  `src/Security/LoginRateLimiter.php` extending
  `AbstractRequestRateLimiter`, composing the `login_account` limiter keyed
  on `hash('sha256', $normalizedEmail . $appSecret)` (read `%kernel.secret%`)
  and the `login_source` limiter keyed on the client IP truncated to /24
  (IPv4) or /64 (IPv6) — implement the truncation helper as a small pure
  function with its own unit test (`tests/Security/IpTruncationTest.php` or
  inline in the limiter's test, either is fine, but it must be tested
  standalone since it is easy to get the bitmask wrong). Wire
  `login_throttling.limiter: App\Security\LoginRateLimiter` into
  `security.yaml` (already referenced in Task 12; this task is what makes
  the class exist).
  Verify: `php bin/console lint:yaml config/packages/rate_limiter.yaml`;
  `php bin/console lint:container`; `php bin/phpunit
  tests/Security/IpTruncationTest.php`.
  (AC-19 — login limiter; groundwork for AC-20 — reset/verification
  limiters declared here, consumed in Tasks 27 and 30.)

- [ ] 23. **Login throttle behavior test.**
  New file `tests/Functional/LoginThrottleTest.php`: 5 failed attempts
  against a real `UserFactory` account within the window locks the 6th
  attempt even with the **correct** password (proves the limiter runs
  before authentication, per the architecture); the same throttle applies
  to an **unknown** email at the same rate (proves throttling itself does
  not distinguish real from unknown accounts — the enumeration-resistance
  half of AC-19); a burst from a single source across many different
  emails trips the `login_source` limiter independently of any one
  account's `login_account` counter.
  Verify: `php bin/phpunit tests/Functional/LoginThrottleTest.php`.
  (AC-19.)

- [ ] 24. **`UserAccountService` — creation, normalization, unique-violation
  mapping.**
  New file `src/Service/UserAccountService.php`: `create(string $email,
  string $plainPassword, UserRole $role): User` — normalizes email, hashes
  the password via the autowired hasher, persists inside one
  `EntityManager::wrapInTransaction()` call, catches
  `Doctrine\DBAL\Exception\UniqueConstraintViolationException` and rethrows
  a new `App\Service\Exception\EmailAlreadyInUseException` **without**
  touching the `EntityManager` again afterward (per the architecture's note
  that the manager is closed after that violation — this constraint must
  be a code comment at the catch site, not just documented here, so the
  next editor does not reopen the bug).
  New file `tests/Service/UserAccountServiceConcurrentCreationTest.php`:
  attempt to create two users with the same (differently-cased) email
  concurrently or sequentially against the same unique constraint; assert
  exactly one succeeds, the other raises
  `EmailAlreadyInUseException` (a caught, typed exception — never an
  uncaught `500`), and the service instance remains usable for a
  subsequent unrelated `create()` call in the same test (proves the
  manager-closed pitfall was actually avoided, not just documented).
  Verify: `php bin/phpunit tests/Service/UserAccountServiceConcurrentCreationTest.php`.
  (AC-5 — the concurrent-registration edge case, "exactly one succeeds ...
  not a 500".)

- [ ] 25. **Password policy: `NotBlocklistedPassword` constraint + offline
  list.**
  New file `src/Validator/Constraints/NotBlocklistedPassword.php` (the
  constraint) and `NotBlocklistedPasswordValidator.php` (the validator,
  loading a bundled offline top-100k common-password list — add the list
  as a plain-text asset under e.g. `src/Resources/security/common-
  passwords.txt` or `assets/security/` per whatever asset convention this
  project already has for non-Twig static data; if none exists, place it
  under `src/Resources/` and load it via `%kernel.project_dir%`). Apply
  `Length(min: 12, max: 4096, countUnits: COUNT_BYTES)`,
  `NotCompromisedPassword`, and `NotBlocklistedPassword` together on the
  plain-password field of `ChangePasswordFormType` (built in Task 31) and
  reuse the same constraint group on `UserAccountService::create()`'s
  input via a small DTO if the console command (Task 36) also needs
  validation — confirm the DTO shape when Task 36 is reached rather than
  guessing its fields now.
  New file `tests/Validator/PasswordPolicyTest.php`: an 11-character
  password fails, a 12-character password with a non-ASCII multi-byte
  character passes and is not truncated (assert byte length is what was
  validated, not character length), a >4096-byte password fails, a
  known-blocklisted password fails even when `NotCompromisedPassword` is
  mocked to fail-open (simulates an HIBP outage per the architecture's
  Risk — the offline list must still catch it).
  Verify: `php bin/phpunit tests/Validator/PasswordPolicyTest.php`.
  (AC-4 — "never silently truncated" and the byte-limit edge case; the
  no-composition-rules / blocklist policy itself has no dedicated AC number
  but is the mechanism AC-4's safe-storage guarantee depends on.)

- [ ] 26. **`EmailVerificationTokenService` and `EmailVerificationService`.**
  New file `src/Service/EmailVerificationTokenService.php`: `issue(User
  $user): string` — `random_bytes(9)` base64url-encoded selector (24
  chars), `random_bytes(32)` verifier, store `hash('sha256', $verifier)`
  and `expiresAt = now + 24h` after first invalidating (deleting or
  marking consumed) the user's outstanding tokens, return
  `$selector . $verifier`. `consume(string $token): User` — split on the
  fixed selector length, `SELECT ... FOR UPDATE` the row by selector
  (via a repository method using a pessimistic lock — confirm the exact
  Doctrine ORM ^3.6 API for `LockMode::PESSIMISTIC_WRITE` in Task 2's
  verification pass or at this task's start if not already covered),
  `hash_equals()` the stored hash against `hash('sha256', $verifier)`,
  reject (throw a typed exception) if `consumedAt` is set or `expiresAt`
  has passed, otherwise set `consumedAt` and return the user. New file
  `src/Service/EmailVerificationService.php`: `resend(string $emailInput):
  void` — normalize, consume the `password_reset_account` /
  `password_reset_source` limiters from Task 22 (shared pair, per
  architecture), look up the user, if found and not yet verified call
  `EmailVerificationTokenService::issue()` and dispatch the verification
  mail message (Task 29's `SendEmailMessage`) — **do not** reveal via any
  return value or exception whether the address was found (AC-11-shaped
  behavior, by analogy); `consume(string $token): void` — delegates to
  `EmailVerificationTokenService::consume()`, and treats "user is already
  verified" as success (idempotent — no second write, no error) even if
  the specific token was already consumed for that same already-verified
  user, per the edge case table.
  Verify: `php -l` on both files (functional coverage is Task 28).
  (AC-13, AC-14 — the mechanism itself.)

- [ ] 27. **`EmailVerificationController`, `ResendVerificationFormType`,
  and templates.**
  New file `src/Controller/EmailVerificationController.php`:
  `#[Route('/verify-email/{token}', name: 'app_verify_email')]` (GET,
  `PUBLIC_ACCESS` via Task 12's `access_control`) calls
  `EmailVerificationService::consume()`, renders
  `templates/verify_email/result.html.twig` with a success or
  refused-with-reason state; `#[Route('/verify-email/resend', name:
  'app_verify_email_resend')]` (GET renders the form, POST via a Symfony
  Form submits it) calls `EmailVerificationService::resend()` and always
  renders the same `templates/verify_email/resend.html.twig`
  "check your email" confirmation regardless of whether the address was
  found — mirroring `check-email` from the reset flow (AC-11-shaped, by
  analogy, for AC-13/AC-20's public endpoint). New file
  `src/Form/ResendVerificationFormType.php` (one `email` field,
  `NotBlank`, `Email` constraints). New templates:
  `templates/verify_email/resend.html.twig`,
  `templates/verify_email/result.html.twig` (skeleton content — Task 37
  makes them accessible; do not gold-plate styling here).
  Verify: `php bin/console lint:twig
  templates/verify_email`; `php bin/console debug:router | grep
  verify_email` shows both routes; `php bin/console lint:container`.
  (AC-13, AC-14 — the live producer that exercises the mechanism end to
  end per the spec's S1 boundary; AC-20 — the resend endpoint is
  rate-limited; AC-22, AC-23 — templates exist for Task 37/38 to finish.)

- [ ] 28. **Verification mechanism test: single-use, expiry, idempotent,
  concurrent consume.**
  New file `tests/Functional/EmailVerificationFlowTest.php`: resend issues
  a token, consuming it once marks the user verified and returns success;
  consuming the **same** token a second time is refused (single-use,
  server-side, not just client-side hidden); a token consumed after 24h+1
  minute (manipulate `expiresAt` directly in the test, not `sleep()`) is
  refused; visiting an already-verified user's (already-consumed or a
  fresh) verification link again reports success and does not change
  `emailVerifiedAt` a second time (idempotent re-verification edge case —
  assert the timestamp is unchanged across both requests, not just that
  no error was thrown); two concurrent consume attempts on the same token
  (simulate via two entity managers or two service instances racing
  against the same row) result in exactly one success, proving the `FOR
  UPDATE` lock actually serializes them rather than both reading
  `consumedAt = null` and both succeeding.
  Verify: `php bin/phpunit tests/Functional/EmailVerificationFlowTest.php`.
  (AC-13, AC-14 — this is the security-critical single-use test called out
  explicitly in the phase-3 brief, as its own task, not folded into a
  general test sweep.)

- [ ] 29. **Messenger transport, `SendEmailMessage`, templated emails.**
  Edit the Flex-generated `config/packages/messenger.yaml` (keys confirmed
  in Task 2) to route a new `App\Message\SendEmailMessage` to an `async`
  Doctrine transport with `retry_strategy: { max_retries: 3, delay: 5000,
  multiplier: 3 }` and a `failed` transport; enable
  `DispatchAfterCurrentBusMiddleware` if not already default. New files
  `src/Message/SendEmailMessage.php` (a small serializable DTO — template
  name, `to`, and a typed context array, no raw `Email`/`TemplatedEmail`
  object, which is not reliably serializable across transports) and
  `src/MessageHandler/SendEmailMessageHandler.php` (`#[AsMessageHandler]`,
  builds a `TemplatedEmail` from the DTO and sends via `MailerInterface`).
  New templates `templates/emails/reset_password.html.twig` and
  `templates/emails/verify_email.html.twig`, each with a plain-text
  alternative block. Add a `messenger` Monolog channel entry to
  `config/packages/monolog.yaml` (Flex-generated in Task 1). Set
  `MAILER_DSN=null://null` as the `.env` default (dev/test — no real
  transport needed for the test suite; this is not a secret).
  New file `tests/Functional/QueuedMailDoesNotBlockResponseTest.php`:
  dispatch a `SendEmailMessage` through a deliberately failing transport
  (or assert the message lands in the `async` transport's table without
  requiring a worker to run) and assert the calling controller's HTTP
  response is unaffected — proves the "user-facing response cannot change
  when SMTP is down" property directly, not by inference.
  Verify: `php bin/console lint:yaml config/packages/messenger.yaml`; `php
  bin/console debug:messenger` lists `SendEmailMessage` routed to `async`;
  `php bin/phpunit tests/Functional/QueuedMailDoesNotBlockResponseTest.php`.
  (AC-9, AC-13 — mail dispatch for both flows; the delivery-failure edge
  case, which is part of AC-11's "response is unchanged" guarantee.)

- [ ] 30. **`PasswordResetService` over `reset-password-bundle`.**
  Edit/confirm the Flex-generated `config/packages/reset_password.yaml`
  (keys from Task 2): `request_password_repository:
  App\Repository\ResetPasswordRequestRepository`, `lifetime: 3600`,
  `throttle_limit: 0` (the bundle's own throttle disabled — G-22's numbers
  live only in `symfony/rate-limiter` from Task 22, per the architecture's
  Decisions table). New file `src/Service/PasswordResetService.php`:
  `request(string $emailInput): void` — normalize, consume the
  `password_reset_account`/`password_reset_source` limiters (an exhausted
  **account** limiter must still proceed to render the generic
  check-email outcome from the controller, never a 429; an exhausted
  **source** limiter may let the controller return 429 — implement this
  distinction as a typed exception the controller can branch on, e.g.
  `SourceRateLimitExceededException` vs. silently proceeding for the
  account case), look up the user, if found call
  `ResetPasswordRequestRepository::removeRequests($user)` **before**
  `ResetPasswordHelper::generateResetToken()` (this ordering, not the
  bundle's default behavior, is what makes "most recently issued token
  valid, earlier ones refused" true — the architecture is explicit that
  the bundle does not do this for you), then dispatch
  `SendEmailMessage` for the reset template. `complete(string $token,
  string $plainPassword): void` — one transaction:
  `ResetPasswordHelper::validateTokenAndFetchUser()`,
  `removeResetRequest()`, set the new hash, set `passwordChangedAt =
  now()`, `removeRequests($user)` to invalidate siblings.
  Verify: `php -l src/Service/PasswordResetService.php` (functional
  coverage is Task 32).
  (AC-9, AC-10, AC-11 — request-side mechanics; AC-12 — completion
  invalidates siblings and, via `EquatableInterface` +
  `passwordChangedAt`, every other live session; AC-8 — the
  password-change half of session-id regeneration, completed by the
  controller in Task 31 calling `$session->invalidate()`.)

- [ ] 31. **`ResetPasswordController`, forms, templates.**
  New file `src/Controller/ResetPasswordController.php`:
  `#[Route('/reset-password', name: 'app_forgot_password_request')]`
  (`PUBLIC_ACCESS`) renders `ResetPasswordRequestFormType`, on submit calls
  `PasswordResetService::request()` and **always** renders
  `templates/reset_password/check_email.html.twig` regardless of whether
  the address was found (branch only on the source-limiter exception from
  Task 30 to return 429 instead); `#[Route('/reset-password/reset/{token}',
  name: 'app_reset_password')]` (`PUBLIC_ACCESS`) renders
  `ChangePasswordFormType`, on submit calls `PasswordResetService::
  complete()`, then explicitly calls `$session->invalidate()` (per the
  architecture — this single call both discards any pre-existing session
  for whoever is currently viewing the link and satisfies AC-8's
  regeneration-on-password-change) and redirects to `/login`. New files
  `src/Form/ResetPasswordRequestFormType.php` (one `email` field) and
  `src/Form/ChangePasswordFormType.php` (`RepeatedType` wrapping a
  `PasswordType` with the constraints from Task 25). New templates:
  `templates/reset_password/request.html.twig`,
  `templates/reset_password/check_email.html.twig`,
  `templates/reset_password/reset.html.twig`.
  Verify: `php bin/console lint:twig templates/reset_password`; `php
  bin/console debug:router | grep reset_password`; `php bin/console
  lint:container`.
  (AC-9, AC-10, AC-11, AC-12 — the live end-to-end flow; AC-22, AC-23 —
  templates exist for Task 37/38.)

- [ ] 32. **Reset flow test: uniform response, expiry, sibling
  invalidation, cross-session discard, AC-12 session invalidation.**
  New file `tests/Functional/PasswordResetFlowTest.php`, one scenario per
  method (not one giant test): (a) requesting a reset for a registered vs.
  an unregistered address renders byte-identical `check_email` output
  (AC-11); (b) a token older than 1 hour is refused (AC-10); (c) using a
  valid token once succeeds, using the same token again is refused
  (AC-10's "refused on second use even within the hour"); (d) requesting
  reset twice and opening both links — the earlier token is refused, only
  the latest succeeds (sibling-invalidation edge case); (e) opening a
  reset link while authenticated as a **different** user — assert the
  pre-existing different-user session is gone after the request and the
  password change was applied to the **token's** subject, never the
  session's (the cross-session edge case, as its own explicit assertion,
  not inferred from (a)–(d)); (f) — the AC-12 test, standalone and
  explicit per the phase-3 brief's requirement: sign in as the same user
  from two separate test clients (two sessions), complete a reset from a
  third context, then assert **both** of the original sessions are now
  unauthenticated on their next request, and assert any other outstanding
  reset token for that account (from a second, uncompleted request) is
  also now refused.
  Verify: `php bin/phpunit tests/Functional/PasswordResetFlowTest.php`.
  (AC-9, AC-10, AC-11, AC-12 — with (f) specifically satisfying the
  phase-3 brief's call for a dedicated, standalone AC-12 test.)

- [ ] 33. **Reset/verification rate-limit test — account exhaustion never
  429s, source exhaustion may.**
  New file `tests/Functional/ResetAndVerificationThrottleTest.php`:
  4 reset requests for the **same** registered account within an hour —
  the 4th still renders the generic `check_email` page (200), never a 429
  (AC-11 would otherwise leak that the account exists via status code, not
  just body); 11 reset requests from the **same source** across many
  different (possibly unregistered) email addresses within an hour — the
  11th may return 429, since a source limiter carries no per-account
  signal; repeat both shapes for the verification-resend endpoint at its
  3/hour and 10/hour thresholds. This is the AC-19/AC-20 "does not itself
  enumerate" property, proven explicitly rather than assumed from the
  login test in Task 23, since the account/source-429 asymmetry is a
  separate mechanism the architecture calls out by name.
  Verify: `php bin/phpunit tests/Functional/ResetAndVerificationThrottleTest.php`.
  (AC-20; reinforces AC-11 and AC-19's enumeration-resistance property in
  the reset/verification context specifically.)

- [ ] 34. **`AuthEventRecorder`, `AuthEventRecord` DTO,
  `AuthEventSubscriber`.**
  New file `src/Service/AuthEventRecord.php`: a `readonly` DTO whose
  constructor accepts only `type` (an `AuthEventType` enum:
  `LOGIN_SUCCEEDED`, `LOGIN_FAILED`, `LOGGED_OUT`,
  `PASSWORD_RESET_REQUESTED`, `PASSWORD_RESET_COMPLETED`,
  `EMAIL_VERIFIED`, `SUPER_ADMIN_BOOTSTRAPPED`), `outcome`, `userId`
  (nullable), `identifierAttempted` (nullable), `ip`, `userAgent`,
  `context` (`array<string,scalar>`) — no constructor parameter capable of
  holding a `Request`, a password, or a raw token, by construction, not by
  convention. New file `src/Service/AuthEventRecorder.php`:
  `record(AuthEventRecord $record): void` persists an `AuthEvent` and
  flushes in its **own** transaction/`EntityManager` scope, independent of
  whatever business transaction is in flight (a failed sign-in has no
  other transaction to piggyback on; a rolled-back reset must still leave
  its "requested" trace). New file
  `src/EventSubscriber/AuthEventSubscriber.php`: subscribes to
  `LoginSuccessEvent`, `LoginFailureEvent`, `LogoutEvent`, builds the
  appropriate `AuthEventRecord` (using `AccountStatusChecker`'s Task 14
  exception classes to distinguish `LOGIN_FAILED` outcomes when
  present) and calls the recorder. Wire equivalent calls directly into
  `PasswordResetService` (Task 30 — `PASSWORD_RESET_REQUESTED`,
  `PASSWORD_RESET_COMPLETED`), `EmailVerificationService` (Task 26 —
  `EMAIL_VERIFIED`), and `CreateSuperAdminCommand` (Task 36 —
  `SUPER_ADMIN_BOOTSTRAPPED`) by injecting `AuthEventRecorder` into each.
  Verify: `php -l` on all new files; `php bin/console lint:container`.
  (AC-24 — the recording mechanism and its wiring across every listed
  event type.)

- [ ] 35. **Audit logging test — content and secret-exclusion.**
  New file `tests/Service/AuthEventRecorderTest.php`: a reflection-based
  or static-analysis-style assertion that `AuthEventRecord`'s constructor
  has no parameter whose name or type could plausibly carry a password or
  raw token (guards against a future edit silently reintroducing one) —
  and a positive test that recording an event actually persists a row
  with the expected `type`/`outcome`/`userId`/`ip` values. New file
  `tests/Functional/AuthEventsRecordedTest.php`: sign in successfully,
  sign in with a wrong password, log out — assert three `auth_event` rows
  exist with the correct types, and assert none of their `context` JSON
  or any other column contains the literal test password string or any
  substring of the session/reset/verification token used in the test
  (grep the persisted row's serialized form for the known secret values).
  Verify: `php bin/phpunit tests/Service/AuthEventRecorderTest.php
  tests/Functional/AuthEventsRecordedTest.php`.
  (AC-24 — "contain no password or token material" proven by direct
  inspection of persisted rows, not by code review alone.)

- [ ] 36. **`CreateSuperAdminCommand` and its console test.**
  New file `src/Command/CreateSuperAdminCommand.php`,
  `#[AsCommand(name: 'app:create-super-admin')]`: interactive mode uses
  `SymfonyStyle` to prompt for email and a hidden, confirmed password;
  non-interactive mode falls back to `SUPER_ADMIN_EMAIL` /
  `SUPER_ADMIN_PASSWORD` read from the real process environment (`$_SERVER`
  / `getenv()`, **never** from a value defaulted in a tracked `.env` file —
  add no default for either variable to any committed file). Applies the
  same password constraints as Task 25 (reuse the same `Validator`
  constraints, do not hand-roll a second length check). Delegates account
  creation to `UserAccountService::create()` with `UserRole::
  ROLE_SUPER_ADMIN`, then **explicitly sets `emailVerifiedAt = now()`** on
  the created user before the final flush (the one sanctioned exception to
  "verification precedes sign-in", confined to this command) and calls
  `AuthEventRecorder::record()` with `SUPER_ADMIN_BOOTSTRAPPED`. If a
  Super Admin already exists, requires an interactive confirmation prompt
  or a `--force` flag when run non-interactively before proceeding (the
  command doubles as the lost-Super-Admin recovery path). Returns exit
  code `0` on success, `1` on a caught business failure (e.g. invalid
  password, `EmailAlreadyInUseException`), `2` on an unexpected/unhandled
  condition. Never echoes the plaintext password back to the console.
  New file `tests/Console/CreateSuperAdminCommandTest.php` using
  `CommandTester`: interactive mode with valid prompted input creates a
  verified `ROLE_SUPER_ADMIN` user and exits `0`; non-interactive mode
  reading `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD` from a test-injected
  environment does the same; running it again without `--force` when a
  Super Admin already exists and refusing the confirmation prompt exits
  non-zero without creating a second account; the created user's row has
  a non-null `emailVerifiedAt` immediately, with no verification email
  having been dispatched for it (assert nothing landed on the `async`
  transport from this command).
  Verify: `php bin/phpunit tests/Console/CreateSuperAdminCommandTest.php`;
  `grep -rn "SUPER_ADMIN_PASSWORD" .env` confirms no default value is set
  there (the variable, if present at all in `.env`, must be unset/empty,
  documented as "set via real environment or `.env.local`").
  (AC-25.)

- [ ] 37. **Accessible form theme, base template, stylesheet.**
  New file `templates/form/theme.html.twig` extending
  `form_div_layout.html.twig`, overriding the field-row block(s) so every
  widget emits `aria-invalid="true"` when it has errors and
  `aria-describedby` pointing at that field's error node id; register it
  as a global form theme in `config/packages/twig.yaml`
  (`twig.form_themes: ['form/theme.html.twig']`). New file
  `templates/base.html.twig`: one `<h1>` slot per page, a `<meta
  name="viewport" content="width=device-width, initial-scale=1">`, a
  single stylesheet link to `public/css/app.css` (new file) providing:
  visible `:focus-visible` outline at ≥3:1 contrast against its
  background, 4.5:1 body text contrast, a single-column layout that holds
  at 320px width, and ≥44×44 CSS px interactive targets with ≥8px
  spacing. Update every template from Tasks 20, 27, 31 (login form,
  reset request/check-email/reset, verify-email resend/result, four
  dashboard stubs) to extend `base.html.twig`, include a `role="alert"`
  error summary at the top of each form linking to the offending fields
  (id-anchored `<a href="#field-id">`), use `<label for>` on every
  control (never a placeholder standing in as a label), and set
  `autocomplete="email"` / `"current-password"` / `"new-password"` and
  `type="email" inputmode="email"` on the relevant inputs. The login form
  itself is written as plain semantic HTML (not a Symfony `FormType`),
  per the architecture, since `form_login` reads `_username`/`_password`/
  `_csrf_token` directly from the raw request — still apply every
  accessibility requirement above to that hand-written markup.
  Verify: `php bin/console lint:twig templates`; `php bin/console
  lint:yaml config/packages/twig.yaml`.
  (AC-22, AC-23 — made structural via the shared theme and base template,
  per the architecture's explicit intent that no individual page template
  can forget these.)

- [ ] 38. **Accessibility and mobile-viewport verification pass.**
  Since no JS build tooling is installed in this project (confirmed in
  Task 1's `N/A` report if still true), verification here is a documented
  manual/automated pass rather than an `npm run` command: for each of the
  three named screens (sign-in, password reset — both request and
  completion forms, email verification — both resend and result screens),
  record in a short section appended to this plan
  (`## Accessibility verification notes (Task 38)`) the outcome of: (a) a
  full keyboard-only walkthrough (tab order reaches every control, submit
  works with no mouse), (b) a screen-reader spot-check of the error-summary
  announcement when a form is submitted invalid (screen reader of choice —
  note which one was used), (c) a contrast check of body text and
  interactive-element focus outline against the actual rendered
  `public/css/app.css` values (compute the ratios by hand or with any
  available contrast-checking tool and record the numbers, not just
  "passed"), (d) rendering each screen at a 320px-wide viewport and
  confirming no horizontal scroll and no control smaller than 44×44px. If
  browser automation tooling becomes available in this environment before
  this task executes, prefer an automated Axe/Lighthouse pass instead and
  record its output; do not block this task waiting for tooling that
  isn't there.
  Verify: the notes section exists with all four checks recorded per
  screen, each with a concrete pass/fail and number, not a bare
  checkmark.
  (AC-22, AC-23 — direct verification, since Task 37 only builds the
  structure that is supposed to satisfy them.)

- [ ] 39. **Full CSRF-rejection sweep across every state-changing route.**
  New file `tests/Functional/CsrfProtectionTest.php`, using the exact
  cookie/field mechanics Task 2 confirmed for `stateless_token_ids`: for
  each of `/login` (POST), `/logout` (POST), `/reset-password` (POST),
  `/reset-password/reset/{token}` (POST), `/verify-email/resend` (POST),
  submit once with the CSRF token stripped and once with it altered to an
  invalid value; assert every one of the ten resulting requests is refused
  (the framework's stateless CSRF rejection — a 403 or the form
  re-rendering with a validation error, whichever Task 2's findings say is
  the actual behavior for this configuration) and that none of them
  perform the underlying action (no session logged out, no password
  changed, no token consumed) as a side effect of the rejected request.
  Verify: `php bin/phpunit tests/Functional/CsrfProtectionTest.php`.
  (AC-21 — proven across every state-changing route named in the spec,
  not asserted only for login.)

---

## Coverage table (every AC-1…AC-25 claimed)

| AC | Claimed by task(s) | Note |
|---|---|---|
| AC-1 | 12, 14, 17 | Firewall + post-auth gate + end-to-end sign-in matrix. |
| AC-2 | 14, 15, 16, 17 | Distinct server-side exceptions; timing padding; uniform message; statistical proof. |
| AC-3 | 12, 17 | One firewall, all four roles, proven in the sign-in matrix. |
| AC-4 | 5, 9, 12, 25 | Hash column/no plaintext; DB constraints; `password_hashers: auto`; byte-limit/no-truncation policy. |
| AC-5 | 5, 9, 11, 24 | Normalized storage; DB `UNIQUE`+`CHECK`; case-insensitive lookup; concurrent-create mapping. |
| AC-6 | 12, 19 | `logout` config; replay-refused test. |
| AC-7 | 3, 13, 18 | Trusted proxies (correct `cookie_secure: auto`); cookie flags + idle parameter; deterministic idle subscriber + test. |
| AC-8 | 12, 19, 30, 32 | Sign-in regeneration (built-in, proven in 19); password-change regeneration (`$session->invalidate()` in 31, service in 30, proven in 32). |
| AC-9 | 29, 30, 31, 32 | Mail dispatch; request-side service; controller; end-to-end test. |
| AC-10 | 30, 32 | 1-hour lifetime + second-use refusal; proven in flow test. |
| AC-11 | 29, 30, 31, 32, 33 | Uniform check-email response; delivery-failure independence; proven in flow and throttle tests. |
| AC-12 | 30, 32 | Sibling/session invalidation via `EquatableInterface`; dedicated standalone test (32f) per phase-3 brief. |
| AC-13 | 26, 27, 28 | **S1 boundary, not a gap:** mechanism + public resend producer + single-use/expiry tests, fully built and tested; no account-creation trigger exists in S1 by design (self-registration is S3; bootstrap sets verification directly, AC-25) — the account-creation trigger is claimed by S2/S3, not by this plan. |
| AC-14 | 26, 27, 28 | 24-hour lifetime + replacement via resend; proven in flow test. |
| AC-15 | 4, 5, 9 | Enum; scalar entity column; DB `CHECK` makes a second role unrepresentable. Profile contract is frozen in the architecture, not built in S1 (Out of scope) — no task ships a profile table. |
| AC-16 | 20 | Resolver + four dashboards + landing test. |
| AC-17 | 20 | Server-side refusal proven together with navigation absence, in one test. |
| AC-18 | 12, 21 | Allow-list + catch-all; router-sweep test that holds it over time. |
| AC-19 | 3, 22, 23 | Trusted proxies (correct source IP); limiter config + class; throttle-behavior test including "correct password after limit" and unknown-account parity. |
| AC-20 | 22, 27, 30, 33 | Shared reset/verification limiter pair declared; consumed by both services; dedicated account-vs-source 429 test. |
| AC-21 | 2, 12, 39 | Config verified against installed sources before forms exist; `enable_csrf` wired; full route sweep proving rejection. |
| AC-22 | 27, 31, 37, 38 | Templates built with the accessible form theme; direct verification pass with recorded numbers. |
| AC-23 | 27, 31, 37, 38 | Same templates, mobile-viewport requirements; direct verification pass. |
| AC-24 | 8, 9, 34, 35 | Schema; migration; recorder/DTO/subscriber wiring; content and secret-exclusion tests. |
| AC-25 | 9, 36 | No `INSERT` in migration history (proven in 9); command creates+verifies+audits the bootstrap account, both modes tested. |

**No criterion is unclaimed.** AC-13 is the one criterion the architecture
flags as having no in-slice account-creation *trigger*; it is still fully
**built and tested** in S1 (Tasks 26–28) via the public resend endpoint, per
the spec's recorded S1 boundary — this is a decided scope line, not a task
that fell off the plan.

---

## Definition of Done

Before any task is checked `[x]`, its own verification command(s) must pass.
Before the whole plan is considered done, run the **Standard** tier of
`.claude/DOD.md` in addition to every task's own commands:

- `composer validate --strict`
- `php -l` on all changed PHP files (or rely on each task's own `php -l`/
  `phpunit` runs, which exercise this transitively)
- `php bin/phpunit` (full suite — not just the file introduced by the last
  task, to catch cross-task regressions)
- `php bin/console lint:container`
- `php bin/console lint:yaml config`
- `php bin/console lint:twig templates`
- `php bin/console debug:router` (spot-check the full route list matches
  what Tasks 20, 27, 31 declared)
- `php bin/console doctrine:migrations:diff --check-database-platform`
  (expect no pending diff once Task 9's migration is applied and every
  later entity change has its own migration)
- `php bin/console doctrine:schema:validate --skip-sync`
- Any formatting/static-analysis tool actually configured in this repo
  (report `N/A - tooling not configured` if none is found — do not install
  one without explicit user approval, per AGENTS.md)

Before merge/PR (Full tier), additionally:

- `composer audit` (the S1 dependency additions from Task 1 are new attack
  surface worth auditing explicitly)
- Confirm `messenger:consume` deployment is documented somewhere reachable
  by ops, per the architecture's Risks section on "a worker nobody runs" —
  this plan does not own deployment docs, but merge should not proceed
  silently past that gap.
- Confirm the S2 handoff note from the architecture's AC-13 risk ("account
  creation must call `issue()`") is recorded somewhere S2's own spec pass
  will read — not silently dropped between slices.

---

## Config verification notes (Task 2)

*Verified 2026-08-18 against the **installed** `vendor/` tree (Symfony v8.1.4,
`symfonycasts/reset-password-bundle` v1.25.0, PHP 8.5.9). Every key below was
read from the cited file; nothing here comes from memory. There is no
`security-1.0.xsd` in this installation — SecurityBundle ships only PHP
configuration classes, so those are the authority.*

### `security.firewalls.<name>.form_login`

Options come from two places: `FormLoginFactory::__construct()` and
`AbstractFactory::addConfiguration()`.

`vendor/symfony/security-bundle/DependencyInjection/Security/Factory/FormLoginFactory.php:31-37`

| Key | Default |
|---|---|
| `username_parameter` | `_username` |
| `password_parameter` | `_password` |
| `csrf_parameter` | `_csrf_token` |
| `csrf_token_id` | `authenticate` |
| `enable_csrf` | `false` |
| `post_only` | `true` |
| `form_only` | `false` |

`vendor/symfony/security-bundle/DependencyInjection/Security/Factory/AbstractFactory.php:50-68`
adds `provider`, `remember_me` (default `true`), `success_handler`,
`failure_handler`, plus every key of `$defaultSuccessHandlerOptions`
(lines 31-37: `always_use_default_target_path` `false`, `default_target_path`
`/`, `login_path` `/login`, `target_path_parameter` `_target_path`,
`use_referer` `false`) and `$defaultFailureHandlerOptions` (lines 39-44:
`failure_path` `null`, `failure_forward` `false`, `login_path` `/login`,
`failure_path_parameter` `_failure_path`).

**Confirmed:** `check_path` and `login_path` are valid; a custom
`failure_handler` service id is a first-class key (Task 16 needs no
compiler-pass trickery).

### `security.firewalls.<name>.login_throttling`

`vendor/symfony/security-bundle/DependencyInjection/Security/Factory/LoginThrottlingFactory.php`

| Key | Default |
|---|---|
| `limiter` | *(service id implementing `RequestRateLimiterInterface`)* |
| `max_attempts` | `5` |
| `interval` | `1 minute` |
| `lock_factory` | `null` |
| `cache_pool` | `cache.rate_limiter` |
| `storage_service` | `null` |

Task 22 may therefore either configure `max_attempts`/`interval` inline **or**
point `limiter` at a custom service — both are supported keys.

### `security.firewalls.<name>` (firewall level)

`vendor/symfony/security-bundle/DependencyInjection/MainConfiguration.php:185-215`:
`pattern`, `host`, `methods`, `security` (`true`), `user_checker`
(`security.user_checker`), `request_matcher`, `access_denied_url`,
`access_denied_handler`, `entry_point`, `provider`, `stateless` (`false`),
`lazy` (`false`), `context`. Also `switch_user` and `required_badges`
(lines 268-300).

**Confirmed:** `user_checker` is a firewall-level scalar — Task 14's
`AccountStatusChecker` wires in by service id, no decoration needed.

### `security.firewalls.<name>.logout`

`MainConfiguration.php:214-266`

| Key | Default |
|---|---|
| `enable_csrf` | `null` |
| `csrf_token_id` | `logout` |
| `csrf_parameter` | `_csrf_token` |
| `csrf_token_manager` | *(unset)* |
| `path` | `/logout` |
| `target` | `/` |
| `invalidate_session` | `true` |
| `clear_site_data` | enum: `*`, `cache`, `cookies`, `storage`, `clientHints`, `executionContexts`, `prefetchCache`, `prerenderCache` |
| `delete_cookies` | per-cookie `path`/`domain`/`secure`/`samesite`/`partitioned` |

Note the `beforeNormalization` at lines 217-228: setting `csrf_token_manager`
implies `enable_csrf: true`, and setting `enable_csrf: true` implies
`csrf_token_manager: security.csrf.token_manager`. Task 12 sets
`enable_csrf: true` and nothing else.

### `security.access_control`

`MainConfiguration.php:126-166`: `request_matcher`, `requires_channel`, `path`
(**urldecoded** format), `host`, `port`, `ips`, `attributes`, `route`,
`methods`, `allow_if`, `roles`. Task 20/21's default-deny entries use
`path` + `roles`; `requires_channel: https` is available if S1 wants it.

### `security.role_hierarchy`

`MainConfiguration.php:110-124`: map of role id => list of roles; a
comma-separated string is normalized to a list (line 118).

### `security.password_hashers`

`MainConfiguration.php:392-436`: keyed by class, value either the string
`auto` or a map of `algorithm`, `migrate_from`, `hash_algorithm` (`sha512`),
`key_length` (`40`), `ignore_case`, `encode_as_base64`, `iterations` (`5000`),
`cost` (min 4, max 31), `memory_cost`, `time_cost`, `id`.

### Entity user provider (`security.providers.<name>.entity`)

`vendor/symfony/doctrine-bridge/DependencyInjection/Security/UserProvider/EntityFactory.php`

| Key | Default |
|---|---|
| `class` | **required** |
| `property` | `null` |
| `manager_name` | `null` |

**Confirmed for Task 11:** leaving `property` unset is exactly the mode in
which `EntityUserProvider` delegates to the repository's
`UserLoaderInterface::loadUserByIdentifier()`, which is what the architecture
wants (`UserRepository` owns the lookup, so it can filter/normalize).

### CSRF: `enable_csrf` × `csrf.yaml`'s `stateless_token_ids`

`config/packages/csrf.yaml` lists `submit`, `authenticate`, `logout` — the
same three ids `form_login` (`authenticate`) and `logout` (`logout`) use by
default, so **no id needs overriding**; Tasks 12/31/37 just set
`enable_csrf: true` on both and render the default `_csrf_token` field.

How that field behaves is *not* the classic session-token flow. Per
`vendor/symfony/framework-bundle/Resources/config/security_csrf.php:53-70`,
`security.csrf.same_origin_token_manager` **decorates**
`security.csrf.token_manager` for exactly the listed ids. In
`vendor/symfony/security-csrf/SameOriginCsrfTokenManager.php`:

- `getToken('authenticate')` returns a token whose **value is the cookie
  name** (`csrf-token` by default) — line 88-94. So `csrf_token('authenticate')`
  in Twig renders the literal string `csrf-token`, not a random token. This is
  correct and expected; do not "fix" it.
- `isTokenValid()` (lines 114-190) accepts when **either** the origin check
  passes **or** a JS-set double-submit cookie/header matches; it rejects when
  both are absent ("double-submit and origin info not found", line 146).
- `isValidOrigin()` checks `Sec-Fetch-Site: same-origin`, else `Origin`, else
  `Referer` against `getSchemeAndHttpHost()`.

**Two consequences that later tasks must be written against:**

1. Origin validation depends on `getSchemeAndHttpHost()` resolving correctly
   behind nginx — this is a second, independent reason Task 3's
   `trusted_proxies`/`trusted_headers` must land before any CSRF-protected
   form is tested.
2. In functional tests, `AbstractBrowser::request()`
   (`vendor/symfony/browser-kit/AbstractBrowser.php:356-357`) sets
   `HTTP_REFERER` from history whenever history is non-empty. So a test that
   GETs the form and then submits it passes the origin check, while a bare
   `$client->request('POST', ...)` on a fresh client has no history, no
   Referer, and is **rejected**. Tasks 17, 19, 23, 28, 32, 33 must GET the page
   first; Task 39's negative sweep gets its rejection for free from a
   history-less client — and must assert the rejection is CSRF-caused, not
   merely a 4xx from something else.

### `framework.rate_limiter`

`vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:2586-2650`.
The tree is `framework.rate_limiter.limiters.<name>` (a bare map directly
under `rate_limiter` is normalized into `limiters`, lines 2589-2602).

| Key | Default / values |
|---|---|
| `policy` | **required**, enum: `fixed_window`, `token_bucket`, `sliding_window`, `compound`, `no_limit` |
| `limit` | integer |
| `interval` | e.g. `15 minutes` (number + second/minute/hour/day/week/month) |
| `rate.interval`, `rate.amount` | token_bucket only, `amount` default `1` |
| `cache_pool` | `cache.rate_limiter` |
| `lock_factory` | `auto` |
| `storage_service` | `null` |
| `limiters` | child limiter names, `compound` policy only |
| `anchor_at` | aligns `fixed_window` to a calendar |

**Correction for Task 22:** `AbstractRequestRateLimiter`
(`vendor/symfony/http-foundation/RateLimiter/AbstractRequestRateLimiter.php`)
has **no constructor** — it is abstract over one method,
`protected function getLimiters(Request $request): array`, and implements
`PeekableRequestRateLimiterInterface` (`consume()`, `peek()`, `reset()`). A
custom login limiter injects its `RateLimiterFactory` instances through its
**own** constructor and returns configured limiters from `getLimiters()`.
`RequestRateLimiterInterface` itself is only `consume(Request): RateLimit` and
`reset(Request): void`.

### `framework.messenger`

`Configuration.php:1758-1810`. Per transport: `dsn`, `serializer` (`null`),
`options` (map), `failure_transport` (`null`), `retry_strategy`, `rate_limiter`
(`null`). `retry_strategy` children (lines 1790-1797): `service` (`null`),
`max_retries` (`3`), `delay` (`1000` ms), `multiplier` (`2.0`), `max_delay`
(`0` = infinite), `jitter` (`0.1`). A global `failure_transport` also exists at
the `messenger` level (line 1806). `service` is mutually exclusive with the
other four (lines 1780-1789 throw).

The Flex recipe wrote `config/packages/messenger.yaml` with `sync: 'sync://'`
and everything else commented out, and `.env` now has
`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`. Task 29 owns
uncommenting the `async`/`failed` transports and the
`Symfony\Component\Mailer\Messenger\SendEmailMessage: async` routing entry —
note `auto_setup=0` means the `messenger_messages` table must come from a
migration, which Task 9 must account for.

### `framework.session` cookie flags

`Configuration.php:769-800`: `cookie_lifetime`, `cookie_path`,
`cookie_domain`, `cookie_secure` (enum `true`/`false`/`auto`, default `auto`),
`cookie_httponly` (`true`), `cookie_samesite` (enum `null`/`lax`/`strict`/
`none`, default `lax`), `name`, `gc_maxlifetime`, `metadata_update_threshold`,
`handler_id`, `storage_factory_id`, `save_path`. All of Task 13's intended
flags exist; the secure/httponly/samesite defaults are already what S1 wants,
so Task 13 sets them explicitly for the record rather than to change behavior.

### `framework.trusted_proxies` / `trusted_headers`

`Configuration.php:130-142`. `trusted_proxies` is a `variableNode` whose
`beforeNormalization` (lines 131-134) expands the literal string
`private_ranges` (or `PRIVATE_SUBNETS`) into `IpUtils::PRIVATE_SUBNETS`; its
default is `'%env(default::SYMFONY_TRUSTED_PROXIES)%'`. `trusted_headers` is a
list with default `'%env(default::SYMFONY_TRUSTED_HEADERS)%'`; a bare string is
wrapped into a one-element list.

**Note for Task 3:** the framework already reads `SYMFONY_TRUSTED_PROXIES` by
default, and `private_ranges` expresses the Docker-bridge intent exactly
without hand-copying CIDRs. Task 3 should prefer `private_ranges` over the
literal `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` the plan sketched, and may
keep the `TRUSTED_PROXIES` env var name for explicitness.

### `symfonycasts_reset_password`

`vendor/symfonycasts/reset-password-bundle/src/DependencyInjection/Configuration.php:28-43`

| Key | Default |
|---|---|
| `request_password_repository` | **required** |
| `lifetime` | `3600` (seconds) |
| `throttle_limit` | `3600` (seconds) |
| `enable_garbage_collection` | `true` |

The recipe-generated `config/packages/reset_password.yaml` currently points
`request_password_repository` at `symfonycasts.reset_password.fake_request_repository`;
Task 30 replaces it with `App\Repository\ResetPasswordRequestRepository`.

### Keys that could NOT be confirmed

None. Every key the plan's Tasks 12, 13, 22, 29 and 30 depend on was located
in an installed source file above. The only corrections carried forward are:
`AbstractRequestRateLimiter` having no constructor (Task 22), `rate_limiter`
nesting under `limiters:` (Task 22), `trusted_proxies: private_ranges` being
available (Task 3), the CSRF token value being the cookie name and origin
validation being Referer-dependent in tests (Tasks 12, 17, 19, 23, 28, 31, 32,
33, 37, 39), and `auto_setup=0` requiring a migrated `messenger_messages`
table (Tasks 9, 29).
