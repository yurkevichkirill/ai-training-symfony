# TASK-007 — Epic-01 slice S7: Trainer Portal Branding

Design: `specs/sdd-trainer-branding-architecture.md`. Spec:
`specs/sdd-trainer-branding-spec.md`. Each task cites the acceptance
criteria (AC-N) it serves, or -- for a schema/infrastructure/gate/review
task with no AC of its own -- the Decision or Risk it protects. Mark `[x]`
only once the change is made, migrated, and (where a test task follows)
proven.

This is the last unbuilt story in Epic-01 (US-01.14) per the spec. This
plan touches **no** S1 auth/firewall internals beyond one `security.yaml`
verification line, and freezes every column S1--S6 already shipped: only
two additive nullable columns are added, to `profile_trainer`.

**Naming convention note**, confirmed against source before writing this
plan (TASK-006's precedent): voters live directly under `App\Security`
(`CoachVoter`, `ImpersonationVoter`, no `Voter` sub-namespace), service-layer
domain exceptions live in `App\Service\Exception`, and DTOs such as
`ProfileTrainerRequest` live directly in `App\Service`. Every task below
names the real namespace, not the architecture doc's shorthand.

**Sequencing risk called out up front**, mirroring TASK-006's Task 6/34
shape. `FileStorage::store()` gains two optional trailing parameters
(Task 4) -- a shared-file change every existing upload caller depends on.
Task 5 is a standalone regression gate proving the widened signature is
behavior-identical for S2's existing `ProfileService::uploadPhoto()` call.
Do not start Task 6 onward until Task 5 is green.

## Schema

- [x] 1. Add two columns to `App\Entity\ProfileTrainer` (S2, extended --
  no other change to the file): `logoKey` (`#[ORM\Column(name: 'logo_key',
  type: 'string', length: 255, nullable: true)]`, default `null`) and
  `primaryColorHex` (`#[ORM\Column(name: 'primary_color_hex', type:
  'string', length: 7, nullable: true)]`, default `null`), each with a
  plain `get*()`/`set*()` pair following the file's existing
  `website`/`address` accessor style. `NULL` on both is the "never
  customised" state (D1b) -- no constructor change, since both are
  optional. (D1)
- [x] 2. Generate one migration, `Version...TrainerBranding`: `ALTER TABLE
  profile_trainer ADD COLUMN logo_key VARCHAR(255) NULL, ADD COLUMN
  primary_color_hex VARCHAR(7) NULL` plus the hand-written, DBAL-undiffable
  `CHECK (primary_color_hex IS NULL OR primary_color_hex ~
  '^#[0-9a-f]{6}$')`. No default, no backfill -- an existing trainer row is
  correctly represented by two `NULL`s. Down migration drops the CHECK and
  both columns. Run against dev + test DB; confirm
  `doctrine:schema:validate` is clean and `doctrine:schema:update
  --dump-sql` reports "Nothing to update" on a **second** run (S3--S6's
  normalization-trap check). (AC-9, AC-10, D1, D1b)
- [x] 3. Add `ProfileRepository::findTrainerProfilesFor(array $users):
  array` (keyed by trainer user id) to the existing `App\Repository\ProfileRepository`
  (S2), a batched sibling of the existing `findTrainerProfile(User $user):
  ?ProfileTrainer` -- one `WHERE user_id IN (...)` query, no N+1, for tier
  B's roster/family pages. Repositories never authorize. (AC-11, NFR-001)

## `FileStorage` widening -- the shared-file change, isolated as its own gate

- [x] 4. Widen `App\Service\FileStorage::store()`'s signature to `store(UploadedFile
  $file, string $prefix, ?int $maxBytes = null, ?array $allowedMimeTypes =
  null): string`. `null` for either parameter falls back to the existing
  `self::MAX_BYTES` / `self::ALLOWED_MIME_TYPES` class constants exactly as
  today. Internally: the byte check uses `$maxBytes ?? self::MAX_BYTES`;
  the MIME check builds its extension lookup from `$allowedMimeTypes ??
  self::ALLOWED_MIME_TYPES` (still keyed by content-sniffed MIME type,
  never by filename). `ProfileService::uploadPhoto()`'s existing call,
  `store($file, 'photos')`, is not edited -- it is unchanged in text and in
  behavior. No other method on the class changes. (D2b)
- [x] 5. **Verification gate, before any branding code depends on Task 4.**
  Add `tests/Unit/Service/FileStorageRegressionTest.php` (or extend an
  existing `FileStorage` test if one exists -- check first): a call with no
  third/fourth argument behaves byte-identically to before this slice --
  a file within the old 5MB/allowed-type bounds still stores under the old
  rules, and a file over 5MB or of a disallowed type still raises the same
  exception it always did. Then run the **existing** S2 photo-upload
  functional suite (`ProfileController`'s upload test, by whatever name it
  has) and require it green with **zero test edits**. Do not proceed to
  Task 6 until this gate is green. (D2b, Risk: "`FileStorage` is widened,
  and it is the upload path S2's shipped photo upload already depends on")

## Value layer

- [x] 6. Create `App\Branding\TrainerBranding` (final, readonly,
  Doctrine-free): properties `?string $logoUrl`, `string
  $primaryColorHex`, `string $contrastColorHex`; static
  `platformDefault(): self` returning `#0b5fae`/`#ffffff` with
  `logoUrl: null`; `hasLogo(): bool`. Holds no entity reference. (D4, D4b)
