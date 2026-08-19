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

- [x] 18. **`SessionIdleSubscriber` — deterministic 8-hour inactivity
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

  **Done 2026-08-19.** `src/EventSubscriber/SessionIdleSubscriber.php` reuses
  `%app.session_idle_seconds%` from Task 13 (via `#[Autowire]`) rather than
  redefining it.

  **The one decision the task text left open, resolved from the installed
  source rather than guessed: listener priority.** `Firewall::onKernelRequest`
  — which runs `ContextListener` (restores the token from the session) and
  `AccessListener` (makes the access-control decision) synchronously, back to
  back, inside one `kernel.request` dispatch — is itself registered at
  priority 8 (`vendor/symfony/security-http/Firewall.php`). Registering this
  subscriber at the default priority (i.e. after the firewall) would only
  catch the *next* request: the access decision for the current one is
  already made by the time a priority-0 listener runs, using the token that
  was still valid when the firewall read it. So the subscriber is registered
  at priority 32 — *above* the firewall — and invalidates the session before
  `ContextListener` ever reads a token out of it, satisfying the task's "not
  letting *the* request proceed as authenticated" (not just the next one).
  One consequence: at that priority `security.token_storage` is not populated
  yet for this request, so "authenticated session" is read directly off the
  session bag's `_security_main` key (the key `ContextListener` writes;
  `contextKey` defaults to the firewall name, and `security.yaml` sets no
  `context:` for `main`) rather than off `TokenStorageInterface`. Both facts
  are recorded in the class docblock so a future reader does not "simplify"
  the priority back to the default and silently reopen the one-more-request
  gap.

  `tests/Functional/SessionIdleExpiryTest.php` follows Task 17's conventions
  (`UserFactory`, `disableReboot()`, a transaction rolled back in `tearDown`).
  Beyond the task's single scenario it adds two controls that a real
  regression could otherwise slip past: a session comfortably inside the
  threshold must stay authenticated (else "always invalidate" would pass the
  main assertion for the wrong reason), and two requests each individually
  inside the window, spaced further apart than the window itself, must still
  authenticate — proving `_last_activity` rolls forward on activity rather
  than being checked only against the timestamp stamped at sign-in. Session
  aging is done by writing `_last_activity` directly into the `Session`
  instance the prior request used (`$client->getRequest()->getSession()`) and
  calling `save()` — `when@test`'s `session.storage.factory.mock_file` is
  file-backed, so this is visible to the next request exactly as real elapsed
  time would be, per the task's instruction not to `sleep()`.

  Verify: **3 tests, 18 assertions, green**
  (`docker compose exec -T -e APP_ENV=test php php bin/phpunit
  tests/Functional/SessionIdleExpiryTest.php` — the host PHP still lacks
  `pdo_pgsql`, per Task 1's environment note). The broader suite
  (`tests/Functional/`, 33 tests; the full suite, 83 tests) stayed green, so
  Tasks 1-17/20 show no regression.

- [x] 19. **Logout replay and sign-in session-regeneration test.**
  New file `tests/Functional/LogoutAndSessionRegenerationTest.php`: (a)
  sign in, capture the session cookie, POST to `/logout` with a valid CSRF
  token, then replay the pre-logout session cookie on a protected route —
  assert unauthenticated (AC-6); (b) capture the session cookie **before**
  sign-in, sign in, assert the session identifier used afterward differs
  from the pre-sign-in one (AC-8's sign-in half — `form_login`'s built-in
  behavior, this task is the proof, not new production code).
  Verify: `php bin/phpunit tests/Functional/LogoutAndSessionRegenerationTest.php`.
  (AC-6, AC-8.)

  **Done 2026-08-19.** No production code needed — confirmed both mechanisms
  are already live: Task 12's `logout: { invalidate_session: true }` and
  `form_login`'s own session migration. The CSRF token submitted for
  `/logout` is the real value rendered on Task 20's dashboard template, not
  hand-built, so the test also exercises the logout route's own CSRF gate
  rather than assuming it out of the way.

  **One thing the task assumed that does not hold in this app, resolved by
  measuring rather than guessing: capturing "the session cookie before
  sign-in" first requires *a* session to exist.** `/login`'s CSRF is
  stateless (`csrf.yaml`) and its template has no flash messages to read on a
  first visit, so `GET /login` never starts a PHP session here — confirmed
  empirically (no `Set-Cookie` header at all on that request). So part (b)
  manufactures the pre-sign-in session directly: it starts and saves the
  current (anonymous) request's own `SessionInterface` — the same
  `mock_file`-backed storage every real request uses — and hands its cookie
  to the test client's jar exactly as a `Set-Cookie` response header would
  have. That is the "value observed before authentication"; what the test
  actually checks is only what `form_login` does to it at sign-in. Part (a)
  needed no such workaround, since a real sign-in genuinely does leave a
  session cookie in the jar to capture.

  The session cookie name needed for both halves (`MOCKSESSID`) was confirmed
  against the compiled test container's `session.storage.factory.mock_file`
  arguments rather than assumed as `NativeSessionStorage`'s default
  `PHPSESSID`.

  Verify: **2 tests, 11 assertions, green**, stable across three repeated
  runs (`docker compose exec -T -e APP_ENV=test php php bin/phpunit
  tests/Functional/LogoutAndSessionRegenerationTest.php` — the host PHP
  still lacks `pdo_pgsql`, per Task 1's environment note). The broader suite
  (`tests/Functional/`, 35 tests; the full suite, 85 tests) stayed green, so
  Tasks 1-18/20 show no regression.

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

- [x] 21. **Router-sweep test for default-deny (AC-18).**
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

  **Done 2026-08-19.** `tests/Functional/RouterSweepTest.php` autowires
  `RouterInterface` (via `self::getContainer()`, public because
  `framework.test: true`) and walks `getRouteCollection()->all()` — no route
  is hand-listed. The public allow-list and the `dev` firewall's pattern are
  both parsed straight out of `config/packages/security.yaml` with the
  `Yaml` component rather than copied into the test, so the test reconciles
  against whatever Task 12's file actually says and stays correct if a
  later task edits the allow-list.

  **Judgment calls, each documented in the test itself:**
  - **Public vs. non-public split.** A route matching one of the parsed
    `PUBLIC_ACCESS` patterns is not requested at all — the assertion the
    task specifies is an OR ("matched by the allow-list, **or** answers
    302/403"), and a public route is allowed to legitimately answer 200.
    This also sidesteps `/logout` cleanly: it is POST-only and CSRF-gated,
    but it is also on the public allow-list (`^/(login|logout)$`), so it is
    skipped rather than requiring a hand-built CSRF token for a route this
    test has no need to actually call.
  - **Parameterized routes.** None exist yet in this app (Tasks 26-31 add
    `{token}` routes later) — the second bullet from the task text is
    still built in now, not deferred: `concretePath()` substitutes each
    `{param}` with the first of two generic candidates
    (`'placeholder-value'`, then `'1'`) that satisfies the route's own
    `requirement` regex, so a future numeric-only parameter still resolves
    to something that matches its route, without hand-listing routes by
    name.
  - **Non-GET methods.** `anonymousRequestMethod()` uses the route's own
    declared methods (GET when allowed, otherwise the first declared
    method) rather than assuming GET universally — moot for every
    *non-public* route today (all five are GET-only), but in place for a
    future POST-only non-public route.
  - **Framework-internal exclusion.** Route name prefix `_` (covers
    `_profiler`, `_wdt`, `_preview_error`, ...) plus a belt-and-braces path
    check against the `dev` firewall's own pattern, read from the same
    config file. This project has no `web_profiler` recipe installed, so
    neither exclusion fires today; both stay in place per the task's
    explicit instruction and so a future profiler install does not turn
    this test into a tooling-route checker.
  - Two non-emptiness assertions guard against the sweep silently checking
    nothing on either side of the split (a config-parsing regression could
    make every route look "public" or make the allow-list parse empty).

  **Verified the test is not passing vacuously**, per this task's own
  instruction to investigate before trusting a green run: temporarily
  removed the `^/, roles: ROLE_USER` catch-all from `security.yaml` — the
  test *stayed* green, because every dashboard controller and
  `HomeController` also carries its own `#[IsGranted]` attribute (the
  architecture's documented "belt and braces" — `access_control` is a path
  net, the attribute states the requirement where the code is), so
  `AccessDeniedException` still fires and the anonymous request still gets
  302. Removing *both* layers together (the catch-all **and**
  `HomeController`'s `#[IsGranted('ROLE_USER')]`) did turn `/` into a
  genuine gap, and the test correctly failed — the anonymous request hit
  `$this->getUser()` on a null user and 500'd, which the test reports
  distinctly from a 200 ("got 500" in the failure message). Both files were
  restored immediately after; `git diff` on `security.yaml` and
  `HomeController.php` is empty.

  Verify: **1 test, 7 assertions, green**
  (`docker compose exec -T -e APP_ENV=test php php bin/phpunit
  tests/Functional/RouterSweepTest.php` — the host PHP still lacks
  `pdo_pgsql`, per Task 1's environment note). Of the seven routes
  currently registered (`app_home`, `app_login`, `app_logout`,
  `admin_dashboard`, `trainer_dashboard`, `coach_dashboard`,
  `player_dashboard`), two are public (`app_login`, `app_logout`) and five
  are checked against 302/403 (all five currently answer 302, the
  unauthenticated-session-redirect shape). The broader suite
  (`tests/Functional/`, 36 tests; the full suite, 86 tests) stayed green,
  so Tasks 1-20 show no regression.

- [x] 22. **Rate limiter configuration — login, reset, and verification
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

  **Done 2026-08-19.** `config/packages/rate_limiter.yaml` declares the four
  limiters at G-22's numbers exactly as specified (`login_account` 5/15min,
  `login_source` 20/hour, `password_reset_account` 3/hour,
  `password_reset_source` 10/hour — one shared pair for reset and
  verification-resend, not four), each with an explicit `cache_pool:
  cache.rate_limiter` (redundant with the framework's own default of the
  same id, kept explicit per the task's "keys confirmed by Task 2").
  `config/packages/cache.yaml` adds that pool as a dedicated filesystem
  adapter (not an inherited `cache.app` parent), per the architecture's
  accepted single-node risk — no Redis. `src/Security/IpTruncator.php` is
  the pure truncation helper (`inet_pton`/`inet_ntop`, keep the leading 3
  bytes of 4 for IPv4 (/24), the leading 8 of 16 for IPv6 (/64), zero the
  rest — both prefixes land on a byte boundary so no partial-byte masking is
  needed), with its own `tests/Security/IpTruncationTest.php` (22 assertions:
  concrete address/result vectors for both families, same-block/
  different-block pairs, and an unparsable-input fallback).
  `src/Security/LoginRateLimiter.php` extends `AbstractRequestRateLimiter`,
  composing `limiter.login_account` (keyed on
  `hash('sha256', $normalizedEmail . $appSecret)`, using the existing
  `User::normalizeEmail()` rather than re-deriving normalization) and
  `limiter.login_source` (keyed on `IpTruncator::truncate($request-
  >getClientIp())`, used as-is and *not* separately hashed, since
  `RateLimiterFactory`/`CacheStorage` sha1-hashes every limiter id before it
  reaches the cache pool — confirmed by reading
  `vendor/symfony/rate-limiter/Storage/CacheStorage.php`). Both factories are
  bound explicitly in `config/services.yaml` (`$loginAccountLimiter: '@limiter.
  login_account'`, `$loginSourceLimiter: '@limiter.login_source'`,
  `$appSecret: '%kernel.secret%'`), since autowiring by type cannot
  distinguish two `RateLimiterFactory` arguments. `security.yaml` gains the
  `login_throttling.limiter: App\Security\LoginRateLimiter` line Task 12 left
  for this task. Confirmed the identifier this limiter keys on
  (`SecurityRequestAttributes::LAST_USERNAME`) is set by Symfony's own
  `LoginThrottlingListener` from the passport's `UserBadge` *before* this
  limiter's `getLimiters()` runs and before the password is checked — read
  `vendor/symfony/security-http/EventListener/LoginThrottlingListener.php`
  directly rather than assuming, so the "runs before authentication" claim in
  the architecture is verified, not just repeated.

  **Verify, exactly as specified:** `lint:yaml` on the new file — OK;
  `lint:container` — OK, in both the `dev` and `test` environments;
  `phpunit tests/Security/IpTruncationTest.php` — OK, 13 tests, 13
  assertions.

  **Regression found and fixed — not a new file, a `when@test` addition to
  `cache.yaml`.** Running the full suite twice back-to-back (as this task's
  own instructions require) exposed a real interaction: `SignInTest`'s
  timing-comparison case (Task 17) submits 30 failed logins per failure
  cause — 120 in one method alone — all from the same simulated client IP.
  That alone blows past `login_source`'s real 20/hour budget, and because
  the default `cache.rate_limiter` pool is a *filesystem* adapter, the
  exhausted counter persisted on disk **across separate `phpunit` process
  invocations**, not just within one run. First clean run: 99/99 green
  (`testEveryRoleSignsInThroughTheSameRoute`'s 4 successful logins happen
  first in file order, before the timing test spends the source budget).
  Second run immediately after: 25 failures, every one a correct-password
  sign-in bounced back to `/login` — the *leftover* state from run one, not
  anything wrong with run two's code. This is exactly the "single-node,
  per-node" risk the architecture's Risks section already names, just
  surfacing as cross-*run* leakage in dev/CI rather than cross-*node*
  leakage in production. Fix: `cache.yaml` gets a `when@test` block pointing
  `cache.rate_limiter` at `cache.adapter.array` instead of the filesystem
  adapter — in-memory, scoped to the container's lifetime, so every fresh
  test process starts with an empty limiter regardless of what a previous
  run did, while `dev`/`prod` keep the filesystem adapter unchanged (checked
  with `debug:config framework cache` in both environments). This does not
  touch AC-19's production guarantee and does not pre-empt Task 23 — it only
  stops one test file's already-existing attempt volume from wedging every
  other test for up to an hour. Re-verified clean: two full-suite runs back
  to back (99/99, 99/99), `tests/Functional/` alone (36/36),
  `tests/Functional/SignInTest.php` alone (10/10) — all immediately
  following each other, no gap.
  (AC-19 — login limiter now live on the real firewall, not just declared;
  groundwork for AC-20 — the shared reset/verification pair is declared and
  will be consumed by Tasks 27 and 30, not implemented here.)

- [x] 23. **Login throttle behavior test.**
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

  **Done 2026-08-19.** `tests/Functional/LoginThrottleTest.php`, 4 tests: a
  foundation test proving one real failed HTTP login consumes exactly one
  `login_account` token through the actual firewall pipeline
  (`testASingleFailedHttpLoginAttemptConsumesExactlyOneAccountToken`); the
  5-locks-6th test named by this task
  (`testFiveFailedAttemptsLockTheSixthEvenWithTheCorrectPassword`); the
  unknown-email-same-rate test
  (`testTheSameThrottleAppliesToAnUnknownEmailAtTheSameRate`); and the
  source-independence test
  (`testABurstAcrossManyEmailsFromOneSourceTripsTheSourceLimiterIndependently`).

  **Regression found — a second, distinct one from Task 22's, in the same
  `when@test` array-cache fix.** Writing this test (the first one to actually
  assert rate-limiter *counts* across several requests) surfaced that
  `cache.rate_limiter`'s test-only `cache.adapter.array` pool does not
  survive past a single request within one test method, even with
  `KernelBrowser::disableReboot()`. Root cause, confirmed by reading
  `vendor/symfony/http-kernel/Kernel.php` rather than assumed:
  `ArrayAdapter::reset()` calls `clear()` (wipes everything), every
  `cache.pool`-tagged service — `cache.rate_limiter` included, in both
  filesystem (dev/prod) and array (test) form — is auto-tagged
  `kernel.reset` by `Symfony\Component\Cache\DependencyInjection\
  CachePoolPass`, and `Kernel::boot()` runs every `kernel.reset` service's
  reset method at the start of the *second and every later* top-level
  request handled by an already-booted kernel (`!$requestStackSize &&
  $resetServices`, the latter set at the end of the previous request).
  `disableReboot()` only skips the browser's own explicit
  reboot-between-requests; it has no effect on this kernel-internal
  mechanism. In dev/prod this is invisible — `FilesystemAdapter::reset()`
  only flushes deferred writes, it does not clear the pool — so the
  regression is test-only and does not touch AC-19's production guarantee,
  exactly like Task 22's. Fix, entirely inside the test file, no config or
  production code touched: every "attempt" before the one that decides a
  test calls `LoginRateLimiter::consume()` directly (still the real
  key-hashing and sliding-window logic, just in-process, so it never
  triggers `Kernel::handle()` and therefore never triggers the reset), and
  each test's one real HTTP request is a bare `POST` with no preceding GET
  (stateless CSRF's `SameOriginCsrfTokenManager::isValidOrigin()` accepts a
  same-origin `Referer` header on its own; the `_csrf_token` value it
  expects with no cookie present is the literal cookie name, `'csrf-token'`,
  confirmed against `security/login.html.twig` and
  `isValidDoubleSubmit()`) — keeping that request the *first*
  `$client->request()` call in the method, so nothing resets before it runs.
  The foundation test proves this substitution is equivalent to a real
  failed HTTP attempt for the one thing that matters (how much
  `login_account` is decremented).

  **Test-isolation judgment calls.** Each method sets its own `REMOTE_ADDR`
  via `setServerParameter()` before touching the limiter, even though each
  test method already gets a fully fresh kernel/container (confirmed by
  reading `KernelTestCase::tearDown()`, which calls `ensureKernelShutdown()`
  unconditionally) and therefore a fresh, empty array pool regardless of
  IP — distinct IPs are belt-and-suspenders documentation, not load-bearing,
  except inside the burst test, where one shared IP across many emails is
  the entire point. The burst test's final "control" request (clean IP,
  correct password, expects success) happens to run against a *fully*
  reset pool by the time it executes (it is that test's second real HTTP
  request), not just an unburdened IP — the assertion is still a valid
  control for "the target account and password are fine, the earlier
  refusal was login_source's doing," documented as such rather than
  claimed to be IP-scoped isolation it does not actually rely on.

  **Verify, exactly as specified:** `php bin/phpunit
  tests/Functional/LoginThrottleTest.php` — OK, 4 tests, 22 assertions.
  `lint:container` — OK in both `dev` and `test`. Full suite twice
  back-to-back — 103/103, 103/103 (up from Task 22's 99, the 4 new tests).
  `tests/Functional/` alone and `LoginThrottleTest.php` alone, immediately
  after the two full runs — 40/40, 4/4. No leakage observed in any
  combination.

- [x] 24. **`UserAccountService` — creation, normalization, unique-violation
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

  **Done 2026-08-19.** `src/Service/UserAccountService.php` (email
  normalization stays entirely inside `User`'s own constructor — this
  service never duplicates it), `src/Service/Exception/EmailAlreadyInUseException.php`,
  and `tests/Service/UserAccountServiceConcurrentCreationTest.php`.

  **How the closed-EntityManager pitfall was actually solved (not just
  commented around):** confirmed against the installed `doctrine/orm 3.6.8`
  / `doctrine/dbal 4.4.4` sources (not memory) that both `UnitOfWork::commit()`
  and `EntityManager::wrapInTransaction()` independently wrap their work in
  `try { ... } finally { if (!$successful) { $this->em->close(); ... } }` —
  so any exception escaping the wrapped callback, including
  `UniqueConstraintViolationException` thrown from `executeInserts()` against
  `app_user`'s non-deferrable `UNIQUE (email)` index, leaves that
  `EntityManager` instance permanently closed, and every later
  `persist()`/`flush()`/`wrapInTransaction()` call on it throws
  `EntityManager is closed` — including for a *later, unrelated* `create()`
  call, since the container hands back the same closed singleton by default.
  The service therefore injects `Doctrine\Persistence\ManagerRegistry`
  instead of `EntityManagerInterface` directly. A private
  `openEntityManager()` asks the registry for the manager, and if
  `!$manager->isOpen()` (true exactly when a prior `create()` call's
  violation closed it) calls `ManagerRegistry::resetManager()` — Doctrine's
  own documented mechanism for this ("force the creation of a new manager if
  the current one is closed", per `AbstractManagerRegistry::resetManager()`),
  which resets the container's cached service so the *next* fetch builds a
  fresh, open `EntityManager` around the same underlying DBAL connection. The
  catch block that rethrows `EmailAlreadyInUseException` carries the explicit
  comment instructing the next editor never to touch `$entityManager` again
  there, and the recovery path lives entirely in `openEntityManager()`
  instead. `UserAccountServiceConcurrentCreationTest` proves this end to end,
  not just documents it: it creates a user, then a second `create()` call
  with the same email typed in a different case (`'  ANN@EXAMPLE.TEST  '`)
  hits the real Postgres unique-index collision and is asserted to raise
  `EmailAlreadyInUseException` (never an uncaught error), and then a *third*
  `create()` call for a completely different email is asserted to succeed on
  the same `$service` instance — which would fail with `EntityManager is
  closed` if the reset mechanism did not actually work.

  Two judgement calls worth recording:
  - **The password hash is set via `User::setPasswordHash()` after
    constructing with a placeholder, not passed into the constructor
    pre-hashed.** `hashPassword()` needs a `PasswordAuthenticatedUserInterface`
    instance to call, and constructing one, hashing against it, then
    discarding it for a second "real" constructor call would be pure waste
    since the project's single hasher is bound to the interface, not to
    `User`'s concrete class. This does set `password_changed_at` at creation
    time (via `setPasswordHash()`'s own contract) — treated here as correct,
    not a side effect to work around: it records when *this* password was
    set, distinct from `UserRepository::upgradePassword()`'s deliberate
    preservation of the old timestamp, which is specifically for a
    transparent rehash of the *same* plaintext, not a new password.
  - **The test could not fetch `UserAccountService` via
    `self::getContainer()->get(UserAccountService::class)`:** nothing in the
    app wires it in yet (no controller/command consumes it until a later
    task), so as a genuinely unreferenced private service it is compiled out
    of the container entirely — confirmed with `bin/console debug:container`,
    which showed its only usage as the weak test-locator reference that
    `RemoveUnusedDefinitionsPass` does not honor. The test instead builds it
    directly from `doctrine` and `security.user_password_hasher`, both
    real, independently-consumed services that survive compilation.

  Verify: `php -l` clean on all three files; `docker compose exec -T
  -e APP_ENV=test php php bin/phpunit tests/Service/UserAccountServiceConcurrentCreationTest.php`
  — **2 tests, 9 assertions, green**. Full suite re-run afterward:
  **105 tests, 261 assertions, green** — no regressions from Tasks 1-23.

- [x] 25. **Password policy: `NotBlocklistedPassword` constraint + offline
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

  Done 2026-08-19: built exactly the standalone mechanism this task scopes
  and nothing from Tasks 31/36 — no `ChangePasswordFormType`, no DTO, no
  `UserAccountService` wiring. New files: `src/Validator/Constraints/
  NotBlocklistedPassword.php` (constraint, message + error code), `src/
  Validator/Constraints/NotBlocklistedPasswordValidator.php` (validator;
  `#[Autowire('%kernel.project_dir%/src/Resources/security/common-
  passwords.txt')]` on the constructor's `$blocklistPath` string param, since
  autowiring can't resolve a scalar by type — confirmed live via
  `bin/console debug:container --tag=validator.constraint_validator` and
  `lint:container`, both green; matching is case-insensitive, matched
  against a lazily-loaded, request-cached `array<string,true>` set),
  `src/Resources/security/common-passwords.txt` (no existing non-Twig
  static-asset convention in this project, so placed under `src/Resources/`
  per the task's own fallback instruction), and `tests/Validator/
  PasswordPolicyTest.php`.

  **Blocklist scope decision:** the list is a curated ~3,300-entry set
  (common passwords, name/date/keyboard-walk patterns with common suffixes)
  generated from a hand-written word list, not a literal top-100k breach-
  corpus download — this environment has no network access to fetch one.
  This is documented as a pragmatic stand-in in the validator's own
  docblock: it is sufficient to prove and test the mechanism end to end, and
  a production deployment can drop in a larger vetted list (e.g. the real
  top-100k) by replacing the file's contents only, no code change. The
  test's blocklisted fixture, `password123456`, was confirmed present in
  the generated file (`grep`) before being used, per the task's instruction
  not to assume.

  **Test approach:** per the task's guidance that the consuming form/DTO
  doesn't exist yet, `PasswordPolicyTest` builds a validator directly via
  `Validation::createValidatorBuilder()` with a custom
  `ConstraintValidatorFactory`, rather than booting the kernel. The HIBP-
  outage simulation constructs a real `NotCompromisedPasswordValidator`
  with its `$enabled` constructor argument set to `false` — the identical
  mechanism this project's `config/packages/validator.yaml`
  (`when@test: not_compromised_password: false`) already uses to disable
  the live HTTP call in the test environment — which is a stub that fails
  open exactly as an HIBP outage would. One judgement call on the multi-byte
  test: the literal instruction ("a 12-character password with a non-ASCII
  character passes") wouldn't by itself distinguish byte-counting from
  codepoint-counting, since a 12-plus-codepoint value passes under either
  mode. The fixture used instead has 11 codepoints but 13 bytes (ends in one
  3-byte UTF-8 character), so it fails under `Length::COUNT_CODEPOINTS` and
  passes under `Length::COUNT_BYTES` — both modes are asserted directly on
  the same value, so the byte-vs-character distinction is actually
  exercised, not just plausible. The over-limit test also asserts the
  violation's `{{ value_length }}` parameter equals the full untruncated
  byte count (4097), not a clipped 4096, to prove non-truncation.

  Verify: `docker compose exec -T -e APP_ENV=test php php bin/phpunit
  tests/Validator/PasswordPolicyTest.php` — **6 tests, 19 assertions,
  green**. `bin/console lint:container` — green. Full suite re-run
  afterward: **111 tests, 280 assertions, green** — no regressions from
  Tasks 1–24 (105 tests/261 assertions before this task's 6 tests/19
  assertions).

- [x] 26. **`EmailVerificationTokenService` and `EmailVerificationService`.**
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

  **Done 2026-08-19.** New files: `src/Service/EmailVerificationTokenService.php`
  (`issue()`/`consume()`), `src/Service/EmailVerificationService.php`
  (`resend()`/`consume()`), three typed exceptions in `src/Service/Exception/`
  (`InvalidVerificationTokenException`, `VerificationTokenAlreadyConsumedException`,
  `VerificationTokenExpiredException` — the latter two carry the token's
  `User`, matching this task's other convention-source, `UserAccountService`'s
  `EmailAlreadyInUseException`), and `src/Message/SendEmailMessage.php`.
  `src/Repository/EmailVerificationTokenRepository.php` gained
  `findOneBySelectorForUpdate()` (the pessimistic-lock query) and
  `deleteAllForUser()` (bulk DQL delete, mirroring
  `ResetPasswordRequestRepositoryTrait::removeRequests()`'s shape from Task 7).
  `config/services.yaml` gained an explicit argument binding for
  `EmailVerificationService`'s two `RateLimiterFactory` arguments
  (`limiter.password_reset_account` / `limiter.password_reset_source`), the
  same "autowiring can't distinguish two same-typed args" fix Task 22 already
  applied to `LoginRateLimiter`.

  **Selector length discrepancy, resolved per this task's own instruction to
  check the entity rather than assume.** Both the plan and the architecture
  doc say "`random_bytes(9)` base64url-encoded selector (24 chars)" — but
  9 bytes is a multiple of 3, so base64 never pads it: the actual encoded
  length is always exactly 12 characters, not 24. `EmailVerificationToken`'s
  column is `varchar(24)` (Task 6) — generous headroom, not a required exact
  width. Implemented as documented in the code: `random_bytes(9)` ->
  12-char base64url selector, `SELECTOR_LENGTH = 12` is the single constant
  `issue()` and `consume()` both key off, and it fits the column with room to
  spare. No entity or migration change needed.

  **Pessimistic lock API, confirmed against installed `doctrine/orm ^3.6`
  source (`vendor/doctrine/orm/src/Query.php::setLockMode()`,
  `vendor/doctrine/orm/src/EntityManager.php::checkLockRequirements()`):**
  `Query::setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)` throws
  `Doctrine\ORM\TransactionRequiredException` unless the connection already
  has an active transaction — so `findOneBySelectorForUpdate()` must only be
  called from inside `EntityManagerInterface::wrapInTransaction()` (or an
  equivalent explicit transaction), never standalone. `consume()` does the
  whole read-lock-check-write sequence inside one `wrapInTransaction()` call,
  which is also what makes the row lock actually serialize two concurrent
  consume attempts (Task 28 will assert this directly).

  **Closed-EntityManager pitfall, same one Task 24 documented.**
  `wrapInTransaction()` closes its EntityManager on *any* exception escaping
  the callback, including the plain domain exceptions `consume()` throws for
  every expected rejection (invalid/expired/already-consumed). Copied Task
  24's `UserAccountService::openEntityManager()` recovery pattern verbatim
  into `EmailVerificationTokenService` (detect a closed manager, ask
  `ManagerRegistry::resetManager()` for a fresh one) so a rejected
  `consume()` call never leaves a later call on the same request broken.

  **Rate limiter exhaustion handled by inspecting the result, not catching an
  exception.** Confirmed via `vendor/symfony/rate-limiter/RateLimit.php` /
  `LimiterInterface.php`: `LimiterInterface::consume()` never throws on its
  own — it returns a `RateLimit` whose `isAccepted()` reports the outcome;
  only the unused `RateLimit::ensureAccepted()` helper would throw
  `RateLimitExceededException`. `resend()` calls `->consume()` on both the
  `password_reset_account` and `password_reset_source` limiters and, if
  either is not accepted, returns silently — no exception, no return-value
  difference, so a rate-limited caller is indistinguishable from a normal
  one, exactly as this task's own instruction required.

  **`password_reset_account` keying matches the architecture doc literally,
  including its asymmetry with `login_account`.** The architecture text
  keys `login_account` on `hash('sha256', $normalizedEmail . $appSecret)`
  but `password_reset_account` on "the normalized email" alone, with no
  extra secret-keyed hash step mentioned. Implemented exactly as specified
  rather than silently "fixing" what may or may not be a deliberate choice —
  `RateLimiterFactory`/`CacheStorage` already sha1-hashes every limiter id
  before it reaches the cache pool (Task 22 confirmed this from
  `vendor/symfony/rate-limiter/Storage/CacheStorage.php`), which is enough to
  make the raw email a safe *cache key*, even though it is not the additional
  reversibility-resistance property the login limiter's extra hash buys.
  Flagged here for a future security-reviewer pass, not changed unilaterally.

  **Source-limiter keying reuses `App\Security\IpTruncator` as-is.**
  `EmailVerificationService::resend(string $emailInput): void` takes no
  `Request`, unlike `LoginRateLimiter` (an `AbstractRequestRateLimiter`), so
  the client IP is read via an injected `RequestStack` instead.

  **`SendEmailMessage` shape chosen now, minimal, per this task's explicit
  scope boundary against Task 29.** `src/Message/SendEmailMessage.php` is a
  plain, fully serializable DTO: `to` (string), `template` (string
  identifier — `SendEmailMessage::TEMPLATE_VERIFY_EMAIL = 'verify_email'` is
  the one constant this task needs), and `context` (flat
  `array<string, scalar>`, holding the raw `selector.verifier` token string
  under `'token'` for `resend()`'s dispatch). Deliberately not a
  `Symfony\Component\Mime\Email`/`TemplatedEmail` object, which is not
  reliably serializable across a Messenger transport. `resend()` dispatches
  it via the autowired `MessageBusInterface`. **Nothing consumes this
  message yet** — no `#[AsMessageHandler]` handler, no `async` transport
  routing in `config/packages/messenger.yaml` (still the Flex default, only a
  `sync` transport), no `templates/emails/*.twig` template. Task 29 owns all
  three; until then, dispatching this message goes to the default `sync` bus
  with no registered handler and would fail at dispatch time with
  `NoHandlerForMessageException` if actually invoked with a real user — out
  of this task's scope (`resend()`'s functional coverage is Task 28, which
  will need Task 29's transport/handler wired in test config, or a stub, to
  exercise the dispatch path without erroring).

  **Verify, exactly as specified:** `php -l` on
  `src/Service/EmailVerificationTokenService.php`,
  `src/Service/EmailVerificationService.php`, `src/Message/SendEmailMessage.php`,
  and the three new exception files — clean. `lint:container` — OK in both
  `dev` and `test` environments; `debug:container` confirms every argument
  of both new services resolves to the intended service id (including the
  `limiter.password_reset_account`/`limiter.password_reset_source` binding).
  `lint:yaml config/services.yaml` — OK. Full suite re-run afterward: **111
  tests, 280 assertions, green** — unchanged from Task 25 (this task adds no
  tests of its own; functional coverage is Task 28).

- [x] 27. **`EmailVerificationController`, `ResendVerificationFormType`,
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

  **Done 2026-08-19.** New files: `src/Controller/EmailVerificationController.php`
  (`verify()` on `/verify-email/{token}`, `resend()` on `/verify-email/resend`),
  `src/Form/ResendVerificationFormType.php` (one `email` field, `NotBlank` +
  `Email` constraints), `templates/verify_email/resend.html.twig`,
  `templates/verify_email/result.html.twig`. Both routes were already covered
  by Task 12's `^/verify-email` `PUBLIC_ACCESS` entry in
  `config/packages/security.yaml` -- no security.yaml change needed.

  **Route ambiguity, caught before it could bite.** `/verify-email/{token}`
  has no charset restriction that would exclude the literal path segment
  "resend" (it is a valid base64url string too), so without disambiguation
  whichever route Symfony's attribute loader happened to register first would
  shadow the other for `GET /verify-email/resend`. Fixed two ways: (1)
  `app_verify_email_resend` declares `priority: 1` (confirmed via
  `vendor/symfony/routing/Loader/AttributeClassLoader.php` that
  `#[Route(priority:)]` controls match order independent of declaration
  order), so it always wins the ambiguous path regardless of method order in
  the class; (2) `app_verify_email`'s `{token}` requirement is
  `[A-Za-z0-9_-]{20,}`, which "resend" (6 chars) can never satisfy, as
  belt-and-braces and as a plain-404 rejection of an obviously-malformed
  token before it reaches the service. `debug:router` confirms both routes
  resolve to distinct, correctly-ordered entries.

  **All three typed exceptions mapped to distinct template states, none
  escape uncaught.** `verify()` catches `InvalidVerificationTokenException`,
  `VerificationTokenAlreadyConsumedException`, and
  `VerificationTokenExpiredException` around
  `EmailVerificationService::consume()` and renders `result.html.twig` with
  `state` = `invalid` / `already_consumed` / `expired` respectively; the
  no-exception path (including the service's own idempotent
  already-verified-success case) renders `state = success`. Confirmed via an
  ad hoc throwaway functional check (not committed -- Task 28 owns the real
  coverage) that an invalid-format token renders the `invalid` state at 200,
  and a too-short token 404s at the router rather than reaching the
  controller at all.

  **Only two templates, per this task's own list -- no separate
  `check_email.html.twig` the way the reset-password flow has one.**
  `resend.html.twig` renders either the form (GET, or an invalid POST
  re-render) or the "Check your email" confirmation (successful POST),
  selected by a `submitted` flag the controller passes in, since
  `EmailVerificationService::resend()` returns `void` and never distinguishes
  found/not-found/already-verified/rate-limited outcomes to the caller
  (confirmed by re-reading Task 26's `resend()` body directly rather than
  assuming). A blank-email submission correctly 422s and re-renders the form
  with its validation error and CSRF token intact -- Symfony Form's own
  default status for an invalid submission, not a bug.

  **CSRF:** no custom wiring. `ResendVerificationFormType` is a standard
  Symfony Form, so `createForm()` automatically uses
  `config/packages/csrf.yaml`'s project-wide `token_id: submit` (already in
  `stateless_token_ids`) -- the same mechanism every other form in this
  project relies on, confirmed present in the rendered form's hidden
  `_token` field.

  **Verify, exactly as specified:** `docker compose exec -T -e APP_ENV=test
  php php bin/console lint:twig templates/verify_email` -- "All 2 Twig files
  contain valid syntax." `debug:router | grep verify_email` --
  `app_verify_email_resend GET|POST /verify-email/resend` and
  `app_verify_email GET /verify-email/{token}`, both present, in that
  disambiguated order. `lint:container` -- OK. Full suite re-run afterward:
  **111 tests, 280 assertions, green** -- unchanged from Task 26 (this task
  adds no persistent tests of its own; functional coverage is Task 28),
  `RouterSweepTest` specifically re-run and green (both new routes are on the
  public allow-list, so the sweep skips asserting a status on them, exactly
  as it does for every other public route).

- [x] 28. **Verification mechanism test: single-use, expiry, idempotent,
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

  **Done 2026-08-19.** New file `tests/Functional/EmailVerificationFlowTest.php`,
  7 tests: resend-issues-and-consume success; same-token-twice refused
  (tested at `EmailVerificationTokenService::consume()`, not the outer
  `EmailVerificationService::consume()` — see below); 24h+1-minute expiry
  refused; two idempotent-re-verification cases (same token replayed, and a
  genuinely fresh second token for an already-verified user); an
  invalid-token control case; and the concurrent-consume race. Two small
  support files: `tests/Support/RecordingEmailMessageHandler.php` (a
  test-only Messenger handler, wired only under a new `when@test:` block in
  `config/services.yaml`, so `resend()`'s dispatch of `SendEmailMessage` has
  somewhere to go before Task 29 builds the real handler — otherwise it
  throws `NoHandlerForMessageException`, exactly as `SendEmailMessage`'s own
  docblock anticipated this task would need to solve) and
  `tests/Functional/fixtures/consume-email-verification-token-subprocess.php`
  (the concurrent test's second racer, see below).

  **Two layers, tested separately, because they legitimately disagree on
  purpose.** `EmailVerificationTokenService::consume()` throws
  `VerificationTokenAlreadyConsumedException` on *every* replay of a spent
  token — that is single-use, enforced server-side. `EmailVerificationService
  ::consume()` (what a revisited link actually calls) catches that specific
  exception and treats it as a silent success when the token's own subject
  is already verified, so a user re-clicking their own link never sees an
  error. Testing only the outer layer would never prove single-use at all
  (replay always "succeeds" there); testing only the inner layer would miss
  the idempotent-UX guarantee. Both are asserted, at the layer that actually
  owns each property.

  **Investigated, not assumed: does a revisited link need a second valid
  token, or does consume() accept any fresh one for an already-verified
  user?** Read `EmailVerificationTokenService::consume()` directly rather
  than inferring: it checks only the token's own `isConsumed()`/`isExpired()`
  state, never the user's verification status, before running its happy
  path. So a second, genuinely fresh, unexpired token for an already-
  verified user *is* consumed for real and `User::markEmailVerified()` is
  called again — idempotency here holds only because that method is
  `$this->emailVerifiedAt ??= $at` (null-coalescing). No bug: the guard that
  makes this safe is on the entity, not in the service, so
  `testConsumingAFreshTokenForAnAlreadyVerifiedUserDoesNotMoveTheTimestamp`
  asserts it directly (timestamp equality across both consumptions) instead
  of trusting that inference.

  **A real, pre-existing bug found and fixed: email verification was broken
  for every normal (fresh-request) visit.** Every scenario above passed
  *within a single test method* on the first attempt, because the token's
  `User` was always already fully loaded in the same EntityManager before
  `consume()` touched it. The concurrent-consume subprocess — a genuinely
  separate PHP process with its own EntityManager that had never loaded that
  `User` anywhere else, i.e. exactly what a real unauthenticated visit to
  `/verify-email/{token}` looks like in production — reproducibly hit
  `LogicException: Attempting to change readonly property App\Entity\User::$id`
  inside Doctrine's hydrator. Root cause: `User::$id` is a readonly,
  object-typed (`Uuid`) identifier; the moment `consume()` forces the
  token's lazily-associated `User` proxy to initialize (via
  `markEmailVerified()`, or even just constructing one of the typed
  exceptions with it), Doctrine's hydrator tries to re-set every mapped
  field on it, including `$id`. Doctrine's readonly-property guard only
  tolerates a re-set whose new value is `===` the old one; the proxy's
  pre-populated `$id` and the hydrator's freshly constructed `Uuid` for the
  same row are two different object instances, so `!==` (identity, not
  value, comparison) trips every time. Confirmed via a minimal
  `KernelTestCase` reproduction (`$em->clear()` before `consume()`, no
  subprocess needed) and confirmed the failure persists even with the
  association mapped `fetch: EAGER` (moves *where* it fails, not whether).
  **Fix**, scoped to the one path this task exercises:
  `EmailVerificationTokenRepository::findUserIdBySelector()` (new — reads
  the raw `user_id` FK via DQL's `IDENTITY()`, never touching the `user`
  association) lets `EmailVerificationTokenService::consume()` warm the
  identity map with a plain, top-level, fully-hydrated `User` *before*
  running the locked token query — the one hydration path that populates an
  object-typed readonly identifier in a single pass instead of a
  stub-then-fill sequence. Documented at length on the `consume()` call site
  itself. **Not fixed, flagged for follow-up:** `ResetPasswordRequest` and
  `AuthEvent` map the identical `User` association shape and likely carry
  the same latent bug wherever either loads a `User` that was not already
  independently loaded first in that request — out of this task's scope
  since neither is exercised here; a `security-reviewer` or `architect`
  pass should confirm and, if real, apply the same pattern (or reconsider
  `User::$id`'s readonly-ness at the architecture level).

  **Concurrency mechanism — how the lock's genuine serialization was
  proved, not just its outcome.** The task explicitly warns that "one
  success, one refusal" can pass even with the lock removed, if the two
  attempts merely run sequentially rather than genuinely racing. The test:
  (1) opens a second, real DBAL connection (`DriverManager::getConnection()`
  on the same DSN — a distinct Postgres backend) and takes the identical
  `SELECT ... FOR UPDATE` `findOneBySelectorForUpdate()` takes, holding it
  open without committing; (2) spawns
  `fixtures/consume-email-verification-token-subprocess.php` as a genuinely
  separate OS process via `proc_open()` — its own PHP interpreter, its own
  kernel boot, its own Postgres connection, calling the real
  `EmailVerificationTokenService::consume()`, not a mock; (3) that
  subprocess signals readiness (via a plain marker file, not a pipe --
  `stream_select()` on a `proc_open()` pipe proved unreliable from inside a
  PHPUnit-booted process specifically, hanging indefinitely even with an
  explicit timeout where the identical code worked from a bare CLI script;
  file-existence polling has no such failure mode) immediately before
  calling `consume()`, so the parent's hold window starts only once the race
  is genuinely live, independent of kernel-boot jitter; (4) the parent holds
  the lock for a fixed 300ms after that signal, then releases it without
  mutating anything; (5) the subprocess's own `consume()` call is timed from
  the inside (excluding boot time) and must report both `SUCCESS` *and* an
  elapsed time ≥ 200ms — if the lock did nothing, that call would return
  almost instantly having read `consumedAt = null`, exactly the double-
  consumption bug the lock exists to prevent; (6) the parent then makes its
  own second, real `consume()` attempt against the now-actually-consumed
  row and asserts it is refused as already consumed, proving the *other*
  racer lost. Confirmed stable across repeated runs, and confirmed the
  second-place attempt is genuinely refused rather than merely slow.

  **One more pitfall hit and fixed while building the concurrency test:**
  an earlier version wrote
  `self::assertTrue($this->waitForFile(...), sprintf(..., stream_get_contents($pipes[2])))`
  — PHP evaluates every argument before the call, so the diagnostic
  message's `stream_get_contents()` ran unconditionally, including on the
  success path, where it deadlocks reading the child's still-open stderr
  pipe to EOF (the child cannot exit until the parent's held lock below is
  released). Rewritten as an explicit `if (!waitForFile(...)) { self::fail(...) }`
  so the pipe read only happens when actually needed.

  **Verify, exactly as specified:** `docker compose exec -T -e APP_ENV=test
  php php bin/phpunit tests/Functional/EmailVerificationFlowTest.php` --
  7 tests, 31 assertions, green, stable across 3 repeated runs. Full suite
  re-run afterward: **118 tests, 311 assertions, green** (up from 111 at
  Task 27 -- this task's 7 tests are the only addition). `lint:container`,
  `lint:yaml config/services.yaml`, and `doctrine:schema:validate` all OK
  after the repository/service changes.

- [x] 29. **Messenger transport, `SendEmailMessage`, templated emails.**
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

  **Done 2026-08-19.** `config/packages/messenger.yaml`: `async` (DSN
  `%env(MESSENGER_TRANSPORT_DSN)%`, `retry_strategy: { max_retries: 3, delay:
  5000, multiplier: 3 }`) and `failed` (`doctrine://default?queue_name=failed`
  — same table, different `queue_name`, not a second physical table)
  transports, plus top-level `failure_transport: failed`.
  `DispatchAfterCurrentBusMiddleware` needed no config change: confirmed by
  reading `FrameworkExtension::registerMessengerConfiguration()`
  (`vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php:2410-2413`)
  that `dispatch_after_current_bus` is unconditionally in the framework's own
  `$defaultMiddleware['before']` array for every bus — already active,
  nothing to enable. `src/MessageHandler/SendEmailMessageHandler.php`
  (`#[AsMessageHandler]`) is new; `src/Message/SendEmailMessage.php` (Task
  26) needed no changes at all — its existing `to`/`template`/`context`
  shape was already everything the handler needs.

  **The env-conditional routing/handler reconciliation, verified
  empirically in all three environments, not assumed.** `routing:` for
  `App\Message\SendEmailMessage` lives only under `messenger.yaml`'s
  `when@dev:`/`when@prod:` blocks — deliberately absent from the base config
  and from `when@test:`. Reasoning, confirmed by reading
  `SendMessageMiddleware`'s real behavior rather than guessing: once a
  message class has *any* routing entry, Messenger sends it to that
  transport and does **not** also hand it to a local (same-process) handler
  — a transport substitutes for local handling, it does not add to it. Task
  28's `EmailVerificationFlowTest` calls
  `EmailVerificationService::resend()` directly and reads
  `RecordingEmailMessageHandler->last()` immediately afterward, with no
  worker involved anywhere — that only works if the message stays on the
  default (sync) bus and is handled locally and synchronously. Giving
  `SendEmailMessage` a routing entry in test (or unconditionally at the base
  level, which test would inherit) would have silently broken every one of
  those already-green assertions. So: test has no routing entry at all ->
  message stays local -> handled by whichever handler is tagged in that
  environment. `config/services.yaml` makes that exactly one handler per
  environment: the real `SendEmailMessageHandler` carries
  `#[AsMessageHandler]` (tag added by autoconfiguration in every
  environment by default), and a new `when@test:` override
  (`App\MessageHandler\SendEmailMessageHandler: { autowire: true,
  autoconfigure: false, arguments: { $mailerFromAddress: ... } }`) disables
  *only* the autoconfigured tag for that one service id in test —
  `autowire: true` had to be repeated explicitly there too: overriding a
  resource-discovered service id with a fresh definition under `when@test:`
  was confirmed, via `debug:container --env=test`, to **not** inherit the
  base file's `_defaults: autowire: true` unless restated, leaving 3 of the
  handler's 4 constructor arguments unresolved (harmless only because the
  now-untagged, unused service is pruned by the compiler in test, but wrong
  to leave latent — fixed before finishing).

  Verified with `debug:config framework messenger` (not `debug:messenger`,
  which lists handler bindings but not routing) under all three
  `--env`s: `--env=test` shows `routing: {}`; `--env=dev` and `--env=prod`
  both show `routing: { App\Message\SendEmailMessage: [async] }`. Verified
  with `debug:container App\MessageHandler\SendEmailMessageHandler`:
  `--env=test` shows `Tags: -`, `Autoconfigured: no`, all 4 arguments
  resolved; `--env=dev`/`--env=prod` show the `messenger.message_handler`
  tag and the same 4 resolved arguments. Verified with `debug:messenger`:
  `--env=test` lists `App\Message\SendEmailMessage` handled by
  `RecordingEmailMessageHandler` only; `--env=dev`/`--env=prod` list it
  handled by `SendEmailMessageHandler` only — never both, in any
  environment. End-to-end proof beyond static config: a disposable
  throwaway console command (`app:dev-dispatch-test-mail`, written, run, and
  deleted — not part of this commit) dispatched a real `SendEmailMessage`
  under `--env=dev`; `messenger:consume async --limit=1` then processed it,
  logging `handled by App\MessageHandler\SendEmailMessageHandler::__invoke`
  and "was handled successfully", with the row gone from `messenger_messages`
  afterward (Doctrine transport deletes on ack) — the whole pipeline
  (routing, `UrlGenerator`, Twig render, `MailerInterface::send()` against
  `MAILER_DSN=null://null`) working for real, not just compiling.

  **Templates: one file each, not two, per this task's own list.** Both
  `templates/emails/verify_email.html.twig` and
  `templates/emails/reset_password.html.twig` hold `subject`, `text`, and
  the HTML body together, using an `{% if false %}...{% endif %}` guard
  around the `subject`/`text` blocks. Verified this actually works, not
  assumed: a throwaway `Twig\Environment`/`ArrayLoader` script (run, then
  deleted) confirmed a plain `render()` of such a template outputs *only*
  the unguarded HTML (plus whatever `{{ block('subject') }}` pulls inline
  for the `<title>`), while `renderBlock('subject', ...)` and
  `renderBlock('text', ...)` independently return just those blocks' content
  — Twig's block-definitions are compiled regardless of the surrounding
  `{% if %}`'s runtime value, only the *default* in-place print is skipped.
  `SendEmailMessageHandler` loads the template once, calls `renderBlock()`
  for `subject`/`text`, and separately gives `TemplatedEmail::htmlTemplate()`
  the same file for the HTML body (the `{% if false %}` guard is what stops
  that HTML render from also duplicating the subject/text content inline).
  `reset_password.html.twig` is genuinely unused by anything yet, exactly as
  the task anticipated: there is no `SendEmailMessage::TEMPLATE_RESET_PASSWORD`
  constant and no matching case in the handler's template map or
  `buildContext()` `match` — Task 30 adds both when it actually dispatches
  this template. `lint:twig templates/emails` — "All 2 Twig files contain
  valid syntax."

  **A From address needed a home that isn't `.env`.** No existing convention
  for it anywhere in the project. Added `app.mailer_from_address:
  'no-reply@example.com'` as a plain parameter in `config/services.yaml`
  (not an env var — it is not secret and not infrastructure-specific the way
  `MAILER_DSN` is) and bound it explicitly to the handler's
  `$mailerFromAddress` constructor argument (autowiring cannot infer a plain
  string from its type).

  **`.env` needed no edit at all.** Re-checked before touching anything,
  since `.env`/`.env.*` are hard-denied to this agent's Read/Edit/Write
  tools by `.claude/settings.json` (consistent with the coder skill's own
  "never read or edit `.env`" rule) — confirmed via `git show HEAD:.env`
  that Task 1's Flex recipes already wrote both
  `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` and
  `MAILER_DSN=null://null` verbatim. Nothing for this task to add there.

  **The deferred `messenger_messages` migration (flagged by Task 9,
  claimed here as promised).** Task 9 could not diff this table because no
  `doctrine://` transport existed yet to make the schema listener contribute
  it. With `async`/`failed` now configured, `doctrine:migrations:diff`
  generated exactly one new table + its composite index (unmodified
  Symfony/Doctrine-Messenger shape) as new migration
  `Version20260819095142`. Migrated on both the main and `app_test`
  databases; `doctrine:schema:validate` and a follow-up
  `doctrine:migrations:diff` (`No changes detected`) both clean on each.

  **An unrelated, real, pre-existing production bug found and fixed while
  verifying `--env=prod`.** `php bin/console debug:messenger --env=prod`
  failed outright with `Unrecognized option "cookie_name" under
  "framework.session"` — Task 13 had written `cookie_name: __Host-SESSID`
  under `when@prod`, but Task 2's own verified key list
  (`Configuration.php:769-800`) names the key `name`, not `cookie_name`;
  `--env=prod` had therefore never actually compiled since Task 13 landed.
  Nothing caught it because every task's verify commands ran under
  `-e APP_ENV=test`/`dev`, never `prod`. Fixed in
  `config/packages/framework.yaml` (`name: __Host-SESSID`), documented in
  place; `lint:container` now passes under all three environments.

  **Verify, exactly as specified:** `lint:yaml
  config/packages/messenger.yaml` — OK. `debug:messenger` (`--env=dev`)
  lists `App\Message\SendEmailMessage` handled by
  `App\MessageHandler\SendEmailMessageHandler`, and `debug:config framework
  messenger` confirms the `async` routing entry backing that (see above for
  why `debug:messenger` alone shows handler bindings, not transport
  routing). `phpunit tests/Functional/QueuedMailDoesNotBlockResponseTest.php`
  — 2 tests, 6 assertions, green: one proves a direct `send()` to the real
  `async` transport service stamps a `TransportMessageIdStamp` and lands a
  matching row in `messenger_messages` with no handler invoked and no
  worker running; the other proves an unrelated controller request
  (`GET /verify-email/resend`) remains a normal, fast 2xx response with such
  a row still sitting unprocessed in that table. Full suite re-run
  afterward, twice for stability: **120 tests, 317 assertions, green** (up
  from 118/311 at Task 28 — this task's 2 tests are the only addition;
  `EmailVerificationFlowTest`'s 7 tests re-run individually and still green,
  confirming the routing change did not disturb Task 28).

- [x] 30. **`PasswordResetService` over `reset-password-bundle`.**
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

  **Done 2026-08-19.** `config/packages/reset_password.yaml` filled in with
  the three confirmed keys (`request_password_repository:
  App\Repository\ResetPasswordRequestRepository`, `lifetime: 3600`,
  `throttle_limit: 0`). New file `src/Service/PasswordResetService.php`
  (`request()`/`complete()`), new
  `src/Service/Exception/SourceRateLimitExceededException.php` (carries
  `getRetryAfter(): \DateTimeImmutable`), and
  `App\Repository\ResetPasswordRequestRepository::findUserIdBySelector()`
  (new method, DQL `IDENTITY(t.user)` by selector). `SendEmailMessage`
  gained `TEMPLATE_RESET_PASSWORD`, and `SendEmailMessageHandler` gained its
  template-map entry and `buildContext()` case (`resetUrl` via the
  `app_reset_password` route) — both explicitly anticipated by Task 29's own
  plan note ("Task 30 adds both when it actually dispatches this
  template"). `config/services.yaml` binds `PasswordResetService`'s two
  `RateLimiterFactory` arguments to `limiter.password_reset_account` /
  `limiter.password_reset_source`, the same "autowiring can't distinguish
  two same-typed args" fix already applied to `LoginRateLimiter` and
  `EmailVerificationService`.

  **Rate-limit exception distinction, confirmed against the installed
  `symfony/rate-limiter` API, not assumed.** `LimiterInterface::consume()`
  never throws on its own (only the unused `RateLimit::ensureAccepted()`
  helper does) — it returns a `RateLimit` with `isAccepted()`/
  `getRetryAfter()`. `request()` consumes both `password_reset_account` and
  `password_reset_source` unconditionally, then branches on the results
  exactly as the task specifies: an exhausted *source* limiter throws
  `SourceRateLimitExceededException` (the controller, in Task 31, may turn
  this into a 429 — source is independent of any account, so this discloses
  nothing about which addresses exist); an exhausted *account* limiter never
  throws — `request()` returns normally and the caller still renders the
  identical check-email outcome (AC-11). Keying mirrors
  `EmailVerificationService::resend()` exactly (shared limiter pair, AC-20):
  `password_reset_account` on the normalized email as-is, `password_reset_source`
  on `IpTruncator::truncate()` of the client IP read via `RequestStack`.

  **Ordering confirmed to make the bundle's own throttle a non-issue, not
  just disabled.** `request()` calls
  `ResetPasswordRequestRepository::removeRequests($user)` *before*
  `ResetPasswordHelper::generateResetToken()`, per the task's explicit
  instruction. Read `ResetPasswordHelper::generateResetToken()` directly
  (not assumed): it calls `hasUserHitThrottling()` ->
  `getMostRecentNonExpiredRequestDate($user)` *after* our delete has already
  run, so it never finds a prior request to throttle against regardless of
  `throttle_limit`'s value — `TooManyPasswordRequestsException` cannot be
  thrown from this call site, so `request()` does not catch it (no
  unneeded defensive code for a path that cannot happen, per the task's own
  instruction).

  **Identity-map bug investigation (the task's central ask), verified
  empirically both ways, not assumed either way.** Task 28 flagged
  `ResetPasswordRequest` as "likely carries the same latent bug" as the one
  it found and fixed in `EmailVerificationTokenService::consume()`, without
  exercising it. Read the installed bundle source directly to check:
  `ResetPasswordHelper::validateTokenAndFetchUser()` calls
  `$resetRequest->getUser()` on a `ResetPasswordRequest` it just hydrated
  via a plain `findOneBy()` (the bundle's own trait), on a `#[ORM\ManyToOne]`
  association with no `fetch: EAGER` — the identical shape
  `EmailVerificationToken` has. **Conclusion: the bug DOES reproduce.**
  Confirmed with a minimal `KernelTestCase` repro,
  `tests/Service/PasswordResetServiceIdentityMapTest.php` (1 test, 3
  assertions): persist a user, issue a real reset token via
  `ResetPasswordHelper::generateResetToken()`, `$em->clear()` to simulate a
  genuinely fresh request (the normal case — `/reset-password/reset/{token}`
  is `PUBLIC_ACCESS` and never independently loads a `User` first), then
  call `complete()`. With the identity-map warm-up
  (`ResetPasswordRequestRepository::findUserIdBySelector()` +
  `$entityManager->find(User::class, $userId)` before
  `validateTokenAndFetchUser()`) temporarily removed, this reproducibly
  threw `LogicException: Attempting to change readonly property
  App\Entity\User::$id` at `User::setPasswordHash()`, called from
  `PasswordResetService::complete()` — confirmed by running the test with
  the fix commented out before restoring it verbatim (`diff` confirmed an
  exact match against the pre-experiment file) and re-running to green.
  **Fix, identical to Task 28's:** a new
  `ResetPasswordRequestRepository::findUserIdBySelector()` (DQL
  `IDENTITY(t.user)` by selector, never touching the `user` association)
  lets `complete()` warm the identity map with a plain, top-level,
  fully-hydrated `User` before `validateTokenAndFetchUser()` runs — once
  that `User` is already tracked, Doctrine's association-hydration path
  returns the same real instance instead of creating a proxy for it, and
  the conflict never occurs.

  **Closed-EntityManager pitfall, judged rather than copied
  automatically.** The task asked whether this applies here given
  `complete()` has no unique-constraint-adjacent path (password updates
  don't collide with `app_user`'s email index). Investigated rather than
  assumed: `complete()`'s transaction runs `validateTokenAndFetchUser()` and
  `removeResetRequest()` inside `wrapInTransaction()`'s callback, both of
  which throw a plain `ResetPasswordExceptionInterface` (invalid/expired
  token) for every expected rejection — confirmed against
  `EntityManager::wrapInTransaction()`'s source that it closes the manager
  on *any* exception escaping the callback, not only a DBAL-level one, the
  same general shape Task 26 already documented (not a unique violation, but
  the identical "domain exception escapes the transaction" trigger). So yes,
  it applies, for a different reason than the task's own hint suggested —
  `openEntityManager()` (copied verbatim from
  `EmailVerificationTokenService`) is used in `complete()`. `request()`
  deliberately does *not* get this treatment: it never calls
  `persist()`/`flush()`/`wrapInTransaction()` itself — every write it
  triggers happens inside `ResetPasswordRequestRepository`'s/the bundle's
  own `EntityManager` reference, which this service never touches directly,
  so fetching our own fresh manager would protect nothing there (no
  unneeded defensive code for a path our own code doesn't create).

  **Flagged, not fixed here — out of this task's scope.** Read
  `Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::resolveRepository()`
  directly: it memoizes (`??=`) the `EntityManager` it resolves from
  `ManagerRegistry` on a repository's *first* use for the life of that
  repository instance, and never re-resolves after that — so any container
  singleton repository (this one included, and pre-existing ones like
  `UserRepository`) that already cached a now-closed manager before
  `ManagerRegistry::resetManager()` ran would still use that stale, closed
  instance on a later *write* through the same repository object (a later
  *read* is unaffected — `errorIfClosed()` gates only
  `persist()`/`remove()`/`flush()`/`refresh()`, confirmed by reading
  `EntityManager.php` directly). This is a pre-existing, codebase-wide
  characteristic of `ServiceEntityRepository` (also true of
  `UserRepository::upgradePassword()`'s direct `persist()`/`flush()` calls),
  not something introduced by this task, and fixing it would mean changing
  the repository/registry layer, not `PasswordResetService`. Practically
  reachable only within one long-lived container (a worker process, or a
  single test/request calling `complete()` with a bad token and then
  `request()` afterward) — not ordinary PHP-FPM traffic, where each request
  gets a fresh container. Flagged here for a follow-up
  `security-reviewer`/`architect` pass, the same way Task 28 flagged
  `ResetPasswordRequest`/`AuthEvent` rather than fixing them outside its own
  scope.

  **Verify, exactly as specified:** `php -l` clean on
  `src/Service/PasswordResetService.php` and every other new/edited file.
  `lint:yaml config/packages/reset_password.yaml` and `config/services.yaml`
  — OK. `lint:container` — OK in `test`, `dev`, *and* `prod` (`debug:config
  symfonycasts_reset_password` confirms the three keys land exactly as
  configured; `debug:container App\Service\PasswordResetService` confirms
  all nine constructor arguments resolve to the intended services).
  `doctrine:schema:validate` — OK (no schema change this task). `lint:twig
  templates/emails` — OK, unchanged from Task 29 (the reset-password
  template was already correct; only its dispatch wiring was inert until
  now). `phpunit tests/Service/PasswordResetServiceIdentityMapTest.php` — 1
  test, 3 assertions, green. Full suite re-run twice back to back for
  stability: **121 tests, 320 assertions, green both times** (up from
  120/317 at Task 29 — this task's 1 test is the only addition; functional
  coverage of `request()`/`complete()` themselves is Task 32, as scoped).

- [x] 31. **`ResetPasswordController`, forms, templates.**
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

  **Done 2026-08-19.** New files: `src/Controller/ResetPasswordController.php`
  (`request()` on `/reset-password`, `reset()` on
  `/reset-password/reset/{token}`), `src/Form/ResetPasswordRequestFormType.php`
  (one `email` field, `NotBlank` + `Email`, mirroring
  `ResendVerificationFormType` exactly), `src/Form/ChangePasswordFormType.php`
  (`RepeatedType` wrapping a `PasswordType`, Task 25's three constraints on the
  first field only), `templates/reset_password/request.html.twig`,
  `check_email.html.twig`, `reset.html.twig`. Both routes were already covered
  by Task 12's `^/reset-password` `PUBLIC_ACCESS` entry in
  `config/packages/security.yaml` — no security.yaml change needed, confirmed
  via `RouterSweepTest` (see below) and by re-reading the existing entry
  before assuming.

  **Route name confirmed to match Task 30's forward reference, not just
  assumed.** `app_reset_password` is `SendEmailMessageHandler::buildContext()`'s
  exact route name for `resetUrl`; `debug:router app_reset_password` shows it
  resolving to `App\Controller\ResetPasswordController::reset()` at
  `/reset-password/reset/{token}`, so the reset email's link is live end to
  end now that this task exists (previously inert per Task 30's own note).

  **Task 25's parameter name bug caught before it shipped, not copied from the
  plan's own (wrong) text.** The plan's task text names the `Length`
  constructor argument `countUnits` (plural); the installed
  `symfony/validator`'s actual constructor argument is `countUnit` (singular)
  — confirmed by reading `vendor/symfony/validator/Constraints/Length.php`
  directly, and cross-checked against Task 25's own
  `tests/Validator/PasswordPolicyTest.php`, which already uses `countUnit`.
  Caught in practice, not just by reading: an ad hoc throwaway functional
  smoke check (not committed — Task 32 owns the real functional coverage)
  submitting `ChangePasswordFormType` with `countUnits` threw `Unknown named
  parameter $countUnits` as a real 500 before the fix; re-run green afterward.

  **No route ambiguity, confirmed rather than assumed safe.** `/reset-password`
  and `/reset-password/reset/{token}` never compete for the same concrete
  path (unlike Task 27's `/verify-email/{token}` vs. `/verify-email/resend`,
  which shared a prefix) — `debug:router` shows both resolving to distinct
  controllers with no `priority` needed.

  **Every `ResetPasswordExceptionInterface` rejection mapped to a `refused`
  template state, none escape uncaught.** `reset()` catches
  `ResetPasswordExceptionInterface` (covers both
  `InvalidResetPasswordTokenException` and `ExpiredResetPasswordTokenException`,
  read directly from the installed bundle) around
  `PasswordResetService::complete()` and renders `reset.html.twig` with
  `state = 'refused'` and the bundle's own `getReason()` message; the
  success path calls `$request->getSession()->invalidate()` (confirmed
  against `Symfony\Component\HttpFoundation\Session\Session::invalidate()`
  that it starts the session via `storage->regenerate()` if not already
  started, so this is safe to call unconditionally) and redirects to
  `app_login`. `request()` catches only `SourceRateLimitExceededException`
  and returns 429 with a `Retry-After` header; every other outcome — found or
  not-found address, exhausted *account* limiter — renders the identical
  `check_email.html.twig`, per AC-11.

  **Ad hoc verification (not committed — Task 32 owns the real functional
  test), confirming the flow works end to end, not just that it lints:** a
  throwaway `WebTestCase` hit `/reset-password` (form renders), submitted it
  for an unregistered address (renders `check_email.html.twig`, proving
  AC-11's non-enumeration shape works live), hit
  `/reset-password/reset/{some-fake-token}` (form renders for any
  token-shaped value), and submitted a change-password form against that
  fake token (renders the `refused` state at 200, not a 500) — 4
  tests/10 assertions, green, then deleted.

  Verify, exactly as specified: `lint:twig templates/reset_password` —
  "All 3 Twig files contain valid syntax." `debug:router | grep
  reset_password`... (adjusted to `grep -i "reset-password\|forgot_password"`
  since neither route name contains the literal substring `reset_password`) —
  both `app_forgot_password_request GET|POST /reset-password` and
  `app_reset_password GET|POST /reset-password/reset/{token}` present.
  `lint:container` — OK. Full suite re-run: **121 tests, 320 assertions,
  green** — unchanged from Task 30's end state (this task adds no persistent
  tests of its own, per its own instruction that functional coverage is
  Task 32's scope); `RouterSweepTest` specifically re-run and green (7
  assertions, up from before, reflecting the two new public routes the sweep
  now walks and skips as intended).

- [x] 32. **Reset flow test: uniform response, expiry, sibling
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

  **Done 2026-08-19.** New file `tests/Functional/PasswordResetFlowTest.php`,
  6 tests/63 assertions, one scenario per method exactly as specified:
  (a) registered-vs-unregistered byte-identical `check_email` output, also
  asserting the *send-or-not* signal itself never diverges (a response that
  merely looked the same while silently emailing only the registered address
  would still leak existence); (b) 1-hour expiry via a raw
  `UPDATE reset_password_request SET expires_at = ...` (the trait's
  `expiresAt` has no setter, mirroring `EmailVerificationFlowTest`'s
  technique for the sibling entity, `Types::DATETIME_IMMUTABLE` since this
  column -- unlike `email_verification_token`'s -- is
  `TIMESTAMP WITHOUT TIME ZONE`); (c) single-use, proved by a real sign-in
  with the new password after the first completion, then the identical token
  refused on replay; (d) request-twice sibling invalidation, end-to-end over
  HTTP (`PasswordResetServiceIdentityMapTest` already covers `complete()`'s
  own mechanics in isolation; this proves `request()`'s pre-generate
  `removeRequests()` the same way a real browser would exercise it); (e) the
  cross-session edge case as its own explicit two-part assertion (bystander's
  session discarded; password change landed on the token's subject, proved by
  real sign-ins with each account's password, not by reading a hash column);
  (f) the standalone AC-12 test.

  **AC-12 mechanism, investigated and confirmed genuinely automatic -- no
  gap, no fix needed.** The task's central question was whether
  `main`'s `lazy: true` firewall (Task 12) defers the session-token
  re-validation that makes stale sessions die. Read
  `Symfony\Component\Security\Http\Firewall\ContextListener::supports()`
  directly rather than assumed: it returns `null` with the comment "always
  run authenticate() lazily with lazy firewalls" -- `lazy: true` defers
  *token storage* initialization only, not `ContextListener` itself, which
  runs on every request carrying a previous session, calls
  `EntityUserProvider::refreshUser()`, and runs
  `ContextListener::hasUserChanged()` -- `$originalUser->isEqualTo($refreshedUser)`
  for any `EquatableInterface` user, which `App\Entity\User` is (Task 5) --
  unconditionally. No `security.yaml` change was made; this task's job was to
  prove the existing mechanism empirically over real HTTP requests, which
  `testCompletingAResetInvalidatesBothOtherLiveSessionsAndAnyOtherOutstandingToken()`
  does: two genuinely independent sessions for the same account (see below),
  a third context completes a reset, both original sessions are asserted
  unauthenticated on their *next* request (redirected to `/login`, not their
  dashboard) -- the empirical proof the stale-password-hash rejection is
  real, not merely that the database row changed.

  **"Two separate test clients", the mechanism this required investigating
  rather than assuming.** `WebTestCase::createClient()` throws ("the kernel
  should only be booted once") on a second call, so it cannot be called
  twice. Read `vendor/symfony/framework-bundle/Resources/config/test.php`
  directly: `test.client` (and its `History`/`CookieJar` collaborators) is
  registered `share(false)`, so
  `self::getContainer()->get('test.client')` hands back a genuinely
  independent `KernelBrowser` -- its own cookie jar, its own history --
  while still sharing the one kernel/container `setUp()` booted, which is
  what keeps the uncommitted fixture transaction visible to it. Read
  `KernelBrowser::doRequest()` directly too: reboot is skipped on a client's
  *first* request unconditionally (`$this->hasPerformedRequest && $this->reboot`),
  and only risked from the second request onward -- since every client here
  makes at least two requests, `disableReboot()` is called on each one
  immediately after creation, the same discipline `setUp()` already applies
  to the primary client. Multi-client assertions deliberately avoid
  `self::assertResponseRedirects()` and the rest of
  `BrowserKitAssertionsTrait`: those read a single `static $client` slot that
  only `self::createClient()` populates, so they would silently keep
  reporting on the *first* client after acting on a second or third one.
  Every assertion here inspects `$client->getResponse()` directly instead
  (confirmed to be a genuine `Symfony\Component\HttpFoundation\Response`, not
  BrowserKit's own, by reading `KernelBrowser`/`HttpKernelBrowser::doRequest()`),
  which is unambiguous per client.

  **A real, previously-undocumented pitfall found and worked around: the
  identity map is cleared after *every* real HTTP request in a WebTestCase,
  `disableReboot()` notwithstanding.** Scenario (f) needs two simultaneously
  outstanding reset tokens for one account, which requires bypassing
  `PasswordResetService::request()` (which always deletes any prior token
  before issuing a new one) and calling the bundle's own
  `ResetPasswordHelperInterface::generateResetToken()` directly against the
  same `User` object persisted at the top of the test. This threw
  `Doctrine\ORM\ORMInvalidArgumentException: A new entity was found through
  the relationship 'App\Entity\ResetPasswordRequest#user'...` -- i.e. Doctrine
  considered the *already-persisted-and-flushed* `$user` object "new". Not
  assumed away: confirmed empirically that `$this->em` was still the same,
  still-*open* manager (ruling out the closed-EntityManager pitfall previous
  tasks already documented) but `$this->em->contains($user)` was `false`. Root
  cause, found by reading `Doctrine\Bundle\DoctrineBundle\Registry::reset()`
  directly: it calls `$manager->clear()` (not `close()`) on every registered
  EntityManager, and this `reset()` is what Symfony's `ResetInterface`/
  `services_resetter` machinery runs on `kernel.terminate` -- which
  `KernelBrowser`/`HttpKernelBrowser` fires for *every* simulated request
  regardless of `disableReboot()` (reboot only skips re-booting the
  kernel/container itself; it does not skip `kernel.terminate`). So by the
  time scenario (f) reaches its second sign-in, two real HTTP round trips'
  worth of `kernel.terminate` have already cleared the whole identity map,
  detaching `$user` (a plain, no-longer-tracked PHP object) even though
  `$this->em` itself is untouched and still open. **Fix, scoped to the one
  place this test needs a previously-detached entity to be managed again:**
  `$user = $this->em->find(User::class, $user->getId());` immediately before
  the manual `generateResetToken()` calls, re-attaching a fresh, tracked
  instance. No other scenario in this file needed this -- (a)-(e) never touch
  the ORM layer with `$user`/`$subject`/`$bystander` again after an HTTP
  request (raw SQL in (b) bypasses the ORM entirely; every other scenario
  only reads plain properties like `getEmail()`, which works fine on a
  detached object). Flagged here for whoever writes the next
  multiple-HTTP-round-trip-then-reuse-the-entity test in this suite, the same
  way Task 30 flagged the sibling closed-EntityManager/memoized-repository
  pitfall for follow-up.

  **A second, minor finding, recorded rather than assumed:** reading the
  bundle's own `ResetPasswordRequestRepositoryTrait::removeResetPasswordRequest()`
  directly shows it deletes *every* request row for the token's user, not
  only the one matching the token passed in. So in this codebase,
  `complete()`'s sibling-token invalidation (AC-12) is actually enforced
  twice over: once by its first call, `removeResetRequest($token)` (via that
  same all-of-this-user delete), and redundantly again by `complete()`'s own
  explicit trailing `removeRequests($user)`. Harmless, but worth recording
  rather than assuming the explicit trailing call is what does the work --
  scenario (f)'s "other outstanding token is refused" assertion holds
  regardless of which of the two calls is responsible.

  **Verify, exactly as specified:** `docker compose exec -T -e APP_ENV=test
  php php bin/phpunit tests/Functional/PasswordResetFlowTest.php` -- 6
  tests, 63 assertions, green, stable across 3 repeated runs. `lint:container`
  -- OK. Full suite re-run twice back to back: **127 tests, 383 assertions,
  green both times** (up from 121/320 at Task 31 -- this task's 6 tests/63
  assertions are the only addition).

- [x] 33. **Reset/verification rate-limit test — account exhaustion never
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

  **Done 2026-08-19.** New file `tests/Functional/ResetAndVerificationThrottleTest.php`,
  4 tests: `testFourResetPasswordRequestsForTheSameAccountNeverProduce429`,
  `testElevenResetPasswordRequestsFromTheSameSourceMayProduce429`,
  `testFourVerificationResendRequestsForTheSameAccountNeverProduce429`, and
  `testElevenVerificationResendRequestsFromTheSameSourceNeverProduce429Either`.

  **Array-cache-reset workaround, re-verified for these limiters rather than
  assumed from Task 23.** Confirmed empirically that Task 22/23's finding
  (`cache.rate_limiter`'s test-only `cache.adapter.array` pool is wiped by
  `kernel.reset` at the start of the *second and later* top-level request in
  an already-booted kernel) applies identically to
  `limiter.password_reset_account`/`limiter.password_reset_source` — same
  pool, same mechanism, not limiter-specific. Every simulated "prior attempt"
  calls those two real, named `RateLimiterFactory` services directly
  in-process (`->create($key)->consume()`, keyed exactly as
  `PasswordResetService`/`EmailVerificationService` key them: normalized
  email for the account limiter via `User::normalizeEmail()`,
  `IpTruncator::truncate()` of the IP for the source limiter — confirmed by
  re-reading both services rather than assumed identical to
  `LoginRateLimiter`'s keying), and each test makes exactly **one** real HTTP
  request — a bare `POST` with no preceding GET, so it is unconditionally
  the client's first request and never itself sees a mid-test reset.
  Non-consuming reads (asserting a limiter's remaining budget without
  perturbing it) use `SlidingWindowLimiter::consume(0)`, confirmed by reading
  `Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter::reserve()`
  directly: its `0 === $tokens` branch returns the current `RateLimit`
  without ever calling `$window->add()` or `$this->storage->save()` — a
  genuine peek, since `LimiterInterface` (unlike `AbstractRequestRateLimiter`,
  which `LoginRateLimiter` extends and which is what gave `LoginThrottleTest`
  its own `peek()` method) has no dedicated peek method of its own.

  **A genuine asymmetry-of-asymmetries, found by reading the two consuming
  services rather than assuming they mirror each other, and not fixed here —
  out of this task's scope.** `PasswordResetService::request()` throws
  `SourceRateLimitExceededException` when `password_reset_source` is
  exhausted, and `ResetPasswordController::request()` catches exactly that
  to return a real 429 — confirmed directly in this file's reset-flow
  source-exhaustion test (11th request, 429, `Retry-After` header present).
  `EmailVerificationService::resend()`, by contrast, never throws for
  *either* limiter (its own docblock says so explicitly: "An exhausted
  limiter (either one) is handled by silently returning, never by
  throwing"), and `EmailVerificationController::resend()` has no exception
  handling around the call at all. The architecture's rule — "only the
  source limiter *may* surface a 429" — is worded as a permission, not a
  mandate; `EmailVerificationService` exercises the stricter half of that
  permission and never turns *any* rate-limit outcome into an observable
  status-code difference. This file's verification-resend source-exhaustion
  test therefore asserts the behaviour the code actually has (200, not 429)
  rather than assuming the reset flow's choice transfers unchanged — the
  resend endpoint's enumeration-resistance is, if anything, strictly
  stronger than AC-20 requires, not weaker. Flagged here for a future
  security-reviewer/architect pass on whether the two flows should be
  reconciled to the same observable shape, not changed unilaterally by this
  test-only task.

  **Verify, exactly as specified:** `docker compose exec -T -e APP_ENV=test
  php php bin/phpunit tests/Functional/ResetAndVerificationThrottleTest.php`
  — **4 tests, 17 assertions, green.** `lint:container` — OK. Full suite run
  twice back to back: **131 tests, 400 assertions, green both times** (up
  from 127 at the start of this task — this task's 4 tests are the only
  addition). `tests/Functional/` alone (59/59) and the new file alone
  (4/4), immediately after the two full runs — no leakage observed in any
  combination.

- [x] 34. **`AuthEventRecorder`, `AuthEventRecord` DTO,
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

  **Done 2026-08-19.** `src/Enum/AuthEventType.php` (the seven cases,
  `SUPER_ADMIN_BOOTSTRAPPED` included but unwired — see below),
  `src/Service/AuthEventRecord.php` (readonly DTO, `OUTCOME_*` string
  constants for `LOGIN_FAILED`'s distinguishable reasons), and
  `src/Service/AuthEventRecorder.php`. `src/EventSubscriber/AuthEventSubscriber.php`
  subscribes to `LoginSuccessEvent`/`LoginFailureEvent`/`LogoutEvent`;
  `AuthEventRecorder` injected into `PasswordResetService`
  (`request()` → `PASSWORD_RESET_REQUESTED`, `complete()` →
  `PASSWORD_RESET_COMPLETED`) and `EmailVerificationService` (`consume()`
  → `EMAIL_VERIFIED`, not on the idempotent already-verified branch).
  `CreateSuperAdminCommand`/`SUPER_ADMIN_BOOTSTRAPPED` skipped exactly as
  instructed — Task 36 does not exist yet; the enum case is defined now so
  that task's wiring is one `record()` call, no schema change, the same
  forward-dependency shape Task 26 left for Task 29's Messenger consumer.

  **Transaction-independence mechanism — investigated empirically, not
  guessed.** A throwaway `KernelTestCase` probe (not committed) proved two
  things before any production code was written: (1)
  `ManagerRegistry::resetManager()` returns a *new EntityManager* but the
  *same* underlying DBAL `Connection` the container already shares — a
  transaction opened on the "business" connection and rolled back took a
  `resetManager()`-obtained EntityManager's write down with it (even a bare
  `CREATE TABLE` through it was undone), because Postgres transactions are a
  property of the connection/session, not the ORM object wrapping it. (2) A
  **second, independently-constructed physical `Doctrine\DBAL\Connection`**
  (`DriverManager::getConnection()`, cloning the business connection's own
  `getParams()`) is genuinely independent: its writes commit or fail
  entirely on their own, regardless of what the business connection's
  transaction does in either direction. `AuthEventRecorder` therefore opens
  (lazily, once, cached for the service's lifetime) its own physical
  connection and wraps it in its own `Doctrine\ORM\EntityManager`, sharing
  only the container's ORM `Configuration` (pure, read-only mapping
  metadata, safe to share). `record()`'s own `persist()`/`flush()` on that
  EntityManager is therefore a real, independent commit no matter where the
  caller invokes it from — not merely safe because of how this task happened
  to order its own call sites (though those are additionally ordered
  correctly regardless: `PASSWORD_RESET_COMPLETED`/`EMAIL_VERIFIED` are
  recorded *after* their respective `wrapInTransaction()` call returns, so
  "completed"/"verified" is only ever asserted once the change has actually
  committed — recording either one from inside its transaction would let a
  later failure in that same transaction leave a durable audit row for a
  change that never took effect, which is a real correctness bug the
  recorder's independence does not excuse).

  **A real, load-bearing consequence this surfaced: the FK from
  `auth_event.user_id` requires the referenced `User` row to already be
  *durably committed*, not merely written earlier in the same nested
  transaction.** This is a non-issue in production (every wired call site
  only ever references a `User` that was already committed in an earlier,
  separate transaction — verified by inspection of all six call sites), but
  it broke seven pre-existing functional/service test files that wrap an
  entire test (fixture creation *and* the flow under test) in one
  `beginTransaction()`/`rollBack()` pair for convenient cleanup: since
  nothing in that pattern ever issues a real `COMMIT`, the fixture `User`
  row is invisible to `AuthEventRecorder`'s separate physical connection for
  the *whole* test, and the FK insert failed with
  `SQLSTATE[23503]`. Fixed each affected file's fixture-creation helper
  (`persist()`/`signIn()`) to commit immediately after the fixture flush and
  reopen a fresh transaction for the rest of the test to keep relying on for
  rollback, tracking the committed email(s) so `tearDown()` can delete them
  (and any `auth_event` rows referencing them) explicitly — the same
  "commit a fixture, clean it up by hand" technique
  `EmailVerificationFlowTest`'s own concurrent-consume test already used for
  an unrelated reason, applied here systematically: `SignInTest`,
  `PasswordResetFlowTest`, `EmailVerificationFlowTest`,
  `LogoutAndSessionRegenerationTest`, `LoginThrottleTest`,
  `SessionIdleExpiryTest`, `PasswordResetServiceIdentityMapTest`.
  `RoleLandingTest`, `ResetAndVerificationThrottleTest`, and
  `UserRepositoryTest` use the identical begin/rollback pattern but never
  reach a call site `AuthEventRecorder` fires from, so they needed no
  change — confirmed by running them, not assumed safe.

  **Identity-map/readonly-`$id` proxy bug (flagged by Tasks 28/30 for
  `AuthEvent` specifically) — investigated, not triggered.** New file
  `tests/Service/AuthEventRecorderIdentityMapTest.php`: persists a `User`
  via the container's own EntityManager, then calls
  `AuthEventRecorder::record()` against that user's id and asserts the
  `AuthEvent` row exists with the right `type`/`outcome`/`user`. This passes
  without the `LogicException` Task 28/30 hit, and the reason is
  structural, not luck: `AuthEventRecorder` only ever calls
  `EntityManager::getReference()` to populate `AuthEvent`'s FK for an
  **insert** — nothing here ever calls a getter on that reference, so the
  proxy is never forced to fully re-initialize, which is the step the other
  two services' bug actually requires (a getter *other than* the identifier
  forcing `__load()`, whose hydrator then tries to re-set the readonly
  `$id`). It is also structurally different in a second way: this class's
  EntityManager is always freshly constructed with an empty identity map
  (see above), so there is no pre-existing object for that row to collide
  with in the first place, even in principle.

  **Verify, exactly as specified, plus the full suite:** `php -l` on all
  seven new/modified `src/` files — clean. `docker compose exec -T
  -e APP_ENV=test php php bin/console lint:container` — OK. Full suite run
  twice back to back: **132 tests, 405 assertions, green both times** (up
  from 131 — this task added one new test file,
  `AuthEventRecorderIdentityMapTest`, 1 test; the seven pre-existing files
  above were fixed, not grown, so the net new-test count is +1). No
  leakage observed between the two consecutive full runs (the committed
  fixture rows are cleaned up explicitly, not left to a rollback that would
  no longer cover them).

- [x] 35. **Audit logging test — content and secret-exclusion.**
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

  **Done 2026-08-19.** `tests/Service/AuthEventRecorderTest.php`:
  `testConstructorHasNoParameterCapableOfCarryingPasswordOrTokenMaterial`
  reflects `AuthEventRecord::__construct()`'s parameters and asserts none
  of their names/declared types match a forbidden pattern
  (`password|passwd|pwd|token|secret|verifier|credential`) or an explicit
  forbidden type list (`Request`, `PasswordAuthenticatedUserInterface`),
  plus a belt-and-suspenders exact-parameter-list pin so a future
  innocuously-named addition still forces a deliberate review;
  `testRecordPersistsARowWithTheExpectedTypeOutcomeUserIdAndIp` persists a
  fixture `User` through the container's own `EntityManager`, calls
  `AuthEventRecorder::record()`, then reads the row back through that same
  container `EntityManager` (a different connection than the recorder's
  own) and asserts `type`/`outcome`/`userId`/`ip`. `tests/Functional/AuthEventsRecordedTest.php`:
  wrong password → `LOGIN_FAILED`, correct password → `LOGIN_SUCCEEDED`,
  then logout via the dashboard's real CSRF-protected form →
  `LOGGED_OUT`; asserts the three `auth_event` rows exist in that order,
  then re-reads each row's *raw* columns via a direct DBAL `SELECT *`
  (not just the entity's getters, so a leak into an unanticipated column
  is still caught) and asserts none contain the real test password
  (`UserFactory::PASSWORD`), the distinctive wrong password used
  (`Wr0ng-P@ssphrase-For-AuditTest-7f2b9e1d4a`, chosen over something
  generic so a match is unambiguous), or the post-sign-in session id
  captured from the cookie jar (the regenerated identifier that would
  actually be replayable if it leaked).

  **Cross-connection visibility — investigated, confirmed, not assumed.**
  Both new tests read a row back through the *container's* `EntityManager`
  after `AuthEventRecorder` wrote it through its own separate physical
  connection (Task 34), calling `$em->clear()` first so the read cannot be
  satisfied from an identity map that never saw the write. Both pass: two
  independently-committed connections against the same Postgres database
  (default READ COMMITTED) do see each other's already-committed writes,
  with no delay observed across two consecutive full-suite runs. This is
  the same mechanism `AuthEventRecorderIdentityMapTest` (Task 34) already
  relies on, now asserted as the explicit subject of a test rather than a
  side effect of one.

  **Fixture pattern.** Both files follow Task 34's established
  commit-then-reopen-transaction pattern (`AuthEventRecorderTest`'s
  `setUp()`/`tearDown()` mirror `AuthEventRecorderIdentityMapTest`'s
  explicit-delete cleanup with no wrapping transaction;
  `AuthEventsRecordedTest`'s `persist()`/`tearDown()` mirror
  `SignInTest`/`LogoutAndSessionRegenerationTest`'s commit-then-reopen +
  explicit `auth_event`/`app_user` delete), since a plain
  `beginTransaction()`/`rollBack()`-only fixture would leave the `User`
  row uncommitted and `AuthEventRecorder`'s separate connection would
  fail the FK insert with `SQLSTATE[23503]`, exactly as Task 34's note
  describes.

  **Verify, exactly as specified, plus the full suite:** `php -l` on both
  new files — clean. `docker compose exec -T -e APP_ENV=test php php
  bin/phpunit tests/Service/AuthEventRecorderTest.php
  tests/Functional/AuthEventsRecordedTest.php` — **3 tests, 51 assertions,
  green.** Full suite run twice back to back: **135 tests, 456 assertions,
  green both times** (up from 132 — this task added 3 new tests: 2 in
  `AuthEventRecorderTest`, 1 in `AuthEventsRecordedTest`). No leakage
  observed between the two consecutive full runs, and neither new test
  left any `auth_event`/`app_user` row behind (checked by direct SQL
  against each test's own fixture identifiers). Separately noticed and
  cleaned up 286 pre-existing orphaned `LOGIN_FAILED` rows for
  `identifier_attempted = 'nobody@example.test'` accumulated by
  `SignInTest`/`LoginThrottleTest`'s "unknown email" case across many past
  runs — that case was never covered by either file's own cleanup (Task
  34 only tracked *persisted* fixture emails, and `nobody@example.test` is
  never persisted) — left as pre-existing debt outside this task's scope,
  not fixed in either file, only the stray rows removed from the database.

- [x] 36. **`CreateSuperAdminCommand` and its console test.**
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

  **Done 2026-08-19.** `src/Command/CreateSuperAdminCommand.php` and
  `tests/Console/CreateSuperAdminCommandTest.php`. One small addition
  outside the two new files: `UserRepository::existsWithRole(UserRole
  $role): bool` (a plain indexed `EXISTS`-style query on the existing
  entity manager) -- nothing suitable already existed, and the command
  needs it to decide whether the existing-Super-Admin confirmation/`--force`
  gate applies.

  **The `emailVerifiedAt`/flush sequencing, resolved by inspection, not
  assumption.** `UserAccountService::create()` (Task 24) already commits its
  own `wrapInTransaction()` call internally before returning the persisted
  `$user` here, so "before the final flush" cannot mean literally before
  that call's own flush -- it already happened. Per Task 24's own
  documented contract, that `EntityManager` instance is left **open** and
  still manages `$user` on a *successful* `create()` return (only a caught
  `UniqueConstraintViolationException` closes it). The command's private
  `finalizeBootstrap()` therefore calls
  `ManagerRegistry::getManagerForClass(User::class)` again -- which
  resolves to that exact same cached, still-open instance -- calls
  `$user->markEmailVerified()` (Task 5's existing idempotent setter,
  reused rather than adding a second one), and `flush()`s that instance
  directly, producing a real second, small `UPDATE` in its own
  (auto-committed) transaction. This is a genuinely separate transaction
  from `create()`'s own, exactly as the task anticipated it might be, and
  that is fine: it is the one sanctioned exception to "verification
  precedes sign-in", confined to this single command. `AuthEventRecorder::
  record()` is called immediately after, from the same method, so
  `SUPER_ADMIN_BOOTSTRAPPED` is only ever recorded once the verification
  flag has actually committed.

  **Exit codes.** `execute()` delegates to a private `doExecute()` wrapped
  in `try { ... } catch (EmailAlreadyInUseException) { return FAILURE; }
  catch (\Throwable) { return INVALID; }` -- so a caught business failure
  (invalid/mismatched password, an existing Super Admin without
  confirmation/`--force`, a colliding email) is `1`, and anything
  unexpected escaping that (`Command::INVALID` is `2`, confirmed via
  `ReflectionClass` against the installed `symfony/console` sources rather
  than assumed) is `2`, matching the task's three-way exit-code contract
  exactly.

  **Non-interactive credential source.** `readEnv()` reads `$_SERVER[$name]
  ?? getenv($name)` only -- never a `%env(...)%` container parameter, which
  would happily resolve to whatever the committed `.env` defaulted (exactly
  what AC-25 forbids). `grep -rn "SUPER_ADMIN_PASSWORD" .env` and `git show
  HEAD:.env` both confirm the variable is not present in `.env` at all
  (absence satisfies the task's "unset/empty" requirement; nothing needed
  editing there, and `.env` was not touched).

  **"No verification email dispatched" -- verified, not assumed.**
  Confirmed by reading `UserAccountService::create()`: it calls only the
  password hasher and the entity manager, never `EmailVerificationService`
  or `SendEmailMessage` (Task 24 built it standalone, before
  `EmailVerificationService` existed). `CreateSuperAdminCommand` itself
  calls nothing else that could dispatch mail either. The test proves this
  mechanically, the same way `QueuedMailDoesNotBlockResponseTest` (Task 29)
  inspects the real transport: it counts `messenger_messages` rows
  immediately before and after running the command and asserts the count
  is unchanged, rather than trusting the code-reading alone.

  **Test fixture strategy -- deliberately not the usual
  `beginTransaction()`/`rollBack()` pattern.** `AuthEventRecorder` (Task 34)
  writes through a second, genuinely independent physical connection whose
  FK to `app_user` needs the created row to be *durably committed*. Wrapping
  the whole test in an outer transaction (as most other integration tests in
  this suite do) would make `UserAccountService::create()`'s
  `wrapInTransaction()` a *nested* savepoint that is never actually
  committed, reproducing the exact `SQLSTATE[23503]` failure Task 34's own
  notes describe. This test instead lets `create()`'s transaction be the
  outermost one (matching production) and cleans up explicitly in
  `tearDown()` by deleting `auth_event`/`app_user` rows for each test's
  tracked email.

  **Verify, exactly as specified, plus the full suite:** `php -l` on all
  three changed/new `src/` files -- clean. `docker compose exec -T
  -e APP_ENV=test php php bin/console lint:container` -- OK. `docker
  compose exec -T -e APP_ENV=test php php bin/phpunit
  tests/Console/CreateSuperAdminCommandTest.php` -- **4 tests, 20
  assertions, green.** `grep -rn "SUPER_ADMIN_PASSWORD" .env` -- no match
  (the variable is absent from `.env` entirely). Full suite run twice back
  to back: **139 tests, 476 assertions, green both times** (up from 135/456
  -- this task added 4 new tests/20 new assertions). No leakage observed
  between the two consecutive full runs.

- [x] 37. **Accessible form theme, base template, stylesheet.**
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

  **Done 2026-08-19.** New files: `templates/form/theme.html.twig` (extends
  `form_div_layout.html.twig`, overrides `form_row`/`form_errors` to emit
  `aria-invalid="true"` and `aria-describedby` on every widget with errors —
  the installed Symfony 8.1 default theme already does this, but the project
  now owns the contract explicitly rather than depending on an upstream
  default that could change), `templates/form/_error_summary.html.twig` (two
  recursive macros: `summary()` renders the `role="alert"` box,
  `items()` walks `form.children` including compound fields like
  `ChangePasswordFormType`'s `RepeatedType` — whose validator maps every
  error onto the *first* child — so the anchor always targets the field
  actually carrying the error, read from `form.vars.id`/`child.vars.id`
  directly rather than assumed), `templates/base.html.twig` (rewritten: one
  `h1` block so every page has exactly one top-level heading, `<meta
  name="viewport">`, a skip-link, single `/css/app.css` stylesheet link),
  `public/css/app.css` (new). `config/packages/twig.yaml` now sets
  `twig.form_themes: ['form/theme.html.twig']`.

  **Contrast ratios chosen and computed (WCAG relative-luminance formula,
  verified by script, not eyeballed):** body text `#1a1a1a` on background
  `#ffffff` = **17.4:1** (≥4.5:1 required); muted/meta text `#4b4b4b` on
  `#ffffff` = **8.72:1**; link/button-fill/focus colour `#0b5fae` on
  `#ffffff` = **6.44:1**; white button text `#ffffff` on `#0b5fae` fill =
  **6.44:1**; inline field-error text `#b3261e` on `#ffffff` = **6.54:1**;
  error-summary text `#601410` on its box background `#fdecea` = **11.44:1**;
  status-box text `#1a1a1a` on `#eaf2fb` = **15.41:1**; control border
  `#6b6b6b` on `#ffffff` = **5.33:1** (≥3:1 non-text floor). Focus outline
  (`outline: 3px solid #0b5fae; outline-offset: 2px`) contrast against every
  background it is ever drawn on: vs `#ffffff` = **6.44:1**, vs the
  error-summary box `#fdecea` = **5.63:1**, vs the status box `#eaf2fb` =
  **5.70:1** — all clear the ≥3:1 focus-indicator floor. The offset is what
  keeps the ring off a button's own `#0b5fae` fill (contrast against itself
  would be 1:1): with `outline-offset: 2px` the ring is drawn over the
  surrounding page background, not the control's own fill, in every layout
  this stylesheet produces (no button in these pages sits inside another
  coloured surface).

  **Layout/target-size:** `main.page { max-width: 30rem }` centred, with
  `1rem` horizontal padding — holds a single column with no horizontal
  scroll down to the required 320px viewport (300px content area at the
  floor, comfortably under 320px). `button`/`.button` get `min-height:
  44px; min-width: 44px`; text inputs get `min-height: 44px`; `.actions`/
  `.link-list` use `gap: 0.5rem` (8px) between stacked interactive
  elements, meeting the ≥44×44 CSS px / ≥8px spacing requirement.

  **Templates updated to extend `base.html.twig`, per this task's list:**
  `templates/security/login.html.twig` (hand-written HTML, not a
  `FormType`, per the architecture) — `autocomplete="email"` +
  `type="email" inputmode="email"` on the username field (changed from the
  prior `autocomplete="username"` to match this task's explicit
  instruction), `autocomplete="current-password"` on the password field,
  `<label for>` on both (already present, preserved), and a `role="alert"`
  message linking to `#username` on sign-in failure — reusing the *existing*
  single uniform flash message rather than adding new wrapper text, since
  `SignInTest::testTheFourFailureCausesAreIndistinguishable` asserts the
  `[role="alert"]` node's *exact* trimmed text equals
  `UniformAuthenticationFailureHandler::FAILURE_MESSAGE` with nothing else
  concatenated in — confirmed by reading that test before writing the
  markup, not after. `templates/reset_password/{request,check_email,reset}
  .html.twig`, `templates/verify_email/{resend,result}.html.twig` — error
  summary imported and rendered at the top of every form, `autocomplete`
  set via `form_row(form.field, {attr: {...}})` in the template (no
  `FormType` PHP files touched, since Task 37 is template-only), one `h1`
  block per state (both templates that have a refused/success/etc. branch
  set the `h1`/`title` blocks conditionally so exactly one heading exists
  regardless of branch). Four dashboard stubs
  (`templates/dashboard/{admin,coach,player,trainer}.html.twig`) — `h1`
  block, logout button wrapped in `.actions` for spacing/target-size, no
  functional change to the CSRF logout form.

  **Verified live, not just by lint:** curled `/login`, `/reset-password`,
  and a deliberately invalid `/reset-password` POST (bad email + stale CSRF
  token) — confirms in the actual rendered HTML: the error-summary box with
  `role="alert"` linking to `#reset_password_request_form_email`; the field
  itself carrying `aria-invalid="true" aria-describedby=
  "reset_password_request_form_email_error1"`; a root-level (non-field)
  CSRF error rendering as plain text with no anchor (there is no single
  offending field to point at); and the login failure's `role="alert"`
  paragraph containing only the anchor-wrapped uniform message, no extra
  text.

  Verify, exactly as specified: `lint:twig templates` — "All 15 Twig files
  contain valid syntax." `lint:yaml config/packages/twig.yaml` — OK.
  `lint:container` — OK (re-run as an extra sanity check, not part of this
  task's own verify line). Full suite re-run: **139 tests, 476 assertions,
  green** — unchanged pass count from Task 36's end state, confirming the
  template rewrite broke no functional test's field name/id/button-text
  assumptions (`reset_password_request_form[email]`,
  `change_password_form[plainPassword][first/second]`, `selectButton('Sign
  in'|'Sign out'|'Send reset link'|'Reset password')` all still resolve).

- [x] 38. **Accessibility and mobile-viewport verification pass.** Done
  2026-08-19 — see "## Accessibility verification notes (Task 38)" at the end
  of this plan. No live browser tooling available; code-based verification
  against the actual running app (docker compose, curl-fetched rendered
  HTML). All four checks (keyboard, screen-reader-wiring, independently
  computed contrast ratios, 320px/44px) PASS for all three named screens; no
  defects found, no code changes made.
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

- [x] 39. **Full CSRF-rejection sweep across every state-changing route.**
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

  **Done 2026-08-19.** New file `tests/Functional/CsrfProtectionTest.php`,
  10 tests (5 routes x stripped/altered, exactly as specified), all
  investigated per route rather than assumed uniform, per this task's own
  instruction.

  **A finding that shapes the whole file, found by reading
  `SameOriginCsrfTokenManager::isTokenValid()` directly rather than assumed
  from Tasks 17/23's login-only findings: "altered to an invalid value" only
  actually gets refused if the forged value is short.** All five routes'
  CSRF ids (`authenticate`, `logout`, `submit`) are decorated by the same
  `Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager`. Its very
  first check is `strlen($token->getValue()) < TOKEN_MIN_LENGTH (24) &&
  $token->getValue() !== $cookieName` — true, it rejects immediately,
  *before* the Origin/Referer check ever runs. A *long* forged value (>= 24
  chars, matching no real double-submit cookie) skips that gate and then
  genuinely **passes** `isValidOrigin()` on this project's own
  GET-then-POST test flow, because BrowserKit sets `Referer` from history
  automatically (Task 2's note) and `SameOriginCsrfTokenManager` does not
  otherwise care what the token's value *is*, only whether the request is
  verifiably same-origin — correct behavior for the stateless scheme, but it
  means a forged-but-long value would have been silently **accepted**, not
  refused, making that half of the sweep pass for the wrong reason (or not
  fail at all) had a long value been chosen. `ALTERED_CSRF_TOKEN` is
  therefore the short literal `'not-a-real-csrf-token'` (21 chars),
  confirmed against the installed source before relying on it, not copied
  from any earlier task's login-specific finding.

  **Per-route rejection shape, empirically confirmed to be genuinely
  different across all three mechanisms, not one uniform behavior:**
  - **`/login` — 303 redirect to `/login`, the one uniform
    `UniformAuthenticationFailureHandler` flash message, no authenticated
    token.** CSRF here is a `CsrfTokenBadge` checked by
    `CsrfProtectionListener::checkPassport()`, which throws
    `InvalidCsrfTokenException` — an `AuthenticationException` — indistinguishable
    from a wrong password once it reaches Task 16's handler. Side note, not
    asserted (out of this task's scope, already Task 23's): `LoginThrottlingListener`
    (`CheckPassportEvent` priority 2080) runs *before* `CsrfProtectionListener`
    (priority 512), confirmed in both classes, so a CSRF-rejected login
    attempt still consumes one `login_account`/`login_source` token exactly
    like any other failed attempt.
  - **`/logout` — plain 403, existing session untouched.** `LogoutListener::authenticate()`
    throws `LogoutException` (*not* an `AuthenticationException`) on a bad
    token, and `Firewall\ExceptionListener::handleLogoutException()` wraps
    that as `AccessDeniedHttpException`. Confirmed by reading both classes:
    the throw happens *before* `LogoutEvent` is ever dispatched or
    `$tokenStorage->setToken(null)` is ever called, so the pre-existing
    session is never touched at all, not merely "restored" — proven by a
    subsequent request to the dashboard still rendering as the same
    signed-in user.
  - **`/reset-password`, `/reset-password/reset/{token}`, `/verify-email/resend`
    — 422, form re-render with a root-level CSRF error, service never
    called.** Ordinary Symfony Forms; `CsrfValidationListener::preSubmit()`
    adds a *form-level* `FormError` (rendered by `_error_summary.html.twig`
    as the plain, unanchored list item Task 38's accessibility pass already
    found this exact shape for) and never touches the controller. All three
    controllers only call their service inside `if ($form->isSubmitted() &&
    $form->isValid())` — confirmed by re-reading all three, not inferred —
    so a CSRF-rejected submission never reaches `PasswordResetService`/
    `EmailVerificationService` at all, and `AbstractController::doRender()`'s
    own submitted-and-invalid rule sets the response to 422 automatically
    (the same mechanism Task 27 already documented for a blank-email
    submission).

  **Side-effect proof per route, each concrete rather than inferred from
  the status code alone:** `/login` — a subsequent request to `/` is still
  anonymous. `/logout` — a subsequent request to `/player` still renders as
  the signed-in user. `/reset-password` — no email dispatched
  (`RecordingEmailMessageHandler`), and (stripped case) zero
  `reset_password_request` rows for the account, or (altered case, starting
  from an already-outstanding token) the exact same row/selector still
  present *and* still genuinely completable afterward — the stronger half
  of the task's "or" clause, not just "still in the table". `/reset-password/reset/{token}`
  — the stored password hash is byte-identical after `$em->clear()` +
  refetch, and the same token still completes a real reset afterward,
  proving it was never consumed. `/verify-email/resend` — no email
  dispatched, and (stripped case) zero `email_verification_token` rows, or
  (altered case, from a pre-existing token) the same selector untouched and
  the token still genuinely verifies the account afterward.

  **Test-isolation notes, consistent with Tasks 17/19/23/28/32/34's
  established pattern, not new discoveries:** `persist()` commits the
  fixture user immediately and reopens a transaction, because
  `AuthEventRecorder` writes through its own, genuinely separate physical
  connection (Task 34) whenever a real sign-in/sign-out fires — which
  happens here either way for the login tests (`LOGIN_FAILED` fires even on
  a CSRF-rejected attempt, since `AuthEventSubscriber::onLoginFailure()`
  does not distinguish the cause) and for the logout tests' precondition
  sign-in (`LOGIN_SUCCEEDED`); `tearDown()` deletes `auth_event` then
  `app_user` by email, and `ON DELETE CASCADE` on both token tables' `user_id`
  FK cleans up any `reset_password_request`/`email_verification_token` row a
  successful proof-of-validity step left committed. The reset-password and
  verify-email-resend CSRF-rejection requests themselves never touch the
  rate limiter (the controller's service call — the only thing that
  consumes `password_reset_account`/`password_reset_source` — sits inside
  the same `if ($form->isValid())` guard the CSRF check trips), so this file
  has no interaction with Tasks 22/23/33's array-cache-reset findings beyond
  what is already documented there.

  **Verify, exactly as specified:** `docker compose exec -T -e APP_ENV=test
  php php bin/phpunit tests/Functional/CsrfProtectionTest.php` — **10
  tests, 54 assertions, green**, stable across three repeated runs.
  `lint:container` — OK. `lint:yaml config` — OK (19 files).
  `doctrine:schema:validate --skip-sync` — mapping OK. Full suite run twice
  back to back: **149 tests, 530 assertions, green both times** (up from
  139 before this task — this task's 10 tests are the only addition,
  confirming no regression from Tasks 1-38). `tests/Functional/` alone
  (70/70) and the new file alone (10/10), immediately after the two full
  runs — no leakage observed in any combination.

  **This is the final task in the 39-task plan. Every task (1-39) is now
  checked off; the Coverage table below already lists AC-21 as claimed by
  Tasks 2, 12, and this one.**

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

---

## Accessibility verification notes (Task 38)

*Verified 2026-08-19. No browser-automation tooling (Playwright/Chrome
MCP/Axe/Lighthouse) is wired into this environment — confirmed by searching
the available tool set before starting. This is therefore a code-based pass,
not a live-browser pass: the app was started via `docker compose` (already
running — `nginx` on `http://localhost:8080`, `php`, `database`, `mailer`),
and every screen's **actual rendered HTML** was captured with `curl` (GET for
the initial state, POST with a matching `Origin` header for the
stateless-CSRF-valid invalid-submission states — see the "CSRF:
`enable_csrf`" note above, which this pass independently reproduced: a bare
POST with no `Origin`/`Referer` is rejected as CSRF-invalid, exactly as
documented). Rendered files live under
`/tmp/claude-1000/-home-user-ai-training-symfony/ce704a06-c108-4dcf-9699-5e915c52a7ce/scratchpad/`
(`login.html`, `login_after.html` (failed sign-in), `reset_request.html`,
`reset_request_invalid.html`, `reset_reset_invalid.html` (form state),
`reset_change_invalid.html` (validation-error state), `reset_change_refused.html`
(refused/invalid-token state), `reset_request_csrf_fail.html` (root-level CSRF
error, no anchor), `verify_resend.html`, `verify_resend_invalid.html`,
`verify_resend_submitted.html`, `verify_result_invalid.html`). Contrast ratios
were computed independently (not copied from Task 37) with a small script,
`contrast.py`, implementing the WCAG relative-luminance formula from scratch;
its full output and worked arithmetic are reproduced below. No DOM tabindex
attribute exists anywhere in `templates/` (`grep -rn tabindex templates/`
returns nothing), so for every screen below, tab order = DOM order and no
positive-tabindex hack needs separate checking.

**Overall result: PASS on all four checks for all three named screens. No
defects found; no code changes made.**

### Independently computed contrast ratios (WCAG relative-luminance formula)

Worked arithmetic, body text `#1a1a1a` on `#ffffff`:

```
R=G=B=0x1a=26 -> srgb = 26/255 = 0.101961 -> linear = ((0.101961+0.055)/1.055)^2.4 = 0.010330
L(#1a1a1a) = 0.2126*0.010330 + 0.7152*0.010330 + 0.0722*0.010330 = 0.010330
L(#ffffff) = 1.000000
contrast   = (1.000000+0.05)/(0.010330+0.05) = 1.05/0.06033 = 17.40:1
```

Worked arithmetic, focus outline `#0b5fae` on `#ffffff`:

```
R=0x0b=11  -> srgb=0.043137 -> linear=0.003347
G=0x5f=95  -> srgb=0.372549 -> linear=0.114435
B=0xae=174 -> srgb=0.682353 -> linear=0.423268
L(#0b5fae) = 0.2126*0.003347 + 0.7152*0.114435 + 0.0722*0.423268 = 0.113116
contrast   = (1.000000+0.05)/(0.113116+0.05) = 1.05/0.163116 = 6.44:1
```

Full script output (every colour pair actually used in `public/css/app.css`),
all independently agreeing with Task 37's stated numbers to 2 decimal places
(nothing here was copied from that task's notes — same formula, re-derived):

| Pair | Ratio | Floor | Result |
|---|---|---|---|
| body text `#1a1a1a` on `#ffffff` | **17.40:1** | 4.5:1 | PASS |
| muted text `#4b4b4b` on `#ffffff` | **8.72:1** | 4.5:1 | PASS |
| link/button/focus `#0b5fae` on `#ffffff` | **6.44:1** | 4.5:1 | PASS |
| button text `#ffffff` on fill `#0b5fae` | **6.44:1** | 4.5:1 | PASS |
| inline field-error `#b3261e` on `#ffffff` | **6.54:1** | 4.5:1 | PASS |
| error-summary text `#601410` on box `#fdecea` | **11.44:1** | 4.5:1 | PASS |
| status-box text `#1a1a1a` on `#eaf2fb` | **15.41:1** | 4.5:1 | PASS |
| control border `#6b6b6b` on `#ffffff` | **5.33:1** | 3:1 | PASS |
| focus outline `#0b5fae` vs page bg `#ffffff` | **6.44:1** | 3:1 | PASS |
| focus outline `#0b5fae` vs error-summary bg `#fdecea` | **5.63:1** | 3:1 | PASS |
| focus outline `#0b5fae` vs status bg `#eaf2fb` | **5.70:1** | 3:1 | PASS |

Cross-referenced against the actual rendered HTML: every input in the five
templates is `type="email"`, `type="password"`, or `type="text"`, so the
`input[type="email"], input[type="password"], input[type="text"] { min-height:
44px }` rule in `app.css` applies to every field observed; every submit
control is a bare `<button>`, so `button, .button { min-height/min-width:
44px }` applies without a class needing to be added; every invalid field in
the four captured invalid-submission renders carries the literal
`aria-invalid="true"` attribute the `input[aria-invalid="true"]` border rule
selects on; `.error-summary`/`.error-summary__title`/`.field-errors` classes
in `app.css` match the literal classes emitted by
`templates/form/_error_summary.html.twig` and `templates/form/theme.html.twig`
in the rendered output byte-for-byte.

### Screen 1 — Sign-in (`/login`)

- **(a) Keyboard walkthrough — PASS.** DOM/tab order confirmed from
  `login.html` and `login_after.html`: skip-link → (on failure only) the
  `role="alert"` paragraph's `<a href="#username">` → email input (has
  `autofocus`) → password input → submit button (`Sign in`) → "Forgot your
  password?" link. No positive tabindex, no element skipped, Enter on either
  input submits the native form. Reaches every control; no trap.
- **(b) Screen-reader spot-check — PASS, code-based (no live AT available in
  this environment; reasoned from the actual rendered ARIA wiring instead of
  a live NVDA/JAWS/VoiceOver session — noting explicitly that none was run).**
  A failed sign-in (`curl -X POST /login` with wrong credentials, confirmed
  live) renders `<p role="alert" id="login-error"><a href="#username">Invalid
  email or password.</a></p>` and both inputs gain
  `aria-invalid="true" aria-describedby="login-error"`. Two independent
  exposure paths exist: (1) `role="alert"` marks the region as an assertive
  live region for any AT user who reaches it; (2) regardless of whether a
  given browser/AT combination auto-announces an alert region that was
  already present at initial parse (a known cross-AT inconsistency for
  full-page reloads, as opposed to an AJAX-inserted alert — not a defect in
  this codebase), the `aria-describedby` link guarantees that the moment
  focus lands on either input (which happens immediately here, since
  `username` carries `autofocus`), the screen reader reads the field plus its
  described error text. This is a reliable mechanism independent of live-
  region announcement timing.
- **(c) Contrast — PASS.** Body text, link colour, and focus outline are the
  shared tokens verified above (17.40:1, 6.44:1, 6.44:1 respectively); this
  screen introduces no screen-specific colours.
- **(d) 320px viewport / 44×44 targets — PASS.** `main.page` is
  `max-width: 30rem` with `1.5rem 1rem` padding — at a 320px viewport the
  content column is `320 - 2*16 = 288px`, comfortably under 320px with no
  horizontal-scroll risk (no fixed-pixel widths anywhere in `app.css` wider
  than that; every width is `100%`/`auto`). Both inputs get `min-height:
  44px`; the submit button gets `min-height`/`min-width: 44px`; the
  "Forgot your password?" link is in `.link-list a`, which sets
  `min-height: 44px` (its rendered text is far wider than 44px, so width is
  not a constraint here).

### Screen 2 — Password reset (`/reset-password`, `/reset-password/reset/{token}`)

- **(a) Keyboard walkthrough — PASS**, confirmed on all three states actually
  rendered:
  - *Request form* (`reset_request.html` / `reset_request_invalid.html`):
    skip-link → (if invalid) error-summary anchor(s), e.g.
    `<a href="#reset_password_request_form_email">` → email input → submit
    button. No link-list on this state.
  - *Completion form* (`reset_reset_invalid.html` is the form state served
    for an unrecognized-at-GET-time token; `reset_change_invalid.html` is the
    same form after a password-mismatch POST): skip-link → (if invalid)
    error-summary anchor → `plainPassword_first` input → `plainPassword_second`
    input → submit button (`Reset password`).
  - *Refused state* (`reset_change_refused.html`, produced by POSTing a
    well-formed-but-unresolvable token): skip-link → single `role="alert"`
    paragraph (plain text, not a link — see below) → one link-list entry,
    "Request a new reset link". No control is skipped in any state.
- **(b) Screen-reader spot-check — PASS, code-based, same caveat as Screen 1
  (no live AT session run).** Two error shapes were captured live: a
  field-level error (`reset_request_invalid.html`, invalid email) renders the
  error-summary `<li><a href="#reset_password_request_form_email">Email: This
  value is not a valid email address.</a></li>` and the field itself gets
  `aria-invalid="true" aria-describedby=
  "reset_password_request_form_email_error1"` — the id referenced by
  `aria-describedby` matches the `id` on the `<li>` that actually carries the
  message, confirmed byte-for-byte in the fetched HTML (not assumed from the
  template source). A **root-level (non-field) CSRF error** was also
  independently reproduced (POST with no `Origin`/`Referer` header and a
  syntactically-invalid token): the error-summary renders `<li>The CSRF token
  is invalid. Please try to resubmit the form.</li>` as **plain text with no
  anchor and no field marked invalid** — exactly matching Task 37's claim,
  now independently reproduced rather than trusted. The change-password
  form's mismatch error is correctly anchored to the **first** repeated-field
  child (`change_password_form_plainPassword_first`), matching the documented
  `RepeatedType`-maps-errors-to-first-child behaviour, confirmed in the live
  `reset_change_invalid.html` capture. The refused-token state's
  `role="alert"` paragraph carries the entire message as its only content, so
  a screen reader reaching it reads the whole reason text.
- **(c) Contrast — PASS.** Same shared tokens (body text 17.40:1, focus
  outline 6.44:1 vs `#ffffff`); the error-summary box additionally uses
  `#601410` on `#fdecea` (11.44:1) and the focus ring on links/inputs inside
  that box was independently computed at 5.63:1 against `#fdecea` — both
  clear their respective floors.
- **(d) 320px viewport / 44×44 targets — PASS.** Same `main.page` sizing
  argument as Screen 1. Both password inputs and the email input get
  `min-height: 44px`; both submit buttons get `min-height`/`min-width: 44px`;
  the refused-state's single recovery link is in `.link-list a` (`min-height:
  44px`, wide text).

### Screen 3 — Email verification (`/verify-email/resend`, `/verify-email/{token}`)

- **(a) Keyboard walkthrough — PASS**, confirmed on all captured states:
  - *Resend form* (`verify_resend.html` / `verify_resend_invalid.html`):
    skip-link → (if invalid) error-summary anchor → email input → submit
    button (`Send verification link`).
  - *Resend submitted* (`verify_resend_submitted.html`): skip-link → one
    `role="status"` paragraph, no further controls (a deliberate dead-end
    confirmation, mirroring the reset-password `check_email` screen read
    directly from `templates/reset_password/check_email.html.twig`).
  - *Result screen* (`verify_result_invalid.html` captured live for the
    `invalid` branch; the `success`/`expired`/`already_consumed` branches were
    confirmed structurally identical by reading
    `templates/verify_email/result.html.twig` directly — same single
    `h1`/`role="alert"`-or-`role="status"` paragraph/one-item `link-list`
    shape in every branch, just different text and link target, so the tab
    order established for the live `invalid` capture — skip-link → paragraph
    (not focusable) → one link — holds for all four branches without needing
    seed data to force each token state).
- **(b) Screen-reader spot-check — PASS, code-based, same caveat as Screens 1
  and 2.** `verify_resend_invalid.html` (invalid email) shows the identical
  error-summary/`aria-describedby` pattern already verified for the
  reset-password request form —
  `aria-describedby="resend_verification_form_email_error1"` matches the
  `<li id="resend_verification_form_email_error1">` that holds the message,
  confirmed in the live capture. The result screen's `role="alert"` (invalid/
  expired/already-consumed) or `role="status"` (success) paragraph contains
  the entire user-facing message as its only text content, so it is fully
  exposed to any AT user who reaches it regardless of live-region timing.
- **(c) Contrast — PASS.** Same shared tokens; the status-message box
  (`role="status"`, seen live in `verify_resend_submitted.html`) uses
  `#1a1a1a` on `#eaf2fb` (15.41:1, independently computed above).
- **(d) 320px viewport / 44×44 targets — PASS.** Same `main.page` sizing
  argument. Email input and submit button meet the 44px rules as above; the
  result screen's single link-list entry meets `.link-list a`'s 44px
  min-height.

### Findings

No accessibility defect was found. One structural observation, not a defect:
the login failure message (`templates/security/login.html.twig`) renders as a
bare `<p role="alert" id="login-error">` with no `.alert-message`/
`.status-message` styling class, unlike every other screen's error/status
box — this is intentional per Task 37's note (the exact-trimmed-text
assertion in `SignInTest::testTheFourFailureCausesAreIndistinguishable`
constrains this paragraph's content), and it does not affect contrast (the
paragraph inherits body text colour, and its link inherits the link colour
already verified at 6.44:1) or keyboard/AT exposure. Recorded here as a
finding, not silently patched, since it is a deliberate design/test
constraint rather than an oversight.

### Verify

Four checks (a/b/c/d), each with a concrete pass/fail and number rather than
a bare checkmark, recorded above for all three named screens (sign-in;
password reset request + completion; email verification resend + result).
No code changes were made during this pass, so the full suite was not
re-run — it remains at Task 37's end state (139 tests, 476 assertions,
green).
