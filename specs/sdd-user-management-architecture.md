# Design: User Management Basics (Epic-01, slice S2)

> Answers *how*. The *what* and *why* live in `specs/sdd-user-management-spec.md`
> (AC-1…AC-24); this file does not restate them.
> Builds directly on `specs/auth-foundation-architecture.md` (S1). Written against the
> repo as S1 left it: `App\Entity\User` (scalar `role`/`status`, UUIDv7), the frozen
> `Profile` contract (not yet built), `symfonycasts/reset-password-bundle`'s
> selector/verifier discipline, Messenger-queued mail, `AuthEventRecorder`.

## Approach

Reuse every S1 mechanism that already fits rather than inventing parallel ones:

1. **The invitation token is the verification/reset token pattern a third time.**
   `AccountInvitation` is structurally identical to `EmailVerificationToken` (selector,
   `hashed_verifier`, `expires_at`, `consumed_at`) plus `issuedByUserId`. Consuming it sets
   the trainer's password *and* calls the same `User::markEmailVerified()` S1 already
   has. No new crypto, no new bundle.
2. **The frozen `Profile` contract is built now, exactly as specified in S1's
   architecture doc** — abstract `Profile` (JOINED inheritance, base table `profile`,
   `UNIQUE(user_id, type)`) plus one concrete subclass, `ProfileTrainer`. Coach/Player/Child
   subclasses are additive migrations for S3/S4/S5 to write when they have real columns —
   S2 does not stub empty tables for them.
3. **Account-management audit is a sibling of `AuthEvent`, not a reuse of it.** S1's
   `auth_event` is deliberately about the authentication surface (sign-in/out, reset,
   verification). Deactivation, deletion, and trainer creation are a different concern —
   administrative actions performed *by* one user *on* another — so they get their own
   table, `AccountEvent`, with an explicit `actor_user_id` / `subject_user_id` pair that
   `auth_event` has no reason to carry (its actor and subject are always the same person).
   S6 reports over both tables; it does not need them merged.
4. **Deletion is anonymize-in-place, not a second table shadowing `app_user`.** The
   `AccountDeletionLog` row is the compliance record (AC-21); the `app_user` row itself is
   mutated to its anonymized values and stays the row every foreign key already points at,
   so "history preserved" (AC-16, BR-016) requires no join changes anywhere else in the
   schema.
5. **Common display fields (name, phone, photo) live on `User`, not on a common
   `Profile`.** They carry no capability and are not role-specific, so per S1's
   "profiles carry capability data" invariant they do not belong in the `Profile`
   hierarchy at all — putting them there would just be a second place to look for
   ordinary identity data. `Profile` stays reserved for what actually varies by role
   (a trainer's business name, later a coach's certifications).

## Components

### Entities and schema

**`User` (extended, no new table)** — three additive, nullable columns plus one index:

| Column | Type | Notes |
|---|---|---|
| `first_name` | `varchar(80)` NULL | null until the user (or the trainer invitation flow) sets it |
| `last_name` | `varchar(80)` NULL | |
| `phone` | `varchar(32)` NULL | E.164-ish, validated by `Assert\Regex`, not by a DB constraint (formats vary by locale) |
| `photo_key` | `varchar(255)` NULL | opaque storage key, never a filesystem path exposed to the client — see FileStorage below |

Index `idx_app_user_status_role_created` on `(status, role, created_at)` — the Users
directory's only query shape (AC-1, AC-2, AC-3): filter by status and/or role, sort by
`created_at`, keyset-paginate.