- [x] 7. Create `App\Branding\ContrastColor` (final, stateless): static
  `forBackground(string $hex): string` implementing WCAG 2.x relative
  luminance (sRGB linearisation, 0.2126/0.7152/0.0722 weights), returning
  `#ffffff` or `#1a1a1a` (the stylesheet's `--color-text`), whichever gives
  the higher contrast ratio against `$hex`. Pure integer/float arithmetic,
  no dependency. (D4b)
- [x] 8. Create `App\Service\TrainerBrandingRequest` (final, readonly DTO,
  in `ProfileTrainerRequest`'s existing constructor-normalising style):
  `{?string primaryColorHex}`, trimming and lowercasing the value and
  mapping `''` to `null` in the constructor -- one normalisation site, not
  per-caller. (AC-9, D4b Risk: three-place hex validation must agree)

## Authorization

- [x] 9. Create `App\Security\BrandingVoter` with two attributes, subject
  `User` (the trainer) in both cases: `EDIT_BRANDING` -- granted when the
  subject is an active `TRAINER` **and** (the token user *is* the subject,
  **or** the token user is an active `SUPER_ADMIN`); `VIEW_BRANDING` --
  granted under the same condition, **or** when the token user has an
  active `TrainerPlayerAssociation` or `TrainerCoachAssociation` with the
  subject, **or** is the parent of a child (via `ChildAccountRepository` /
  the existing parent-lookup path) who has an active
  `TrainerPlayerAssociation` with the subject. Reads only `User`,
  `TrainerPlayerAssociation`, `TrainerCoachAssociation`, and the
  child/parent link -- no `Profile` is read, preserving S1's
  "authorization never reads a Profile" invariant. Written out explicitly
  because `role_hierarchy` is flat (S5's fact), so the Super Admin clause
  cannot be inherited. (AC-2, AC-6, AC-7, BR-001, D5)
- [x] 10. Create `App\Service\Exception\BrandingActionNotPermittedException`
  (plain domain exception, in the existing `App\Service\Exception`
  namespace). Not wired into any service yet -- Task 12 does. (AC-2, D5, D6)

## Services

- [x] 11. Create `App\Service\TrainerBrandingResolver` (new, read-only, no
  writes, no `flush()`):
  - `forTrainer(User $trainer): TrainerBranding` -- reads
    `ProfileRepository::findTrainerProfile()`; both columns null yields
    `TrainerBranding::platformDefault()`; otherwise builds the value object,
    deriving `contrastColorHex` via `ContrastColor::forBackground()` and
    `logoUrl` via the `app_branding_logo` route (Task 15) when `logoKey` is
    set.
  - `forViewerChrome(User $viewer): ?TrainerBranding` -- tier A, a *total*
    function with no fallback: `TRAINER` -> `forTrainer($viewer)`; `COACH`
    -> `TrainerCoachAssociationRepository::findActiveForCoach($viewer)`'s
    trainer, via `forTrainer()` (at most one row, by S3's partial unique
    index -- named by index name in this method's docblock as the
    precondition, per the architecture's Risk); **every other role,
    including `PLAYER`, returns `null`**.
  - `forTrainers(array $trainers): array` -- tier B, batched over
    `ProfileRepository::findTrainerProfilesFor()`, keyed by trainer id.
  - No caching of any kind anywhere in this class -- AC-11 is satisfied by
    reading the row on the request that renders. (AC-6, AC-11, AC-12,
    NFR-001, D3, D3b, D3c)
- [x] 12. Create `App\Service\TrainerBrandingService`, the only writer of
  the two new columns:
  - `uploadLogo(User $trainer, UploadedFile $file, User $actor): string` --
    guard first (below); then `getimagesize()` on the uploaded file's
    temp path, refusing (new `App\Service\Exception\UnprocessableImageException`)
    when it cannot parse the file or either dimension exceeds 4000px;
    then `FileStorage::store($file, 'branding', maxBytes: 2 * 1024 * 1024,
    allowedMimeTypes: ['image/png' => 'png', 'image/jpeg' => 'jpg',
    'image/webp' => 'webp'])`; on success, capture the previous `logoKey`,
    write the new one, `flush()`, then delete the previous file if one
    existed -- store-then-clean order, matching
    `ProfileService::uploadPhoto()` exactly, so a failed flush leaves the
    old logo intact. Records `AccountEventType::PROFILE_UPDATED`
    post-commit.
  - `updateColor(User $trainer, TrainerBrandingRequest $request, User
    $actor): void` -- guard first; writes the already-normalised
    `primaryColorHex` (which may be `null`), flushes. Records
    `PROFILE_UPDATED`.
  - `removeLogo(User $trainer, User $actor): void` -- guard first; sets
    `logoKey` to `null`, flushes, then deletes the previous file. Records
    `PROFILE_UPDATED`.
  - `resetToDefault(User $trainer, User $actor): void` -- guard first;
    clears **both** `primaryColorHex` and `logoKey` in one flush, then
    deletes the logo file afterward if one existed. Records
    `PROFILE_UPDATED`. (AC-10, D1b)
  - Every method opens with the same guard: `$trainer` must be an active
    `UserRole::TRAINER` with a `ProfileTrainer`, and `$actor` must be
    `$trainer` or an active `SUPER_ADMIN`, else throws
    `BrandingActionNotPermittedException` -- defence in depth behind
    `BrandingVoter`, per S3/S5's convention, so a console command or future
    API controller cannot bypass the rule. (AC-2, AC-3, AC-4, AC-5, AC-8,
    AC-9, AC-10, BR-001, D2, D2b, D2c, D6, D7)

## GDPR-deletion cleanup fix (flagged Risk)

- [x] 13. Extend `App\Service\AccountLifecycleService` (S2)'s existing
  anonymize-in-place deletion flow with **one additive step**, mirroring
  the existing `photoKey` cleanup it already performs: when the target
  user has a `ProfileTrainer` with a non-null `logoKey`, delete the file
  via `FileStorage::delete()` and null the column, in the same
  transaction/order the existing `photoKey` cleanup uses. This closes the
  gap the architecture's Risks section names explicitly ("the logo file
  must be cleaned up by S2's GDPR deletion path, and nothing in that path
  knows about it yet") -- the same orphaned-file bug shape S2's own review
  already caught once for `photoKey`, now repeated for `logoKey` before it
  ships rather than after a second review catches it. (Risk: "logo file
  not cleaned up by S2's GDPR deletion path")

## Forms

- [x] 14. Create `App\Form\TrainerBrandingFormType` over
  `TrainerBrandingRequest`, in `ProfileTrainerFormType`'s established
  array-data style: `primaryColorHex` field using Symfony's `ColorType`,
  `required: false`, with `Symfony\Component\Validator\Constraints\Regex('/^#[0-9a-f]{6}$/i')`
  (human message: "Enter a valid hex color, e.g. #0b5fae.") and
  `Length(exactly: 7)`, both skipped when the value is empty/null. No logo
  field on this type -- the upload is its own action (Task 16). (AC-8,
  AC-9, D4b)

## Controllers -> routes

- [x] 15. Create `App\Controller\Trainer\BrandingController::edit()` at
  `GET|POST /trainer/branding` (`app_trainer_branding`), class-level
  `#[IsGranted('ROLE_TRAINER')]`. `denyAccessUnlessGranted('EDIT_BRANDING',
  $this->getUser())` before handling either verb. Binds
  `TrainerBrandingFormType` seeded from the current `primaryColorHex`;
  valid submit calls `TrainerBrandingService::updateColor()` and redirects
  back with a flash; renders `templates/trainer/branding/edit.html.twig`
  (Task 19) either way, passing the current `TrainerBranding` (via
  `TrainerBrandingResolver::forTrainer()`) for the live preview seed. Add a
  "Branding" nav link from the trainer dashboard/settings navigation to
  this route. (AC-1, AC-2, AC-8, AC-9)
- [x] 16. Add two more actions to `Trainer\BrandingController`:
  `uploadLogo()` at `POST /trainer/branding/logo`
  (`app_trainer_branding_logo`) and `reset()` at `POST
  /trainer/branding/reset` (`app_trainer_branding_reset`), both under the
  same class-level `#[IsGranted('ROLE_TRAINER')]` and each calling
  `denyAccessUnlessGranted('EDIT_BRANDING', $this->getUser())` plus a
  manual CSRF check in `ProfileController::uploadPhoto()`'s exact style
  (neither is a full form submit). `uploadLogo()` delegates to
  `TrainerBrandingService::uploadLogo()`, catching
  `FileTooLargeException`/`UnsupportedFileTypeException`/`UnprocessableImageException`
  into a flash error with no state change; `reset()` delegates to
  `resetToDefault()`. Both redirect back to `app_trainer_branding`. (AC-3,
  AC-4, AC-5, AC-10)
- [x] 17. Create `App\Controller\BrandingLogoController::show()` at `GET
  /branding/logo/{trainerId}` (`app_branding_logo`), under the existing
  `^/` authenticated catch-all (no `security.yaml` edit needed --
  confirm this in the same task). `denyAccessUnlessGranted('VIEW_BRANDING',
  $trainer)` (404, not 403, when the trainer id does not resolve to a
  `TRAINER` at all -- avoids leaking existence); 404 when `logoKey` is
  null; otherwise `FileStorage::read($logoKey)`, called from the
  controller, never returning a `Response` from the service (S1's rule).
  (AC-6, AC-7, NFR-002)
- [x] 18. Add `showForShareLink()` to `BrandingLogoController` at `GET
  /join/{code}/logo` (`app_share_link_logo`). Authorization is possession
  of the code, resolved through S3's existing `PlayerShareLinkResolver`
  exactly as `/join/{code}` itself resolves it -- no voter. 404 for an
  unknown/revoked/expired code or a trainer with no logo; otherwise
  `FileStorage::read()` on that trainer's `logoKey`. **Verify** that
  `security.yaml`'s existing `^/join` (or equivalent) `PUBLIC_ACCESS` rule
  already matches this sub-path; if it does not, widen that one existing
  pattern (never add a second rule) and re-run
  `tests/Functional/RouterSweepTest.php` (S1) to confirm it still passes
  with zero edits. (AC-6, AC-7, D8)

## Wiring branding into existing render surfaces (D3's three tiers)

- [x] 19. Create `templates/_branding.html.twig`: renders **nothing at
  all** unless a `branding` variable was passed to the template (inert by
  default, per D3c) -- guarded by `{% if branding is defined %}`. When
  present: an inline `style="--color-primary: {{ branding.primaryColorHex
  }}; --color-primary-contrast: {{ branding.contrastColorHex }};"` on one
  wrapping element, and, only when `branding.hasLogo`, an `<img>` pointing
  at `branding.logoUrl` with `max-height: 200px; max-width: 200px; width:
  auto;` inline/utility styling and appropriate `alt` text. `--color-focus`
  is never touched. Add one `{{ include('_branding.html.twig') }}` to
  `templates/base.html.twig`, after S6's impersonation-banner include and
  before `<main>` -- the one edit to that shared file, and it is inert on
  every page that passes no `branding` variable. (AC-6, AC-8, AC-11, D3c,
  D4, D4b, Risk: "`base.html.twig` is edited for the second time in two
  slices")
- [x] 20. **Tier A -- chrome, trainer's own pages.** In
  `App\Controller\Dashboard\TrainerDashboardController` and every other
  action under `App\Controller\Trainer\*` that renders a full page (audit
  the existing controllers under that namespace and list them explicitly
  in the diff), inject `TrainerBrandingResolver` and pass `'branding' =>
  $resolver->forViewerChrome($this->getUser())` into each `render()` call.
  One added argument and one array key per action -- no other logic moves.
  (AC-6, AC-11, AC-12, D3, D3c)
- [x] 21. **Tier A -- chrome, coach's pages.** In
  `App\Controller\Dashboard\CoachDashboardController` and
  `App\Controller\Coach\AvailabilityController` (audit for any other
  full-page coach action and list it explicitly), pass `'branding' =>
  $resolver->forViewerChrome($this->getUser())` the same way. This is the
  branch that depends on S3's active-coach partial unique index (at most
  one trainer per coach) -- add a one-line comment in the resolver, not
  here, naming that dependency (already covered by Task 11). (AC-6, AC-11,
  AC-12, D3, D3c)
- [x] 22. **Tier C -- ShareLink landing chrome.** In
  `App\Controller\PlayerShareLinkController`'s `follow()`/`register()`
  actions (already modified once by S3), pass `'branding' =>
  $resolver->forTrainer($shareLink->getTrainer())` into each `render()`
  call -- the epic's own headline scenario, reaching a viewer who is not
  yet a user. (AC-6, D3, D3c, D8)
- [x] 23. **Tier B -- per-row, roster and family surfaces.** In
  `App\Controller\Player\TrainerRosterController::index()`,
  `App\Controller\Trainer\PlayerRosterController::index()`, and the
  family child-trainer controller under `App\Controller\Family\*` (name it
  from the actual class -- confirm against `src/Controller/Family/`), call
  `TrainerBrandingResolver::forTrainers()` once per page with the full set
  of trainers already being rendered, and pass the resulting map into the
  template so each templated trainer row carries its own
  `TrainerBranding` (logo + color), rendered inline per row -- **no**
  `branding` chrome variable and **no** change to `_branding.html.twig`'s
  guard. Update `templates/trainer/player_roster/index.html.twig`,
  `templates/player/trainer_roster/index.html.twig` (or its actual path --
  confirm), and the family child-trainer template to render each row's
  branding. No N+1: exactly one `TrainerBrandingResolver::forTrainers()`
  call per page, backed by Task 3's batched repository method. (AC-6,
  AC-11, AC-12, D3, D3c, NFR-001)
- [x] 24. **Explicitly not branded** (no code change -- this task is the
  audit confirming the omission is deliberate, not missed): verify
  `/player` (`Player\*` dashboard controllers outside the roster),
  `/family/*` cross-child views outside the per-row roster, `/admin/*`,
  `/profile`, and every anonymous security page (`/login`, password reset,
  email verification) pass no `branding` variable and render platform
  default chrome. Record the confirmed list in this task's checkbox
  comment when checked off. (D3b, AC-12)

  **Confirmed (Task 24 audit, no code change):** `Dashboard\PlayerDashboardController::index()`
  (`/player`) and `Dashboard\AdminDashboardController::index()` (`/admin`)
  render their templates with no arguments at all; `ProfileController`'s
  actions (`/profile`) pass none; `Family\ChildTrainerController` (add,
  confirmRemove, remove) and `Family\ChildController`'s `create()`,
  `uploadPhoto()`, `enableSignIn()` -- the cross-child views outside the
  per-row roster on `family/index.html.twig` -- pass none; every anonymous
  security page (`/login`, password reset, email verification,
  `share_link/unavailable.html.twig` when the code itself fails to
  resolve, before any trainer is known) passes none. All render the
  platform default chrome via `_branding.html.twig`'s inert guard.

## Tests

- [x] 25. Functional -- **settings page and authorization**:
  `tests/Functional/TrainerBrandingSettingsTest.php`. A signed-in trainer
  reaches `GET /trainer/branding` and the page renders the `ColorType`
  input and the logo upload control (AC-1); a player, a coach, and a
  parent each get **403** (not a redirect) on `GET` and on forged `POST`s
  to all three write routes (AC-2); a Super Admin can edit a *named*
  trainer's branding via a route/form that accepts a target id, but a
  Super Admin hitting the self-service `/trainer/branding` route directly
  is refused by the class-level `ROLE_TRAINER` guard -- proving the voter,
  not the role attribute, carries the admin allowance; a deactivated
  trainer is refused; every write route 403s on a missing/incorrect CSRF
  token. (AC-1, AC-2, BR-001)
- [x] 26. Functional -- **logo upload**:
  `tests/Functional/TrainerBrandingLogoUploadTest.php`. A valid 1MB PNG
  saves and the key matches `branding/<32-hex>.png` (AC-3); a 3MB PNG is
  refused with the byte-cap error and `logo_key` is unchanged (AC-4); a
  GIF renamed `logo.png` is refused by content sniffing (AC-4); an SVG
  upload is refused with the unsupported-type error (D2); a valid
  1200x1200 PNG is *accepted* and rendered constrained rather than
  rejected (AC-5); a 6000x6000 PNG under 2MB is refused by the dimension
  guard (AC-5); uploading a second logo replaces the first and the
  previous file no longer exists on disk (edge case: logo replace); a
  trainer with no logo renders the platform placeholder with no broken
  `<img>` (edge case: no logo). (AC-3, AC-4, AC-5, AC-6)
- [x] 27. Functional -- **logo read authorization**:
  `tests/Functional/TrainerBrandingLogoReadTest.php`. An associated player,
  an associated coach, the parent of an associated child, the trainer
  themself, and a Super Admin each get `200` with image bytes from `GET
  /branding/logo/{trainerId}`; an unassociated player and an unassociated
  trainer get `403`/`404` per Task 17's chosen distinction; an anonymous
  request redirects to login; a trainer with no logo gives `404`; `GET
  /join/{code}/logo` succeeds **anonymously** for that code's trainer and
  `404`s for an unknown or revoked code. (AC-6, AC-7, NFR-002)
- [x] 28. Functional -- **color and reset**:
  `tests/Functional/TrainerBrandingColorTest.php`. A valid `#ff8800` saves
  and the next rendered trainer page carries `--color-primary: #ff8800`
  (AC-8); an uppercase `#FF8800` submission stores lowercased (D4b Risk);
  `ff8800` (no `#`), `#fff` (wrong length), and `#gggggg` (non-hex) are
  each refused and the previously saved color is unchanged (AC-9); two
  rapid saves of different colors leave the second value in effect (edge
  case); `reset` clears **both** columns -- asserted directly on the
  database row, not just the rendered page -- and the next render carries
  no override at all (AC-10). (AC-8, AC-9, AC-10)
- [x] 29. Functional -- **tier rule (AC-12's real test surface)**:
  `tests/Functional/TrainerBrandingTierResolutionTest.php`. A coach
  associated with trainer A sees A's branding in chrome; after that
  association ends and one to trainer B begins, the same coach sees B's
  branding with nothing cached (AC-11); a player associated with both A
  and B sees **neither** trainer's branding in chrome on `/player` and
  sees **both**, each beside its own trainer's row, on the roster page
  (AC-12); a trainer never sees another trainer's branding on any page; a
  parent's cross-child view renders platform default chrome; the
  ShareLink landing page for A's code shows A's branding to a visitor who
  is not signed in, and to a signed-in player of B (no bleed). (AC-6,
  AC-11, AC-12, BR-002, D3, D3b)
- [x] 30. Functional -- **immediacy**: extend
  `TrainerBrandingTierResolutionTest.php` or a sibling. A trainer saves a
  new color in one session; a player's already-authenticated session,
  with no logout and no cache clear, renders the new color on its very
  next request to a branded surface (AC-11).
- [x] 31. Functional -- **GDPR deletion cleanup**: extend the existing S2
  account-deletion functional test (or add
  `tests/Functional/TrainerBrandingDeletionCleanupTest.php` if none
  exists) with a trainer who has an uploaded logo: run
  `AccountLifecycleService::delete()` (or its console/controller entry
  point) against that trainer and assert the logo file no longer exists on
  disk and `logo_key` is null/gone, alongside the existing `photoKey`
  assertion continuing to pass unedited. (Risk: "logo file not cleaned up
  by S2's GDPR deletion path")
- [x] 32. Unit tests: `tests/Unit/Branding/ContrastColorTest.php`,
  parameterized across white, black, `#0b5fae`, a pale yellow (must choose
  the dark text), a mid-grey on either side of the crossover, and the
  three primaries, asserting the chosen pair meets 4.5:1.
  `tests/Unit/Branding/TrainerBrandingTest.php`: `platformDefault()`
  returns `#0b5fae`/`#ffffff` and `hasLogo() === false`.
  `tests/Unit/Service/TrainerBrandingRequestTest.php`: trim, lowercase, and
  `''`-to-`null` normalisation. (D4, D4b)
- [x] 33. Unit test,
  `tests/Unit/Service/TrainerBrandingResolverTest.php`:
  `forViewerChrome()` parameterized to return `null` for a player, a
  parent, an admin, and an anonymous token, and the correct trainer for a
  trainer and for a coach -- this table *is* D3, and must include an
  explicit assertion that `findActiveForCoach()` (S3) returns at most one
  row, guarding the precondition the architecture's Risks section flags.
  (D3, Risk: "tier A depends on a database fact a future slice could
  change")
- [x] 34. Unit test, `tests/Unit/Security/BrandingVoterTest.php`:
  parameterized over every role x active/deactivated x
  self/associated/parent-of-associated/unassociated combination for both
  `EDIT_BRANDING` and `VIEW_BRANDING`, including the explicit assertion
  that `ROLE_SUPER_ADMIN` grants `EDIT_BRANDING` only through its own
  clause under the flat `role_hierarchy` (matching
  `ImpersonationVoterTest`'s shape from TASK-006). (AC-2, BR-001, D5)
- [x] 35. Repository/schema integration test, against the real database,
  `tests/Repository/TrainerBrandingConstraintsTest.php`: the `CHECK`
  refuses a direct insert of `'red'`, `'#FFF'`, and `'#GGGGGG'` into
  `primary_color_hex`; `UNIQUE (user_id, type)` still refuses a second
  `ProfileTrainer` for one user (unchanged S2 invariant, re-asserted
  because this migration touches the same table);
  `findTrainerProfilesFor()` returns one row per trainer for a 10-trainer
  page in **one** query, asserted by query count (NFR-001, no N+1);
  `doctrine:schema:update --dump-sql` reports "Nothing to update" on a
  **second** run. (AC-9, D1, NFR-001)

## Review and verification

- [x] 36. `code-reviewer` + `security-reviewer` pass over the full slice,
  with explicit attention to: `FileStorage::store()`'s widened signature
  against every existing call site (confirm S2's `uploadPhoto()` call text
  is untouched); the SVG-refusal path end-to-end (confirm no code path
  ever stores or serves an `image/svg+xml` file); `BrandingVoter`'s truth
  table against the flat `role_hierarchy`; `BrandingLogoController`'s two
  routes against NFR-002 (no directly browsable static path, no
  trainer-id enumeration via response-code difference); the
  `/join/{code}/logo` route's `security.yaml` coverage (confirm no new
  `access_control` line was needed, or that exactly one existing pattern
  was widened); `AccountLifecycleService`'s new logo-cleanup step against
  a trainer who is *also* a coach-context viewer of another trainer (no
  cross-contamination); and a specific diff review confirming
  `templates/base.html.twig`'s new include is provably inert on every page
  that was rendering correctly before this slice (S6's impersonation
  banner test must still pass unedited).
- [x] 37. Full regression: `bin/phpunit` -- S1's AC-1...AC-25, S2's
  AC-1...AC-24, S3's AC-1...AC-21, S4's AC-1...AC-24, S5's AC-1...AC-16,
  and S6's AC-1...AC-14 must still hold, with particular attention to
  `RouterSweepTest`, S2's photo-upload suite (must pass with **zero test
  edits**, proving D2b), and S6's impersonation-banner rendering test
  (must still render correctly beside the new branding include). Confirm
  `doctrine:schema:validate` is clean and `schema:update --dump-sql`
  reports "Nothing to update" twice in a row.

## Coverage check

**Every AC cited by at least one task** (mechanically re-derived from the
`(AC-N, ...)` citations actually printed in each task above):

AC-1: 15, 25. AC-2: 9, 10, 12, 15, 16, 25, 34.
AC-3: 14, 16, 26. AC-4: 12, 16, 26.
AC-5: 12, 16, 26. AC-6: 11, 17, 18, 19, 20, 21, 22, 23, 26, 27, 29, 30.
AC-7: 9, 17, 18, 27. AC-8: 12, 14, 15, 19, 28.
AC-9: 2, 8, 12, 14, 28, 35. AC-10: 2, 6, 12, 13, 28.
AC-11: 3, 11, 19, 20, 21, 23, 29, 30, 35.
AC-12: 11, 20, 21, 23, 24, 29.

Every one of AC-1...AC-12 is cited by at least one task. No criterion is
unclaimed.

**Every task cites at least one real AC, or a named Decision/Risk:** true
for all 37 tasks above. Nine cite a Decision/Risk instead of (or in
addition to) an AC because they are schema/infrastructure/gate/audit/review
tasks with no criterion strictly their own: Task 1 (D1 -- schema shape),
Task 4/5 (the flagged shared-file `FileStorage` widening + its own
verification gate, mirroring TASK-006's Task 6 shape), Task 13 (the
GDPR-cleanup Risk named explicitly in the architecture doc), Task 24 (D3b
-- the deliberate-omission audit), Task 31 (the same GDPR-cleanup Risk,
proven by test), Task 33 (the tier-A database-fact Risk), and Tasks 36--37
(the dual review/regression gate, the same shape as TASK-006's Tasks
34--35).

**The two decisions the spec/architecture flagged as central are each
implemented by named tasks, not left implicit:**

- **D2 (SVG refused)** -- Task 12 (the allow-list passed to `FileStorage`
  excludes `image/svg+xml`), proven by Task 26's explicit SVG-refusal
  assertion. The two-part future condition (sanitiser + CSP/nosniff
  headers) is **not** implemented here, per the architecture's own
  decision -- it is a flagged Risk for a client decision, not a task in
  this plan.
- **D3 (no ambient trainer context; explicit three-tier resolution)** --
  Tasks 11 (the resolver, all three tiers as three distinct methods), 19
  (the inert `_branding.html.twig` include), 20--23 (each tier wired into
  its own named controllers/templates as its own task), 24 (the
  deliberate-omission audit). Proven by Tasks 29--30, 33.
- **GDPR logo-cleanup fix (flagged Risk, decided yes per this task's
  instruction)** -- Task 13, proven by Task 31.
- **`FileStorage` widened, not forked or globally tightened (D2b)** --
  Task 4, proven not to regress S2 by the standalone gate at Task 5 and
  again by Task 37's zero-edit regression run.

**The shared-file sequencing risk is addressed by a named task, not left
implicit:** Task 5 is that task, and it is a hard gate -- Task 6 onward
must not start until Task 5's regression check is green with zero S2 test
edits.

**No gap found in either direction** during this planning pass: every
AC-1...AC-12 is claimed by at least one task, every task above cites at
least one AC or names the Decision/Risk it protects, and no task in this
plan edits any S1--S6 frozen entity, enum case, or migration beyond the
two additive `ProfileTrainer` columns and the one additive
`AccountLifecycleService` cleanup step.