`UserStatus` gains one case: `DELETED`. The migration also rewrites the `CHECK (status IN
(...))` constraint to include it (Doctrine DBAL does not diff CHECK constraints, so this
is a hand-written SQL line in the migration, same as S1's `email = lower(email)` check).

```php
enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DEACTIVATED = 'DEACTIVATED';
    case DELETED = 'DELETED';
}
```

`User` gains: `getFirstName()/setFirstName()`, `getLastName()/setLastName()`,
`getDisplayName(): string` (falls back to the email local-part when both are null — a
newly-invited trainer has no name yet), `getPhone()/setPhone()`, `getPhotoKey()/setPhotoKey()`,
and `anonymize(\DateTimeImmutable $at): void` which sets `firstName = null`,
`lastName = null`, `phone = null`, `photoKey = null`, `email = "deleted_{$this->id}@example.com"`,
`status = UserStatus::DELETED`, and touches `updatedAt`. None of these join
`isEqualTo()`'s signature except the status change already covered there — an admin
editing their own name does not need to sign anyone else out, but a deletion (which does
change `status`) already does, for free.

**`App\Entity\Profile`** → `profile` (abstract, `JOINED` inheritance) — exactly the S1
contract: `id uuid PK`, `user_id uuid FK ON DELETE CASCADE`, `type varchar(32)`
discriminator, `created_at`, `updated_at`, `deleted_at NULL`, `UNIQUE (user_id, type)`.

**`App\Entity\ProfileTrainer`** → `profile_trainer` (`id` FK to `profile.id`):
`business_name varchar(160) NOT NULL`, `website varchar(255) NULL`,
`address varchar(255) NULL`, `description text NULL`. A trigram index,
`idx_profile_trainer_business_name_trgm` on `lower(business_name)` (requires the
`pg_trgm` extension — the migration issues `CREATE EXTENSION IF NOT EXISTS pg_trgm`),
because trainer business name is the one field the Users-tool search additionally
matches against for `ROLE_TRAINER` rows (spec's own §8 lists "business name" as
trainer-profile data the directory should find).

**`App\Entity\AccountInvitation`** → `account_invitation`: `id uuid PK`,
`user_id uuid FK ON DELETE CASCADE`, `issued_by_user_id uuid NULL FK ON DELETE SET NULL`,
`selector varchar(24) UNIQUE`, `hashed_verifier char(64)`, `expires_at timestamptz`,
`created_at timestamptz`, `consumed_at timestamptz NULL`. Index
`(user_id, consumed_at)`. Lifetime: 7 days (no spec number given for this token; chosen
to comfortably exceed S1's 24h/1h tokens since a trainer invitation is a slower, often
manually-forwarded action — recorded as a Decision, changeable without a design change).

**`App\Entity\AccountEvent`** → `account_event`: `id uuid`, `occurred_at timestamptz`,
`type varchar(64)` (backed by `AccountEventType`: `TRAINER_CREATED`, `USER_DEACTIVATED`,
`USER_REACTIVATED`, `USER_DELETED`, `PROFILE_UPDATED`), `actor_user_id uuid NULL FK ON
DELETE SET NULL`, `subject_user_id uuid FK ON DELETE RESTRICT` (never silently lose which
account an audit row is about), `ip inet NULL`, `user_agent varchar(255) NULL`,
`context jsonb NOT NULL`. Indexes: `(subject_user_id, occurred_at)`,
`(actor_user_id, occurred_at)`, `(type, occurred_at)` — the same shape `auth_event`
uses, for the same reason (S6 reports need queries). `ON DELETE RESTRICT` on
`subject_user_id` is deliberate and asymmetric with `auth_event`'s `SET NULL`: deletion
*anonymizes* the row rather than removing it (Approach §4), so the FK target always
still exists; RESTRICT is a second, schema-level guarantee that nothing can later change
that without the audit trail noticing.

**`App\Entity\AccountDeletionLog`** → `account_deletion_log`: `id uuid PK`,
`subject_user_id uuid UNIQUE FK ON DELETE RESTRICT` (one row per ever-deleted account —
the uniqueness constraint is what makes AC-23's "already deleted" check a single indexed
lookup, not a status re-check that could race), `actor_user_id uuid NULL FK ON DELETE SET
NULL`, `anonymized_email varchar(180)`, `reference varchar(120) NULL` (optional
free-text reason, per US-01.13's "reason" field), `deleted_at timestamptz`.

**Migration.** One migration, `Version…UserManagement`: alter `app_user` (add columns,
index, rewrite status CHECK), create `profile`, `profile_trainer` (+ `pg_trgm`), create
`account_invitation`, `account_event`, `account_deletion_log`. Reversible in the down
migration in reverse order.

### Trainer creation (`UserAccountService` extended + new `TrainerOnboardingService`)

- `TrainerOnboardingService::createTrainer(CreateTrainerRequest $request, User $actor):
  User` — one transaction: `UserAccountService::create()` (S1's existing method — email
  normalize, unique-violation mapping, no password hash yet: a random, never-disclosed
  hash placeholder, since `password_hash` is currently non-null in the S1 schema and
  loosening that constraint is a schema change this slice does not need to make for one
  caller) with `UserRole::TRAINER`, `UserStatus::ACTIVE`; creates the `ProfileTrainer` row;
  issues an `AccountInvitation` (reusing the selector/verifier generation approach
  `EmailVerificationTokenService` already implements — extracted to a small shared
  `SelectorVerifierTokenFactory` used by both, rather than copy-pasted a third time);
  records an `AccountEvent::TRAINER_CREATED` via `AccountEventRecorder` (the `AccountEvent`
  counterpart of `AuthEventRecorder`, same "own transaction" independence); dispatches the
  invitation email through the existing Messenger `SendEmailMessage` pipeline.
- `AccountInvitationController` (`GET /invitations/{token}` renders a "set your password"
  form, `POST` consumes it): the consume step validates like S1's `EmailVerificationTokenService::consume`
  (split token, `SELECT … FOR UPDATE` by selector, `hash_equals`, reject if consumed/expired),
  then sets the real password hash via `UserRepository`'s existing `PasswordUpgraderInterface`
  path, calls `markEmailVerified()`, and redirects to `/login` — deliberately not
  auto-signing-in, so the very first authenticated action on a new account goes through
  the same audited `form_login` path as everyone else.
- No route exists for a Trainer/Coach/Player to reach `TrainerOnboardingService` — it has
  no controller action reachable without `#[IsGranted('ROLE_SUPER_ADMIN')]` on
  `Admin\UserController::create()` (AC-8).

### Users directory (`Admin\UserController` + `UserRepository`)

- `UserRepository::search(UserSearchCriteria $criteria): Paginator` — one method owning
  the query: optional `role`, optional `status`, optional `query` (ILIKE against
  `first_name || ' ' || last_name`, `email`, and — only when `role = TRAINER` is also
  selected or absent — `profile_trainer.business_name` via a `LEFT JOIN`), ordered by
  `created_at DESC`, keyset-paginated on `(created_at, id)` rather than `OFFSET`, so
  AC-3 holds at 10,000 rows without an ever-slower `OFFSET`. `%`/`_` in the query string
  are escaped before binding — Doctrine parameter binding already prevents injection;
  escaping is specifically for the edge case of literal wildcard characters not
  behaving as wildcards.
- `Admin\UserController` (`#[IsGranted('ROLE_SUPER_ADMIN')]` on the class): `index()`
  (the directory), `create()` (trainer form → `TrainerOnboardingService`), `edit(User)`
  (Super-Admin editing any account's common + role-specific fields, distinct route from
  the self-service one below), `deactivate(User)`, `reactivate(User)`, `delete(User)`
  (renders the warning, then calls the service on confirm).

### Profile self-service (`ProfileController` + `ProfileService`)

- `ProfileController` (`#[IsGranted('ROLE_USER')]`, no role check beyond "signed in" —
  every role reaches it): `edit()` always operates on
  `$this->getUser()`, never on a route parameter, which is what makes AC-13's "cannot spoof
  a different user's id" true by construction rather than by a check that could be missed.
  Renders the common fields plus, when `$user->getRole() === UserRole::TRAINER`, an
  embedded `ProfileTrainerFormType` fieldset.
- `ProfileService::updateCommon(User, ProfileCommonData)`, `updateTrainerDetails(User,
  ProfileTrainerData)`, `uploadPhoto(User, UploadedFile): string` — each records an
  `AccountEvent::PROFILE_UPDATED` with `actor = subject = $user` (a self-edit; the actor/
  subject split still holds, just equal) except the photo upload, which is not
  audit-worthy on its own (it is not PII disclosure or an access change).
- `App\Service\FileStorage` — `store(UploadedFile, string $prefix): string` (validates
  real MIME via `finfo`/Symfony's `MimeTypes` guesser against an allow-list
  `image/jpeg`, `image/png`, `image/webp`, caps at 5 MB, generates a random opaque key,
  writes under `%kernel.project_dir%/var/uploads/<prefix>/<key>.<ext>` — **outside**
  `public/`, so nothing is directly served by nginx without going through app code) and
  `read(string $key): StreamedResponse`. **`PhotoController::show(User $user)`** streams
  the current user's own photo, or (only for `ROLE_SUPER_ADMIN`) any user's — this is what
  makes AC-12's "not a guessable filesystem path" true: the key is opaque and the read path
  is authorized per-request, not a static asset URL.

### Deactivation / reactivation (`AccountLifecycleService`)

- `deactivate(User $subject, User $actor): void` — guard: refuse if
  `$subject->getStatus() === UserStatus::DELETED` (edge case in the spec); set
  `UserStatus::DEACTIVATED`; touch; record `AccountEvent::USER_DEACTIVATED`. No new
  session-invalidation code: `EquatableInterface` already compares `status`, so S1's
  existing mechanism ends any open session at its next request — this slice adds zero
  lines for that half of AC-15.
- `reactivate(User $subject, User $actor): void` — guard: refuse unless
  `$subject->getStatus() === UserStatus::DEACTIVATED` (a `DELETED` account cannot be
  reactivated — AC-20 — and reactivating an already-`ACTIVE` one is a no-op, not an
  error); set `ACTIVE`; record `USER_REACTIVATED`.

### GDPR deletion (`AccountLifecycleService::delete`)

- `delete(User $subject, User $actor, ?string $reason): void`, one transaction:
  guard — refuse if already `UserStatus::DELETED` (AC-23, checked via the
  `account_deletion_log` unique index as the authority, not just the in-memory status,
  so a concurrent double-delete is caught by the DB); capture `$subject->getEmail()`
  *before* mutating; call `$subject->anonymize($now)` (sets the deterministic
  `deleted_<id>@example.com`, per AC-22); persist an `AccountDeletionLog` row with the
  pre-anonymization email, `$actor`, `$reason`; record `AccountEvent::USER_DELETED`
  (subject = the now-anonymized user, actor = the admin). `EquatableInterface`'s
  status-in-signature already ends any live session at its next request — same free
  mechanism as deactivation.
- The Users-tool `delete()` controller action requires the confirmation step to echo back
  the account's current display name/email in the confirmation form (AC-18's "warning
  that states the action is irreversible" is a template concern; the controller's only
  job is refusing a GET to perform the mutation and requiring the CSRF-protected POST).

### Authorization

- No new voter. `#[IsGranted('ROLE_SUPER_ADMIN')]` on `Admin\UserController` covers every
  admin action (AC-1, AC-13's admin half); `ProfileController` needs no per-object check
  because it never takes a target id from the request (AC-13's self-service half).
- **Deliberately no `OrganizationVoter` in S2.** S1's architecture doc flagged this as
  arriving "with `Organization` … in S2"; revisiting that now that `ProfileTrainer` exists
  but no coach/player is yet owned by one: there is nothing to scope a decision *about*
  yet, so a voter here would be encoding a guess about S3's association shape instead of
  a real rule. Recorded as a Decision below, not a silent scope cut — S3's "Done when"
  criterion is where multi-tenancy becomes a real, testable rule.

### Mail

One new template, `emails/trainer_invitation.html.twig` (+ text alternative), dispatched
through S1's existing `SendEmailMessage` → `SendEmailMessageHandler` → async Doctrine
transport. No new mailer/messenger configuration.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| HTTP translation for admin actions | Controller | `Admin\UserController`, `AccountInvitationController` |
| HTTP translation for self-service | Controller | `ProfileController`, `PhotoController` |
| Trainer creation workflow | Service | `TrainerOnboardingService` |
| Own-profile / trainer-details edit, photo | Service | `ProfileService` |
| Deactivate / reactivate / delete workflow | Service | `AccountLifecycleService` |
| Invitation token crypto, reuse | Service | `SelectorVerifierTokenFactory` (shared with S1's `EmailVerificationTokenService`) |
| File validation and storage | Service | `FileStorage` |
| Admin audit write | Service | `AccountEventRecorder` |
| Queries and persistence | Repository | `UserRepository` (extended), `ProfileRepository`, `AccountInvitationRepository`, `AccountEventRepository`, `AccountDeletionLogRepository` |

Transaction boundary and controller/service/repository responsibilities: unchanged from
S1's rules (one transaction per service method, controllers never `flush()`, repositories
never authorize).

### Tests this slice must produce

Functional: Users-tool access control (non-admin refused); pagination and each filter/
search combination; trainer creation happy path + duplicate-email + concurrent-duplicate;
invitation consume (happy path, expired, already-consumed, wrong verifier); no
self-registration route exists for any role (extends S1's router-sweep test rather than
duplicating it); own-profile edit (including the id-spoofing edge case); photo upload
(valid, oversized, wrong-MIME-despite-extension); deactivate → sign-in refused → reactivate
→ sign-in succeeds; delete → anonymized fields exactly as specified → sign-in refused →
second delete refused → deletion log row correct; deactivate-a-deleted-account refused;
reactivate-a-deleted-account refused. Repository integration: keyset pagination stability
across a page boundary, trigram search matching business name. Unit: `User::anonymize()`,
`User::getDisplayName()` fallback, `AccountLifecycleService` guards.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| `pg_trgm` (Postgres extension, no new Composer package) | built-in | Trainer-name substring search over a full-text index or an external search service: the data volume (NFR-002's 10,000 users) does not justify Elasticsearch, and `ILIKE` alone degrades faster than a trigram GIN index as the table grows. |
| No new Composer package for file storage | — | `local` Flysystem adapter would be the natural upgrade path if S8's logo upload or cloud storage arrives later, but S2's single use case (validate + write one file under `var/uploads`) does not yet justify the abstraction; `FileStorage`'s narrow interface (`store`/`read`) is exactly where a Flysystem adapter would slot in without callers changing. |

Not added: a thumbnailing library (out of scope, see spec); `symfony/uid` — already
present from S1, reused for every new entity's PK.

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| Common name/phone/photo fields | Columns on `User` | A `ProfileCommon`/`UserDetails` 1:1 entity | They are not capability data and not role-specific — S1's Profile invariant says capability data only. A common profile entity would just be a second place to look for the same identity concern, for no query or ownership benefit. |
| Trainer creation without a password | Non-null placeholder hash + invitation flow | Loosen `password_hash` to nullable | One caller (trainer creation) does not justify widening a column S1 deliberately made non-null; a hash of random bytes that is never disclosed and can never validate any submitted password is simpler than tracking "no password yet" as a third account state. |
| Admin audit table | New `account_event`, separate from `auth_event` | Reuse `auth_event` with a nullable actor/subject split retrofitted onto it | `auth_event`'s rows are always self-actions (you logged yourself in); retrofitting an actor/subject split onto it to fit a different kind of event (one user acting on another) is a schema change to a table S1 already shipped and tested, for a distinction the two event kinds do not share. |
| Deletion mechanism | Anonymize the `app_user` row in place + a separate compliance log | A `deleted_users` shadow table, move-then-delete | Every foreign key in the schema (and every one S3–S8 will add) already points at `app_user.id`. Anonymizing in place means "history preserved" is automatic; moving the row would require every current and future FK to tolerate a missing parent. |
| Multi-tenancy voter | Deferred to S3 | Build an `OrganizationVoter` now, ahead of any owned data | Nothing exists yet for it to authorize. A voter written against a guessed association shape is more likely to be rewritten than reused once S3 defines `TrainerPlayerAssociation`. |
| Invitation lifetime | 7 days | Match S1's 24h verification window | No spec number exists for this token; a trainer invitation is typically manually forwarded/delayed more than a person clicking their own verification link, so a longer window reduces false "expired" support requests. Purely a config value (see S1's own precedent for Q-01.07), not a design constant. |

## Risks

- **`password_hash` non-null placeholder is a foot-gun if ever logged or compared
  carelessly.** Mitigation: generate it from `random_bytes(32)` through the same
  `PasswordHasherInterface` used everywhere else (so it is a real Argon2id hash of
  unguessable input, not a sentinel string that might be special-cased somewhere and
  accidentally treated as "no password" by future code) and never expose a getter that
  returns it outside `PasswordAuthenticatedUserInterface::getPassword()`.
- **`pg_trgm` requires a Postgres superuser (or a role with `CREATE EXTENSION`
  privilege) at migration time.** The `docker-compose.yml` Postgres user (`app`) is the
  database owner, which is sufficient locally; a managed Postgres in production may
  require the extension pre-enabled by the platform. Flag this in deployment docs, same
  category as S1's Messenger-worker risk.
- **Keyset pagination changes the Users-tool URL contract** (cursor-based, not
  `?page=N`). Acceptable now because no UI depends on page-number URLs yet; revisit if a
  later slice wants "jump to page 40."
- **`AccountEvent.context` is a free `jsonb` field**, unlike `AuthEventRecord`'s
  enumerated-fields DTO. Mitigation carried over from S1: `AccountEventRecorder::record()`
  takes a small typed DTO per event type, not a raw array, so the same "cannot hold a
  secret because the type does not have a field for one" guarantee applies structurally,
  not by caller discipline.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `UserRepository::search`, keyset pagination, `Admin\UserController#index` | AC-1, AC-2, AC-3 |
| `TrainerOnboardingService`, `AccountInvitation`, invitation mail | AC-4, AC-5, AC-9 |
| `AccountInvitationController` consume guards | AC-6 |
| `UserAccountService::create` unique-violation mapping (S1, reused) | AC-7 |
| Router-sweep extension: no self-registration route | AC-8 |
| `ProfileController` (self-only), `ProfileService::updateCommon` | AC-10 |
| `ProfileTrainerFormType`, `ProfileService::updateTrainerDetails` | AC-11 |
| `FileStorage`, `PhotoController` | AC-12 |
| `ProfileController` never takes a target id; `Admin\UserController#edit` gated to Super Admin | AC-13 |
| `AccountLifecycleService::deactivate` | AC-14, AC-16 |
| `EquatableInterface` (S1, reused) | AC-15 |
| `AccountLifecycleService::reactivate` guard | AC-17 |
| `Admin\UserController#delete` confirmation | AC-18 |
| `User::anonymize()` | AC-19, AC-22 |
| `AccountLifecycleService::reactivate` guard (rejects `DELETED`) | AC-20 |
| `AccountDeletionLog` | AC-21 |
| `AccountDeletionLog.subject_user_id` unique index + `delete()` guard | AC-23 |
| `ProfileTrainer` entity | AC-24 |

No criterion is uncovered.
