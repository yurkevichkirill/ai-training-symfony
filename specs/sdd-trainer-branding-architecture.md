# Design: Trainer Portal Branding (Epic-01, slice S7)

> Answers *how*. The *what* and *why* live in `specs/sdd-trainer-branding-spec.md`
> (AC-1…AC-12); this file does not restate them. Governed task: TASK-007. Feature slug:
> `trainer-branding`.
>
> Builds on six shipped slices — `specs/auth-foundation-architecture.md` (S1),
> `specs/sdd-user-management-architecture.md` (S2),
> `specs/sdd-sharelink-invitations-architecture.md` (S3),
> `specs/sdd-player-family-availability-architecture.md` (S4),
> `specs/sdd-coach-features-architecture.md` (S5),
> `specs/sdd-admin-impersonation-architecture.md` (S6). Nothing they froze is edited:
> `User`, `Profile` and its discriminator map, `ProfilePlayer`, `ProfileCoach`,
> `TrainerPlayerAssociation`, `TrainerCoachAssociation`, `PlayerShareLink`,
> `ChildAccount`, `AccountEvent` and `UserRole` keep every column and every case they
> already have. This slice adds **two columns to one existing table**, one new service,
> one new voter, one new form type, one new value class, three new routes on two new
> controllers, one defaulted-parameter widening of one existing service method, one
> additive repository method, and one Twig partial. **No new table, no new entity, no new
> `AccountEventType` case, no email, no Messenger message, no rate limiter, and no
> Composer package.**
>
> **Ground truth re-verified against source, not against the docs.** Six facts shape this
> design and four of them change what the spec assumed was possible:
>
> 1. **There is no "current trainer portal" concept in this codebase, at all.** Verified
>    three ways. (a) Every route is prefixed by the *viewer's role*, never by a tenant:
>    `/trainer/*`, `/coach/*`, `/player/*`, `/family/*`, `/admin`, plus `/join/{code}`.
>    There is no `/t/{trainer}/…` segment, no subdomain resolution, and no request
>    attribute naming a trainer anywhere. (b) `templates/base.html.twig` has **no header
>    and no navigation chrome whatsoever** — it is `<head>`, a skip link, S6's
>    impersonation-banner include, and one `<main>`. The "header" AC-6 puts a logo in does
>    not exist yet. (c) There is **no `src/Twig/` directory, no Twig extension, and no
>    Twig global** anywhere in the project, and `PlayerContextProvider` (S4) deliberately
>    returns a *list* of `PlayerContext`s and never selects one — it is a data source for
>    a selector widget, not a resolved ambient context. So the spec's "does every page
>    need a shared Twig global providing the active trainer" has an answer this slice can
>    give honestly: **no, and building one would be inventing plumbing this slice did not
>    ask for.** See **D3**.
> 2. **Neither GD nor Imagick is installed** (`extension_loaded('gd')` and
>    `extension_loaded('imagick')` both `false`; `composer.json` requires only
>    `ext-ctype` and `ext-iconv`). There is no image-processing capability in this project
>    and no package that would provide one. AC-5's "auto-resize" therefore cannot be a
>    server-side pixel operation without adding an extension to the deployment contract —
>    see **D2c** for what is built instead. `getimagesize()` *is* available (it lives in
>    PHP's always-on standard extension, not in GD), which is what makes the
>    dimension guard in D2c possible with no new dependency.
> 3. **The platform default primary colour already exists and is already a CSS custom
>    property.** `public/css/app.css` declares `:root { --color-primary: #0b5fae;
>    --color-primary-contrast: #ffffff; --color-focus: #0b5fae; … }`. The spec's open
>    question "architecture must pick a concrete default hex" is answered by *reading*
>    rather than deciding: the default is `#0b5fae`, it is already the single source of
>    that colour, and overriding branding is one inline custom-property declaration. See
>    **D4**.
> 4. **`FileStorage::store()` takes no per-call limits**, and its `ALLOWED_MIME_TYPES` is
>    `image/jpeg`/`image/png`/`image/webp` with `MAX_BYTES = 5 * 1024 * 1024` as class
>    constants. Its discipline is otherwise exactly what this slice needs: content-sniffed
>    (`UploadedFile::getMimeType()`, finfo-backed) rather than extension-trusted, opaque
>    `<prefix>/<random-hex>.<ext>` keys, storage under `var/uploads` outside `public/`.
> 5. **`FileStorage::read()` returns a `BinaryFileResponse` with
>    `DISPOSITION_INLINE`** and no `Content-Security-Policy` header of any kind. This is
>    the load-bearing fact behind the SVG decision (**D2**): the logo endpoint's URL is
>    directly navigable by any authorised viewer, so "we will only ever render it inside
>    `<img>`" is not a control this architecture can enforce.
> 6. **A coach has exactly one trainer at a time, as a database fact.** S3's partial
>    unique index `uniq_trainer_coach_active_coach (coach_id) WHERE ended_at IS NULL`
>    guarantees `TrainerCoachAssociationRepository::findActiveForCoach()` returns at most
>    one row. A player does not have that guarantee (`TrainerPlayerAssociation` is
>    `UNIQUE(trainer, player)`, many trainers per player, by S3's design). That asymmetry
>    is what makes D3's tiering a rule rather than a preference.
>
> Also verified: `ProfileTrainer` already carries `businessName`, `website`, `address`,
> `description` and nothing else; `ProfileController` already renders a role-conditional
> `ProfileTrainerFormType` with its sibling writer at `POST /profile/business`, and
> already handles a multipart upload at `POST /profile/photo` as a **separate action**
> from the field forms; `role_hierarchy` is flat (`ROLE_SUPER_ADMIN: [ROLE_USER]`), so
> every admin allowance must be an explicit clause; `account_event.type` is
> `varchar(64)`, so a new case would be migration-free if one were needed (none is).

## Approach

Five shaping choices carry the slice.

1. **Branding is two nullable columns on `ProfileTrainer`, and `NULL` is the whole
   reset mechanism.** `logo_key` and `primary_color_hex` join `businessName`/`website`/
   `address`/`description` on the entity that is already this platform's organisation
   anchor. There is nothing about a logo that is a distinct *kind of profile*, so there
   is no new subtype and no new table; and because "no custom value" is represented by
   the absence of a value rather than by a stored default, AC-10's "no data implies a
   custom color is still active" is true by construction rather than by a cleanup step.

2. **Branding is read where a trainer is already known, and nowhere else.** Given fact 1,
   this slice adds no ambient context and no Twig global. It adds a resolver that answers
   one narrow question — *given a trainer, what is their branding* — plus an explicit
   three-tier rule for which surfaces have an unambiguous trainer to ask about. Page
   chrome is branded only on a trainer's own pages, a coach's pages (one trainer, by
   index), and a ShareLink landing page (one trainer, carried by the code). Everywhere a
   *set* of trainers is rendered, each trainer's branding is attached to that trainer's
   own row. Everywhere the answer would be a guess — a multi-trainer player's dashboard,
   a parent's cross-child view — branding is deliberately not rendered and the platform
   default stands. AC-12 then holds *structurally*: there is no code path in which one
   trainer's branding is fetched without that trainer being the thing being rendered.

3. **Raw SVG upload is rejected, deliberately and against the epic's own wording.**
   Not "sanitised later", not "`<img>`-only by convention" — refused, with the reason
   recorded and the client question flagged. The endpoint that serves logos returns
   `Content-Disposition: inline` with no CSP, from this application's own origin, at a
   URL any authorised viewer can navigate to directly. An SVG opened that way executes
   its embedded script *as this origin*, with the viewer's session cookie — a stored-XSS
   primitive handed to every trainer. No sanitisation step exists anywhere in this
   project (no `DOMDocument` scrubber, no `enshrined/svg-sanitize`, nothing in
   `composer.json`), so accepting SVG would mean shipping the vector and promising the
   control later. See **D2** for the two-part condition under which SVG becomes safe to
   add.

4. **The colour is applied as one CSS custom-property override, and the contrast pair is
   computed, not stored.** `--color-primary` already drives every accent in the
   stylesheet, so branding is `style="--color-primary: #…; --color-primary-contrast: #…"`
   on one element. The contrast value is derived from the chosen hex by a pure WCAG
   relative-luminance function, because a trainer picking a pale brand colour would
   otherwise render white-on-pale text and silently break the contrast discipline S1's
   AC-22 established. `--color-focus` is deliberately **not** overridden: focus
   visibility is not a trainer-tunable property.

5. **Authorisation is one voter plus a service guard, and the two read paths get
   different rules because they have different audiences.** Writing branding is the
   owning trainer or a Super Admin. *Reading* a logo is broad by design (AC-7: "visible
   to an entire organization rather than to one user") but still never a static file
   path — so the authenticated read is a voter check over the existing association
   tables, and the anonymous read a prospective player needs on a ShareLink page is a
   *separate route keyed by the ShareLink code*, which is already this codebase's
   capability token for "you may see this trainer".

## Components

### Entities and schema

**`App\Entity\ProfileTrainer`** (S2, extended — two additive columns, no other change)

| Column | Type | Notes |
|---|---|---|
| `logo_key` | `varchar(255)` NULL | the opaque `FileStorage` key (`branding/<32-hex>.<ext>`); `NULL` = no logo ever uploaded, which is the platform-placeholder state (AC-6, first edge case) |
| `primary_color_hex` | `char(7)` NULL | `#rrggbb`, lowercase; `NULL` = no override, so the stylesheet's `#0b5fae` stands (AC-10) |

One hand-written CHECK, in the style S4/S5 established for invariants DBAL does not
diff: `CHECK (primary_color_hex IS NULL OR primary_color_hex ~ '^#[0-9a-f]{6}$')`.
AC-9's "only a valid hex value can be saved" is therefore a database fact as well as a
form constraint and a DTO normalisation — the three-layer discipline S5's D3d recorded.
Accessors `getLogoKey()`/`setLogoKey()`/`getPrimaryColorHex()`/`setPrimaryColorHex()`
follow the file's existing plain-setter style.

**No new table.** **No new entity.** **No new `Profile` subtype** — see **D1**.

**No `logo_width`/`logo_height`/`logo_mime` columns.** The stored file's own bytes are
the authority; nothing in this slice queries or sorts by a logo's dimensions, and a
cached copy of a fact the file already carries is a fact that can go stale (D2c).

**Migration.** One migration, `Version…TrainerBranding`: two `ALTER TABLE profile_trainer
ADD COLUMN … NULL` statements plus the CHECK. Both columns are nullable with no default,
so this is a metadata-only, non-rewriting, non-blocking change in PostgreSQL, and **no
data backfill runs** — an existing trainer with no branding is correctly represented by
two `NULL`s. Down-migration drops the constraint and both columns.
`doctrine:schema:update --dump-sql` must report nothing on a *second* run (S3's
CHECK-normalisation trap).

**No new `AccountEventType` case** — see **D7**.

### Controllers → routes

Three new routes on two new controllers. **`security.yaml` gains one line** — see the
note under the table.

| Route | Controller | Delegates to |
|---|---|---|
| `GET\|POST /trainer/branding` (`app_trainer_branding`) | `Trainer\BrandingController::edit` (new) | `TrainerBrandingService::updateColor` (AC-1, AC-8, AC-9) |
| `POST /trainer/branding/logo` (`app_trainer_branding_logo`) | `Trainer\BrandingController::uploadLogo` | `TrainerBrandingService::uploadLogo` (AC-3, AC-4, AC-5) |
| `POST /trainer/branding/reset` (`app_trainer_branding_reset`) | `Trainer\BrandingController::reset` | `TrainerBrandingService::resetToDefault` (AC-10) |
| `GET /branding/logo/{trainerId}` (`app_branding_logo`) | `BrandingLogoController::show` (new) | `FileStorage::read` behind `BrandingVoter::VIEW_BRANDING` (AC-6, AC-7) |
| `GET /join/{code}/logo` (`app_share_link_logo`) | `BrandingLogoController::showForShareLink` | `PlayerShareLinkResolver` + `FileStorage::read` (AC-6 on the anonymous landing page) |

`Trainer\BrandingController` carries `#[IsGranted('ROLE_TRAINER')]` at class level (S1's
belt-and-braces rule, and under a flat `role_hierarchy` this refuses a Super Admin by
itself) plus `denyAccessUnlessGranted(BrandingVoter::EDIT_BRANDING, $trainer)` per
action, which is what carries the Super Admin allowance and the active-account half.
Every write action asserts CSRF explicitly — `uploadLogo` in the manual style
`ProfileController::uploadPhoto()` already uses for its multipart action, `reset` the
same way, since neither is a full form submit.

The colour form and the logo upload are **separate actions on one page**, mirroring
`/profile` exactly: a field form (`POST` back to `/trainer/branding`) beside a multipart
upload action (`POST /trainer/branding/logo`). This is not a stylistic echo — mixing a
file field into a form type whose failure mode is a flash message and whose validation
lives in `FileStorage` exceptions is precisely what S2 avoided, and repeating it keeps
one error-handling shape for uploads in the project.

`security.yaml` needs `- { path: ^/join/, roles: PUBLIC_ACCESS }` to already cover
`/join/{code}/logo`; **verify it does** — S3's existing `^/join` rule is expected to
match the new sub-path with no edit, in which case S1's `RouterSweepTest` passes
untouched and this line is a no-op. If S3's rule is narrower than `^/join/`, this slice
widens that one pattern and nothing else. `/branding/logo/{trainerId}` falls under the
existing `^/` authenticated catch-all.

Modified existing surfaces, all additively and all in tiers defined by **D3**:

- **`templates/base.html.twig`** — one `{{ include('_branding.html.twig') }}`, following
  S6's single-include precedent. Unlike S6's banner, this include renders **nothing at
  all** unless the template was passed a `branding` variable, so it is inert on every
  page that does not opt in. It emits the `--color-primary`/`--color-primary-contrast`
  override and, when a logo exists, the `<img>`. This is the one edit to a frozen S6
  file, and it is one line.
- **`Dashboard\TrainerDashboardController`, `Dashboard\CoachDashboardController`,
  `Trainer\*` (3 controllers), `Coach\AvailabilityController`** — each passes
  `branding` from `TrainerBrandingResolver::forViewerChrome($user)` into its `render()`
  call (tier A). One added constructor-less service argument and one array key each; no
  logic moves.
- **`PlayerShareLinkController::follow`/`register`** — pass
  `TrainerBrandingResolver::forTrainer($shareLink->getTrainer())` (tier C). This is the
  epic's own headline scenario ("Players see 'Elite Basketball Academy' branding") at
  its most literal, and it is the one surface where branding reaches someone who is not
  yet a user.
- **`Player\TrainerRosterController::index`, `Trainer\PlayerRosterController::index`,
  `Family\ChildTrainerController`** — per-row branding (tier B): each rendered trainer
  carries its own logo, via one batched
  `ProfileRepository::findTrainerProfilesFor(array $users)` call for the whole page. No
  chrome change, no `branding` variable, no N+1.

**Not branded, deliberately, and named here so the omission is visible:**
`/player` (a player may have many trainers), `/family/*` cross-child views, `/admin/*`,
`/profile`, and every anonymous security page (`/login`, reset, verification). See
**D3b**.

### Services

**`TrainerBrandingService`** (new)
- `uploadLogo(User $trainer, UploadedFile $file, User $actor): string` — validates and
  stores through `FileStorage::store($file, 'branding', maxBytes: 2 * 1024 * 1024,
  allowedMimeTypes: self::LOGO_MIME_TYPES)` (D2b), runs the `getimagesize()` dimension
  guard (D2c) **before** the store call, writes `logo_key`, `touch()`es, flushes, then
  deletes the previous key — the replace-then-clean order `ProfileService::uploadPhoto()`
  already uses, and the order that makes a failed flush leave the *old* logo intact
  rather than no logo at all (spec edge case 2, and S2's own orphaned-photo review
  finding).
- `updateColor(User $trainer, ?string $hex, User $actor): void` — takes the DTO's
  already-normalised value (lowercased, `''` → `null`), writes it, flushes. A `null`
  here is a legitimate save meaning "no override", which is why AC-10 needs no separate
  mechanism for the colour half.
- `removeLogo(User $trainer, User $actor): void` — sets `logo_key` to `NULL`, flushes,
  then deletes the file.
- `resetToDefault(User $trainer, User $actor): void` — AC-10. Clears `primary_color_hex`
  **and** `logo_key` in one transaction and deletes the logo file afterwards, so the
  trainer is returned to the exact state a trainer who never customised anything is in.
  There is no third "customised but reverted" state to represent.
- Every method opens with the same service guard: the target must be an active
  `UserRole::TRAINER` with a `ProfileTrainer`, and `$actor` must be that trainer or a
  Super Admin, else `BrandingActionNotPermittedException`. Defence in depth per S3's Q4
  and S5's D4 — the voter gives the clean 403 at the HTTP edge, the guard is what
  survives a console command, a future API controller, or a request that never passes
  through the annotated action.
- Records `AccountEventType::PROFILE_UPDATED` post-commit on every write (D7).

**`TrainerBrandingResolver`** (new, read-only, no writes, no `flush()`)
- `forTrainer(User $trainer): TrainerBranding` — the one narrow question. Reads
  `ProfileRepository::findTrainerProfile()` and builds the value object. A trainer with
  neither column set yields `TrainerBranding::platformDefault()`.
- `forViewerChrome(User $viewer): ?TrainerBranding` — tier A only, and it is a *total*
  function with no fallback: `TRAINER` → their own branding; `COACH` →
  `TrainerCoachAssociationRepository::findActiveForCoach($viewer)`'s trainer (at most one
  row, by index — fact 6); **every other role, including a `PLAYER`, returns `null`**.
  Returning `null` rather than guessing is the entire content of D3 expressed as a
  signature.
- `forTrainers(array $trainers): array` — the batched tier-B form, keyed by trainer id,
  over one `findTrainerProfilesFor()` query.
- **No caching of any kind.** AC-11's "no publish delay, no cache-clear, no re-login" is
  satisfied by reading the row on the request that renders the page. NFR-001 is satisfied
  because that read is a single indexed lookup on `profile.user_id` (already `UNIQUE
  (user_id, type)` from S2) served from Doctrine's identity map for the rest of the
  request, and because the multi-trainer surfaces batch.

**`App\Service\FileStorage`** (S2, extended — **no behaviour change to existing callers**)
- `store(UploadedFile $file, string $prefix, ?int $maxBytes = null, ?array $allowedMimeTypes = null): string`
  — two defaulted trailing parameters. `null` means "use the class constant", so
  `ProfileService::uploadPhoto()`'s existing `store($file, 'photos')` call is unchanged
  in text and in behaviour, and S2's photo tests pass with zero edits. The 2MB cap and
  the logo allow-list become the *caller's* stated policy rather than a global one,
  which is what makes two different upload policies coexist without either being a
  special case. See **D2b**.

**`App\Service\ProfileService`** (S2) — **not modified.** Branding is not self-service
profile editing: every existing `ProfileService` method is keyed on "the signed-in user's
own profile", and branding has a Super-Admin-acts-on-a-trainer path. See **D6**.

### Value layer

**`App\Branding\TrainerBranding`** (new, final, readonly, Doctrine-free):
`{?string logoUrl, string primaryColorHex, string contrastColorHex}` plus
`platformDefault()` and `hasLogo()`. Holds no entity, so a template can never reach
through it into the ORM, and it is unit-testable with no kernel.

**`App\Branding\ContrastColor`** (new, final, stateless, dependency-free):
`static function forBackground(string $hex): string` — WCAG 2.x relative luminance
(sRGB linearisation, the 0.2126/0.7152/0.0722 weights), returning `#ffffff` or
`#1a1a1a` (the stylesheet's own `--color-text`) for whichever gives the higher contrast
ratio against the chosen colour. Pure integer-and-float arithmetic, no dependency, and
the whole reason a trainer cannot ship an unreadable button by picking pale yellow.

**`App\Service\TrainerBrandingRequest`** (new, plain readonly DTO, constructor-normalising
in the `ProfileCoachRequest` style): trims the hex, lowercases it, maps `''` to `null`.
One place, not per-caller.

### Forms and validation

**`TrainerBrandingFormType`** over the DTO, in `ProfileController`'s established
array-data style:
- `primaryColorHex` — `ColorType`, `required: false`, `Regex('/^#[0-9a-f]{6}$/i')` with
  a human message, `Length(exactly: 7)`. `ColorType` renders `<input type="color">`,
  which is the epic's "color picker" natively, emits `#rrggbb` by construction, and
  degrades to a text field where unsupported — with the `Regex` as the server-side
  authority either way (AC-9).
- **No logo field on this type.** The upload is its own action (see Controllers).

**Logo validation, in order, all server-side and none of it trusting the client:**
1. `2 * 1024 * 1024` byte cap — `FileStorage`'s own check via the new `maxBytes`
   argument, raising the existing `FileTooLargeException` (AC-4).
2. Content-sniffed MIME against `['image/png' => 'png', 'image/jpeg' => 'jpg',
   'image/webp' => 'webp']`, raising the existing `UnsupportedFileTypeException`. WebP
   comes along free because `FileStorage` already allows it and it is a safe raster
   format; **`image/svg+xml` is absent, deliberately** (D2). A `.png`-renamed file of any
   other type fails here, which is the spec's third edge case (AC-4).
3. `getimagesize()` must parse the file *and* report both dimensions ≤ 4000px, else
   `UnprocessableImageException` (new, beside the two existing exceptions). This is a
   second, independent decoder's opinion on top of finfo's, and a decompression-bomb
   guard: a 40-megapixel PNG can sit well under 2MB.

All three failures produce a flash error and **change nothing** — the file is never
moved, `logo_key` is never written, and the existing logo stays exactly as it was
(AC-4's "no logo is changed by a rejected attempt").

**Previews (AC-3, AC-8)** are progressive enhancement in the branding template's
`{% block javascripts %}`: a `FileReader` sets an `<img>`'s `src` for the picked file,
and an `input` listener sets `--color-primary` on the preview container. No Stimulus, no
importmap, no package — there is none of that in this project today. Both previews are
purely local to the page; nothing is uploaded or persisted until the relevant action is
submitted, and the page works fully without JavaScript (you simply save and then see the
result), which is the same accessibility stance S1's Twig surface took.

### Authorization

One new voter. It reads `User::role`, `User::status`, `TrainerPlayerAssociation`,
`TrainerCoachAssociation` and `ChildAccount` — **no `Profile` is read**, so S1's frozen
"authorization never reads a Profile" invariant holds even though the data being guarded
lives on one.

| Voter | Attribute | Subject | Granted when |
|---|---|---|---|
| `BrandingVoter` | `EDIT_BRANDING` | `User` (the trainer) | the subject is an active `TRAINER` **and** (the token user *is* the subject, **or** the token user is an active `SUPER_ADMIN`) — AC-2, BR-001 |
| `BrandingVoter` | `VIEW_BRANDING` | `User` (the trainer) | the above, **or** the token user has an active `TrainerPlayerAssociation` or `TrainerCoachAssociation` with the subject, **or** is the parent of a child who has one (`ChildAccountService::findChildrenOf()` + `TrainerPlayerAssociationRepository::findActiveForPlayers()`) — AC-6, AC-7 |

The Super Admin clause on `EDIT_BRANDING` is written out because `role_hierarchy` is
flat — S5's fact 5, and the reason `#[IsGranted('ROLE_TRAINER')]` on the controller must
be paired with the voter rather than replaced by it. The precedent for an admin being
able to edit a trainer's org data at all is S2's admin-edits-any-account path, which the
spec's AC-2 names explicitly.

`VIEW_BRANDING` is deliberately *broad* and deliberately *not* a role check: a logo is
org-public by AC-7's own framing, but "org-public" is exactly a statement about
associations, and associations are what this attribute reads. Note what it does **not**
require: the *trainer's* status is checked for editing but an association-based read does
not depend on the viewer having any particular role, which is what makes a parent's read
work without a parent-specific branch.

The anonymous ShareLink read has no voter at all, by design: `/join/{code}/logo`'s
authorisation *is* possession of the code, resolved by S3's existing
`PlayerShareLinkResolver` on the same terms `/join/{code}` itself already uses. Adding an
`IS_AUTHENTICATED` requirement there would make the epic's headline scenario impossible;
adding a voter over an anonymous token would be re-implementing the resolver.

Defence in depth, per S3's Q4: every write rule exists as a voter **and** as a
`BrandingActionNotPermittedException` guard inside `TrainerBrandingService`.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| Branding settings page, colour save, reset | Controller | `Trainer\BrandingController` (new) |
| Logo upload action | Controller | `Trainer\BrandingController::uploadLogo` (new) |
| Authenticated logo read | Controller | `BrandingLogoController::show` (new) |
| Anonymous ShareLink logo read | Controller | `BrandingLogoController::showForShareLink` (new) |
| Branding writes, orphan cleanup, reset semantics, audit | Service | `TrainerBrandingService` (new) |
| Branding reads + the tier rule | Service | `TrainerBrandingResolver` (new) |
| Byte cap, MIME allow-list, opaque key, file move/delete | Service | `FileStorage` (S2, two defaulted parameters) |
| Dimension/decoder guard | Service | `TrainerBrandingService` + `UnprocessableImageException` (new) |
| Contrast pair derivation | Value layer | `App\Branding\ContrastColor` (new) |
| Render-ready branding shape | Value layer | `App\Branding\TrainerBranding` (new) |
| Hex trim/lowercase/`''`→`null` | Value layer | `TrainerBrandingRequest` (new DTO) |
| Audit write | Service | `AccountEventRecorder` (S2, unchanged) |
| Branding authorization | Security | `BrandingVoter` (new) |
| Trainer profile lookup, batched | Repository | `ProfileRepository` (S2, one additive method) |
| Association lookups for `VIEW_BRANDING` | Repository | `TrainerPlayerAssociationRepository`, `TrainerCoachAssociationRepository` (S3, unchanged) |
| Colour override + logo `<img>` | Template | `_branding.html.twig` (new) + one include in `base.html.twig` |

Transaction, controller, service and repository boundaries are unchanged from S1's rules:
one transaction per service method, controllers never `flush()`, services never return a
`Response` (the logo endpoints call `FileStorage::read()` from the *controller*, as
`PhotoController` already does), repositories never authorize.

### Tests this slice must produce

Functional — **settings and authorization**: a signed-in trainer reaches
`GET /trainer/branding` and the page renders the colour input and the upload control
(AC-1); a player, a coach and a parent each get a **403** — not a redirect — on `GET` and
on forged `POST`s to all three write routes (AC-2); a Super Admin can edit a named
trainer's branding (AC-2's admin clause) but is refused by `ROLE_TRAINER` on the
self-service route, proving the voter and not the role attribute carries that allowance;
a deactivated trainer whose session began before deactivation is refused (the voter's
`isActive()` half); every write route refuses a missing/incorrect CSRF token.

Functional — **logo**: a valid 1MB PNG saves and the key follows the
`branding/<32-hex>.png` shape (AC-3); a 3MB PNG is refused with the byte-cap message and
`logo_key` is unchanged (AC-4); a GIF renamed `logo.png` is refused by content sniffing
(AC-4, edge case 3); **an SVG is refused** with the unsupported-type message (D2); a
valid 1200×1200 PNG is *accepted* and rendered constrained rather than rejected (AC-5); a
6000×6000 PNG under 2MB is refused by the dimension guard; uploading a second logo
replaces the first **and the first file no longer exists on disk** (edge case 2); a
trainer with no logo renders the placeholder and no broken `<img>` (edge case 1).

Functional — **logo read**: an associated player, an associated coach, the parent of an
associated child, the trainer, and a Super Admin each get `200` with the image bytes from
`GET /branding/logo/{trainerId}`; an unassociated player and an unassociated trainer get
**403**; an anonymous request gets a redirect to login; a trainer with no logo gives
`404`; `GET /join/{code}/logo` succeeds **anonymously** for the code's trainer and `404`s
for an unknown or revoked code (AC-6, AC-7, NFR-002).

Functional — **colour and reset**: a valid `#ff8800` saves and the rendered page carries
`--color-primary: #ff8800` (AC-8); `ff8800`, `#fff`, `#gggggg` and a 40-character string
are each refused and the previously saved colour is unchanged (AC-9, edge case 5); two
rapid saves leave the second value (edge case 6); reset clears **both** columns, the
rendered page carries no override at all, and the DB holds two `NULL`s rather than a
stored `#0b5fae` (AC-10 — asserted on the column, since "no data implies a custom color"
is the actual requirement).

Functional — **the tier rule, which is AC-12's real test surface**: a coach associated
with trainer A sees A's branding in chrome; after that association is ended and one to
trainer B begins, the same coach sees **B's** branding, with nothing cached (AC-11); a
player associated with both A and B sees **neither** in chrome on `/player` and sees
**both, each beside its own trainer's row**, on `/player/trainers` (AC-12); a trainer
never sees another trainer's branding on any page; a parent's cross-child view renders
platform default chrome; the ShareLink landing page for A's code shows A's branding to a
visitor who is not signed in at all, and to a signed-in player of B.

Functional — **immediacy**: a trainer saves a colour in one session; a player's already
authenticated session, with no logout and no cache clear, renders the new colour on its
very next request to a branded surface (AC-11).

Unit: `ContrastColor::forBackground()` across white, black, `#0b5fae`, a pale yellow
(must choose the dark text), a mid grey either side of the crossover, and the three
primaries, with the chosen pair asserted to meet 4.5:1; `TrainerBranding::platformDefault()`
returning `#0b5fae`/`#ffffff` and `hasLogo() === false`; `TrainerBrandingRequest`'s trim,
lowercase and `''`→`null` normalisation; `TrainerBrandingResolver::forViewerChrome()`
returning `null` for a player, a parent, an admin and an anonymous token, and the right
trainer for a trainer and for a coach — parameterised, because this table *is* D3;
`BrandingVoter`'s truth table over every role × active/deactivated ×
self/associated/parent-of-associated/unassociated combination, including the explicit
flat-`role_hierarchy` assertion that `ROLE_SUPER_ADMIN` grants `EDIT_BRANDING` only
through its own clause.

Integration, against the real database: the `primary_color_hex` CHECK refuses a direct
bad insert (`'red'`, `'#FFF'`, `'#GGGGGG'`); `UNIQUE (user_id, type)` still refuses a
second `ProfileTrainer`; `findTrainerProfilesFor()` returns one row per trainer in **one**
query for a 10-trainer page, asserted by query count (NFR-001, no N+1);
`FileStorage::store()` with no `maxBytes`/`allowedMimeTypes` argument behaves byte-identically
to before this slice — a 4MB WebP still stores and a 6MB one still raises — which is the
regression that makes D2b's widening safe; `doctrine:schema:update --dump-sql` reports
nothing on a **second** run.

Regression: S1's AC-1…AC-25, S2's AC-1…AC-24, S3's AC-1…AC-21, S4's AC-1…AC-24, S5's
AC-1…AC-16 and S6's AC-1…AC-14 must still hold — in particular S2's photo-upload suite
with **zero test edits** (D2b), S6's impersonation-banner rendering after the second
`base.html.twig` include, and S1's `RouterSweepTest` against the `security.yaml` check
noted above.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| Two nullable columns on `profile_trainer` | — | Over a `trainer_branding` table or a new `Profile` subtype: branding is business-facing configuration for the organisation `ProfileTrainer` already *is*, sits beside `businessName`/`website`/`description` with the same lifetime and the same owner, and a separate table would add a join, a nullable one-to-one, and a second "does a row exist" state on top of the "is a column null" state that already answers AC-10. See **D1**. |
| `FileStorage` widened with two defaulted parameters | — | Over a parallel `LogoStorage` class: the content-sniffing, opaque-key generation, outside-`public/` placement and delete semantics are the whole value of the class and would all be copied. Over lowering `MAX_BYTES` to 2MB globally: that silently tightens S2's shipped photo upload, a behaviour change to a frozen slice. See **D2b**. |
| PNG/JPEG/WebP only — **SVG refused** | — | Over accepting SVG: no sanitiser exists in this project, and the mitigation the spec floated ("render only via `<img>`") does not apply, because the logo endpoint's own URL is directly navigable and `FileStorage::read()` sends `Content-Disposition: inline` with no CSP. See **D2**. |
| No image-processing library; CSS-constrained rendering + a `getimagesize()` guard | — | Over adding `ext-gd`/`ext-imagick` or `intervention/image`: neither extension is installed (verified), so this would be a deployment-contract change — a new required extension in `composer.json` and in every environment — bought for one 200px thumbnail that CSS renders correctly for free. `getimagesize()` needs no extension. See **D2c**. |
| One CSS custom-property override on an existing `--color-primary` | — | Over a per-trainer generated stylesheet, a `<style>` block of rules, or a build step: the variable already exists and already drives every accent, so the entire feature is two declarations. A generated stylesheet would need a cache, and a cache is exactly what AC-11 forbids. See **D4**. |
| No new Composer package | — | Every mechanism exists: `ProfileTrainer` for the columns, `FileStorage` for the upload, `ColorType` for the picker, `Regex` + a PostgreSQL CHECK for the format, `AccountEventRecorder` for audit, the association repositories for the read rule, `base.html.twig` for the single include. NFR-S7 confirmed. |

Not added: a Twig extension or global (fact 1 — and D3 explains why a global is actively
wrong here, not merely unnecessary); a session attribute or cookie remembering "the last
trainer I looked at" (D3c); an asset-versioning or CDN step (the logo is served through a
controller by NFR-002, so there is no static URL to version); a Messenger message (no
work here is slow or retryable — one small file move and one `UPDATE`); a rate limiter
(the only writer is an authenticated trainer editing their own row, and the 2MB cap plus
`upload_max_filesize` already bound the cost).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| **D1. (Spec's first delegated question) Where branding data lives** | **Two additive nullable columns on the existing `ProfileTrainer`** — `logo_key`, `primary_color_hex` | (a) a new `ProfileBranding extends Profile` subtype; (b) a separate `trainer_branding` table one-to-one with `profile_trainer`; (c) a `jsonb branding` column; (d) columns on `app_user` | The requirements analysis recommended (this) and nothing found in source argues against it, so it stands with the alternatives named. `ProfileTrainer` *is* this platform's organisation anchor — S3's associations, S5's roster and the epic's own "Elite Basketball Academy" all resolve to it — and a logo and a brand colour have exactly its owner, its lifetime and its deletion semantics. (a) misreads S1's frozen contract: a `Profile` subtype is "capability data for one role a User plays", and branding is not a role a user plays; it would also need a second `UNIQUE (user_id, type)` slot for a user who is already a `TRAINER`, which the base table's constraint forbids outright — the design would not even build. (b) adds a join and a nullable one-to-one to gain nothing: it introduces a *second* emptiness state ("no row" vs. "row with nulls") on top of the one AC-10 already needs, and every read would have to collapse them. (c) makes AC-9's hex format unCHECKable and unqueryable, in a project whose stated habit is that invariants are database facts. (d) edits the authentication table six slices depend on, to hold data one role has. |
| **D1b. How "no customisation" is represented** | `NULL` in both columns; **`resetToDefault()` clears both and deletes the file** | (a) store the platform default `#0b5fae` on reset; (b) an `is_customised` boolean; (c) soft-keep the old logo key for undo | AC-10 is written unusually precisely — "no data implies a custom color is still active" — and (a) fails it literally: a stored `#0b5fae` is indistinguishable from a trainer who deliberately picked the platform blue, so the platform could never change its own default without silently changing that trainer's brand. (b) adds a flag that can disagree with the column it describes. (c) invents an undo the spec never asks for and leaves a file whose only referent is gone — the orphan class S2 already had to fix once in review. |
| **D2. (Spec's headline safety question) SVG logo upload** | **Refused.** The allow-list is `image/png`, `image/jpeg`, `image/webp`. An SVG upload fails content sniffing with the existing `UnsupportedFileTypeException` | (a) accept SVG and render only via `<img src>`, never inline `<svg>`; (b) accept SVG and sanitise on upload with a hand-written `DOMDocument` scrubber; (c) accept SVG and add `enshrined/svg-sanitize` | **This deviates from US-01.14's own "Validation" wording, deliberately, and it is flagged as a client question in Risks rather than hidden.** (a) is the mitigation the spec floated and it does not hold in *this* codebase: NFR-002 requires the logo to be served through a controller, that controller returns `FileStorage::read()`'s `BinaryFileResponse` with `DISPOSITION_INLINE` and **no `Content-Security-Policy` header anywhere in the project**, and the endpoint URL is a normal, navigable, same-origin URL that any authorised viewer (or anyone who lands on a link to it) can open directly. Opened that way an SVG is a *document*, not an image: its embedded `<script>` runs as this origin with the viewer's session cookie. So "we only ever put it in an `<img>`" is a convention about one call site, not a control over the endpoint — and the whole point of AC-7's authorised read is that other people fetch this URL. (b) is a scrubber this project would have to write, review and maintain against a decade of known SVG bypasses (`<foreignObject>`, `xlink:href` javascript URIs, entity expansion/XXE, CSS `@import`, event attributes on any element) — a security control invented in a feature slice. (c) is the correct shape if SVG is genuinely required, and is still not taken *now* because it is a new dependency added to satisfy a requirement nobody has confirmed is real: a raster logo at 2MB serves every stated scenario, and no AC in the spec's own list mentions SVG (AC-3/AC-4 say "PNG, JPG, or SVG" only by inheriting the epic's sentence). **The two-part condition for adding it later:** (1) `enshrined/svg-sanitize` (or an equivalent maintained sanitiser) runs on upload and the *sanitised* output is what is stored, and (2) the logo response gains `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'` **and** `X-Content-Type-Options: nosniff`, so the endpoint is defended even if the sanitiser is bypassed. Either half alone is insufficient; both are one small PR once the client confirms the need. |
| **D2b. The 2MB cap vs. `FileStorage`'s shared 5MB constant** | **Two defaulted trailing parameters** on `store()`: `?int $maxBytes = null, ?array $allowedMimeTypes = null`, both falling back to the class constants | (a) lower `MAX_BYTES` to 2MB globally; (b) a `LogoStorage` class or a `FileStorage` subclass; (c) a `MAX_BYTES_BY_PREFIX` map keyed by the `$prefix` argument; (d) validate size/type in the branding service before calling `store()` | The spec's constraint is explicit: no behaviour change for S2's shipped photo upload. (a) breaks it outright — a 4MB photo that stores today would stop storing. (b) copies the four things `FileStorage` is *for* (finfo sniffing, opaque key generation, outside-`public/` placement, delete) so that two callers can disagree about two numbers. (c) hides policy in a lookup table inside the storage class, so a reader of the branding service cannot see what limit applies to it — and it couples the *directory name* to the *policy*, two things that have no reason to move together. (d) duplicates the byte check the class already performs and, worse, would have to re-sniff the MIME type to enforce the narrower list, leaving two sniff sites that can disagree. The chosen shape makes the limits the caller's stated policy at the call site, changes no existing call text, and is covered by an explicit regression test that unchanged calls behave identically. |
| **D2c. (AC-5) The auto-resize mechanism** | **No server-side resize.** Any dimensions within a sanity bound are accepted; the logo is rendered constrained by CSS (`max-height: 200px; max-width: 200px; width: auto`), and a `getimagesize()` guard refuses unparseable images and anything over 4000px on a side | (a) add `ext-gd` and resize on upload; (b) add `ext-imagick`; (c) add `intervention/image` or `imagine/imagine`; (d) reject images over 200×200 outright; (e) accept anything with no dimension check at all | **Neither GD nor Imagick is installed** and `composer.json` requires only `ext-ctype`/`ext-iconv` — verified, not assumed. (a)/(b)/(c) therefore all mean the same thing: a new required PHP extension in the deployment contract of every environment, plus a new failure mode (an unprocessable image *after* a successful upload, which the spec itself flags as needing defined behaviour), bought to produce a 200px thumbnail that the browser renders correctly from the original for free at 2MB. (d) contradicts AC-5's explicit "auto-resized rather than rejected on dimensions alone". (e) leaves a decompression-bomb hole: a 40-megapixel PNG sits comfortably under 2MB and can exhaust memory in any future consumer that ever *does* decode it. The chosen middle satisfies AC-5 as written — no image is rejected for its dimensions within a sane bound, and every accepted logo *displays* at the recommended 200px — while the `getimagesize()` guard costs nothing (standard-extension function, no GD) and adds a second, independent decoder's opinion behind finfo's. **Named consequence:** a trainer uploading a 3000×3000 logo transfers 2MB to every viewer on every branded page. Mitigation is one sentence of guidance on the form and a `loading="lazy"`-style HTTP cache header on the logo response; if it becomes a real problem, that is the moment to buy `ext-gd`, and the storage shape does not change when it happens. Flagged in Risks. |
| **D3. (The spec's central open question) How "active trainer" branding context is resolved** | **This codebase has no general "current trainer portal" concept, and this slice does not build one.** Branding is rendered in three explicitly enumerated tiers, from a trainer the surface *already* has: **A** — page chrome, only where the viewer's own identity determines exactly one trainer (a `TRAINER`; a `COACH`, by S3's partial unique index); **B** — per-row, on any page that renders a *set* of trainers, each trainer's branding attached to its own row; **C** — page chrome on `/join/{code}` and its register step, where the ShareLink carries exactly one trainer. Everywhere else the platform default renders and `forViewerChrome()` returns `null` | (a) a Twig global / request-scoped "active trainer" service consulted by `base.html.twig` on every page, with a fallback rule; (b) resolve from "the trainer whose page the viewer most recently navigated through", stored in the session; (c) extend S4's `PlayerContextProvider` to *select* a context and treat that selection as the branding context; (d) add a tenant path prefix (`/t/{trainerId}/…`) or subdomain routing and re-home existing routes under it; (e) a user-facing "which portal am I in" selector | This is the honest answer to a question the epic never asked, and the source is unambiguous: routes are prefixed by the viewer's *role*, never by a tenant; `base.html.twig` has no header or nav at all; there is no Twig extension, global, or request attribute naming a trainer anywhere; and `PlayerContextProvider` deliberately returns a list and never picks. There is no existing "which trainer's portal am I rendering" fact to read, so any global would have to *invent* one. (a) is the tempting shape and is the dangerous one: a global needs a fallback for a multi-trainer player, and every candidate fallback — first association, most recent association, alphabetically first — is a **guess that renders trainer A's brand to a viewer looking at their own page**, which is AC-12's failure mode arriving by default rather than by bug. (b) is the same guess with a session-shaped memory, and adds a genuine cross-tenant leak: a stale session value outliving an ended association would keep branding a former trainer's identity onto pages, and "most recently navigated" is unobservable on a fresh session, a bookmark, or an email link. (c) misuses S4's provider: it is the *data source for a selector widget* and its whole documented point (S4 AC-11) is that the lists are never merged or collapsed — collapsing it here would undo that in a different file. (d) is the architecturally "right" answer for a true multi-tenant portal and is exactly the plumbing the spec says this slice did not ask for: it re-homes every shipped route, invalidates every route name, every `redirectToRoute`, `security.yaml`'s path rules and S1's `RouterSweepTest`, and it is a slice of its own. (e) adds UI the epic never describes and asks users to answer a question about our data model. The chosen tiering satisfies AC-6 (the trainer's own portal, their coaches' pages, and the player-facing ShareLink landing page — *the epic's own scenario*, most literally, are all branded), AC-11 (no cache anywhere: the row is read on the request that renders), and AC-12 **structurally** — there is no code path in which branding is fetched for a trainer who is not the subject of what is being rendered, so there is nothing for a bleed to happen *through*. What it deliberately does **not** deliver is site-wide chrome branding for a multi-trainer *player* on their own dashboard. That is stated, not glossed: see **D3b**. |
| **D3b. What tier-A's `null` means for a multi-trainer player** | Platform default chrome on `/player`, `/family/*`, `/profile`, `/admin/*` and every anonymous security page — and the branding shown **per trainer row** on the pages where that player actually meets their trainers | (a) brand the dashboard with the player's only trainer when they have exactly one, `null` otherwise; (b) brand with an arbitrary/first trainer always | (a) is genuinely tempting and is rejected on one specific ground: it makes the branding of a player's *own* home page change the day they join a second trainer, with no action by them and no explanation available on the page. A user-visible identity that appears and disappears as a side effect of an unrelated association is worse than one that is consistently absent — and the moment there are two, the tier-A rule has to say `null` anyway, so (a) buys a special case for the single-trainer majority at the cost of making the rule discontinuous. (b) is AC-12's violation stated as a policy. The honest scope is worth writing down plainly: **a multi-trainer player never sees one trainer's brand as their own site chrome, and that is the design, not a gap.** If the client wants a branded player home, the missing ingredient is a *product* decision about what a player's home means in a multi-trainer platform — and then D3's tier A grows one clause. |
| **D3c. Where the branding value reaches the template** | Explicitly, as a `branding` render variable passed by each opted-in controller, consumed by an inert `_branding.html.twig` include in `base.html.twig` | (a) a Twig global via a `TwigExtension` implementing `GlobalsInterface`; (b) an `EventSubscriber` on `kernel.view`/`kernel.response` injecting it; (c) per-template includes with no base-template change | (a) and (b) both make branding *ambient*, which is precisely the property D3 argues against: an ambient value must answer on every page, so it must have a fallback, so it must guess. Passing it explicitly means a surface that has no unambiguous trainer simply does not pass it — the absence is visible in the controller, reviewable in a diff, and impossible to get wrong by default. It also keeps this project's zero-Twig-extension status quo (there is no `src/Twig/` today) rather than introducing that machinery for one variable. (c) is the rule someone will forget, which is the exact reasoning S6 recorded for the impersonation banner; the single inert include keeps that benefit while the explicit variable keeps the safety. |
| **D4. (Spec's delegated question) The platform default colour and how an override applies** | Default is **`#0b5fae`**, read from the existing `:root { --color-primary }` in `public/css/app.css` — not newly invented — and an override is `style="--color-primary: …; --color-primary-contrast: …"` on one element | (a) pick a new default hex in this slice; (b) a per-trainer generated CSS file served from a controller; (c) inline a `<style>` block of concrete rules; (d) a build step / asset compilation | The default did not need deciding, only finding: `--color-primary: #0b5fae` already exists and already drives every accent, focus colour and button in the shipped stylesheet, so (a) would create a *second* default that disagrees with the CSS for every unbranded page. (b) and (d) both need a cache or a compile to be worth doing, and a cache is what AC-11 explicitly forbids ("no publish delay, no cache-clear"); (b) also adds a second authorised-read endpoint for one string. (c) duplicates selectors that already exist and would drift from the stylesheet the first time a rule is added there. Two custom-property declarations is the whole feature. |
| **D4b. The contrast pair** | Derive `--color-primary-contrast` at render from the chosen hex via a pure WCAG relative-luminance function (`ContrastColor`); leave `--color-focus` at the platform value | (a) override only `--color-primary` and keep `--color-primary-contrast: #ffffff`; (b) let the trainer pick the contrast colour too; (c) validate the picked colour against a minimum contrast ratio and refuse pale colours | (a) is the silent-failure option: a trainer picking pale yellow or light cyan gets white text on it, which fails WCAG 1.4.3 outright and undoes the contrast discipline S1's AC-22 established across the whole surface. (b) hands an accessibility invariant to someone with no reason to know it exists, and doubles the form. (c) refuses colours the trainer legitimately owns as their brand — a pale brand is not an invalid brand — and would fail AC-9's "only a valid hex is rejected" framing by rejecting valid hexes. Deriving it means every brand colour is usable and none of them is unreadable. `--color-focus` stays platform-controlled because focus visibility is not a branding decision, and a trainer whose brand colour is near the page background would otherwise make the focus ring invisible. |
| **D5. (BR-001) Authorization shape** | One `BrandingVoter` with `EDIT_BRANDING` and `VIEW_BRANDING`, both over the trainer's `User` as subject, plus `#[IsGranted('ROLE_TRAINER')]` on the self-service controller and a `BrandingActionNotPermittedException` guard inside the service | (a) `access_control` path rules alone; (b) `#[IsGranted('ROLE_TRAINER')]` alone; (c) reuse `CoachVoter`/`FamilyVoter` with new attributes; (d) no voter on the read, just `ROLE_USER` like `PhotoController` | (a) cannot express "this viewer is associated with *this* trainer", which is the entire content of `VIEW_BRANDING`. (b) covers the write routes (and does, belt-and-braces) but says nothing object-level, and under a flat `role_hierarchy` it also *refuses the Super Admin* that AC-2 requires — so the admin allowance has to live in a voter regardless. (c) would put branding rules in classes whose documented subjects are coach associations and family relationships, and one of them would need three more repositories injected. (d) is what `PhotoController` does and is wrong here in the opposite direction: a photo is one user's, so "self or admin or parent" is enumerable inline, whereas a logo's audience is *every association a trainer has*, which is a rule with four branches and a parent case — the thing voters exist for. Following S5's D4 shape means a reader who knows `CoachVoter` already knows this one. |
| **D6. Where the write path lives** | A **new `TrainerBrandingService`**; `ProfileService` is not modified | (a) three more methods on `ProfileService` beside `updateTrainerDetails`/`uploadPhoto`; (b) logic in the controller | (a) is the near-miss: `ProfileService` already has `FileStorage` injected and already writes `ProfileTrainer`, so it *looks* like the home. But its own docblock-level invariant — the one `ProfileController` depends on for AC-14-style safety across two shipped slices — is that every method acts on the signed-in user's own profile, and branding has a Super-Admin-acts-on-a-named-trainer path (AC-2). Adding an actor-and-target pair to that class means either weakening that invariant for all of its methods or documenting an exception inside it. A separate service states its own rule cleanly, owns the branding-specific validation (2MB, dimension guard, hex normalisation) and the reset semantics, and leaves S2's shipped service byte-for-byte untouched. (b) puts file handling, orphan cleanup and audit in a controller. |
| **D7. Audit** | Reuse the existing `AccountEventType::PROFILE_UPDATED` for all three branding writes | (a) a new `TRAINER_BRANDING_UPDATED` case; (b) no audit at all, following `uploadPhoto()`'s precedent | (a) is migration-free (`varchar(64)`) and was considered; it is rejected because a trainer editing their branding is the same *kind* of event as a trainer editing their business name — S2's `PROFILE_UPDATED`, written through the same recorder for the same entity — and a branding-specific case would split one concept across two values for every future report that reads the timeline. This is S5's D6 reasoning applied again. (b) is `uploadPhoto()`'s stance and its stated reason ("a photo is not a PII disclosure or an access change") does not transfer: branding is configuration visible to an entire organisation, and it has a path where a *Super Admin* changes what a trainer's whole org sees — exactly the actor/subject asymmetry `AccountEvent` exists to record, and one S6's impersonator-attribution merge already enriches for free. |
| **D8. Anonymous logo access on the ShareLink page** | A **separate route**, `GET /join/{code}/logo`, authorised by possession of the code through S3's existing `PlayerShareLinkResolver` | (a) make `/branding/logo/{trainerId}` publicly readable; (b) require authentication and show no logo to prospective players; (c) a signed/expiring URL | (a) turns the logo endpoint into an enumerable probe over trainer ids for any anonymous visitor — and NFR-002's whole point is that no unauthenticated party constructs a path to org assets. (b) removes branding from the epic's single most-quoted scenario, the moment a prospective player first meets the trainer's identity. (c) invents a token mechanism next to one that already exists and already means exactly "you may see this trainer": the ShareLink code. Nesting the route under `/join/{code}` also means its authorisation, its 404 behaviour for a revoked or unknown code, and its `security.yaml` coverage are all inherited from a shipped, tested path rather than newly written. |

## Risks

- **SVG is refused, and the epic's text says it should be accepted.** This is the one
  place this design contradicts US-01.14's own words, and it is a product question, not
  a technical one: *does any real trainer need a vector logo?* **Ask the client before
  implementation, not after.** If the answer is yes, D2 names the exact two-part
  condition (a maintained sanitiser storing sanitised output, **plus** `Content-Security-Policy`
  and `X-Content-Type-Options: nosniff` on the logo response) and nothing in this
  design's schema, routes or services changes to accommodate it — only the allow-list
  entry and those two controls. Shipping SVG *without* both halves is the outcome this
  risk exists to prevent.
- **A branded page with a 3000×3000 logo transfers 2MB per viewer, per uncached page
  view.** The direct consequence of D2c's no-resize decision, and the reason the decision
  is written down rather than assumed. Cheapest early checks: guidance text on the upload
  control naming 200×200 as the recommendation (the epic's own number), and a
  `Cache-Control: private, max-age=…` header on the logo response so a viewer fetches it
  once per session rather than once per page. If it still hurts, buying `ext-gd` and
  resizing on upload is a self-contained change inside `TrainerBrandingService` — no
  column, route, or template moves.
- **The logo file must be cleaned up by S2's GDPR deletion path, and nothing in that path
  knows about it yet.** `AccountLifecycleService`'s anonymize-in-place flow handles
  `photoKey` because S2's review caught an orphaned-photo bug; `logo_key` is a second
  file reference on a second entity and it is **not** covered today. **Decide and
  implement this explicitly during implementation** rather than discovering it: exercise
  the deletion path against a trainer who has a logo and assert the file is gone. This is
  a known-shape repeat of a bug this project already paid for once.
- **`base.html.twig` is edited for the second time in two slices.** S6 added the
  impersonation banner include; this adds a branding include. The new include is inert
  without a `branding` variable, so no shipped page changes, but the file is now a
  contention point. Cheapest early check: a functional assertion that the impersonation
  banner still renders on a branded page and on an unbranded one — i.e. that the two
  includes are independent.
- **Tier A's rule depends on a database fact that a future slice could change.** A coach
  gets branded chrome *because* S3's partial unique index guarantees exactly one active
  trainer. If a later slice ever allows a coach to serve two trainers concurrently, that
  index goes and `forViewerChrome()` silently starts guessing. Mitigation: a unit test
  asserting `findActiveForCoach()` returns at most one row, and a comment in
  `TrainerBrandingResolver` naming the index by name as the precondition — so the day
  someone drops it, a test fails instead of a brand leaking.
- **The tier map is a rule that lives in prose plus five controller call sites.** A new
  trainer-facing controller added by a future slice will not be branded until someone
  remembers to pass the variable, and nothing fails when they forget. Deliberate — D3c
  argues the failure mode of forgetting (no branding) is far better than the failure mode
  of an ambient default (wrong branding). Cheapest mitigation: a one-line note in
  `TrainerBrandingResolver`'s docblock listing the tiers, and a functional test per tier
  so the *rule* is covered even though a future omission is not.
- **A deactivated trainer's branding keeps rendering.** The spec flags this and writes no
  rule; this design makes the choice explicit rather than incidental: rendering consults
  the `ProfileTrainer` row and never the trainer's status, so branding persists for
  existing associations. That is consistent with branding being a display concern and
  with S3's associations surviving deactivation, and it means a deactivated trainer's
  identity does not vanish from a player's page mid-relationship. Note the asymmetry
  deliberately: `EDIT_BRANDING` *does* require an active trainer, so a deactivated
  trainer's brand freezes rather than disappearing. If the client wants it to disappear,
  it is one clause in `TrainerBrandingResolver`, not a schema change.
- **`primary_color_hex` is validated in three places and they could disagree.** The
  `ColorType` widget, the DTO's normalisation, the `Regex` constraint and the PostgreSQL
  CHECK must all agree on lowercase 6-digit `#rrggbb`. The trap is a browser or a future
  form change emitting `#FFAA00`: the `Regex` is case-insensitive and would pass it while
  the CHECK would refuse it, turning a valid input into a 500. Mitigation, and it is
  load-bearing: the DTO lowercases **before** anything else sees the value, and an
  integration test submits an uppercase hex end-to-end and asserts it stores lowercased
  rather than erroring.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `Trainer\BrandingController::edit` at `GET /trainer/branding` + a nav link on the trainer dashboard | AC-1 |
| `#[IsGranted('ROLE_TRAINER')]` under a flat `role_hierarchy` + `BrandingVoter::EDIT_BRANDING` (with its explicit Super Admin clause) + `BrandingActionNotPermittedException` in `TrainerBrandingService` | AC-2 |
| `ColorType`/file control + `FileReader` preview in `{% block javascripts %}`; allow-list `image/png`/`image/jpeg`/`image/webp` with `maxBytes: 2MB` | AC-3 |
| `FileStorage`'s new `maxBytes` → `FileTooLargeException`; content-sniffed `allowedMimeTypes` → `UnsupportedFileTypeException`; both raised **before** `move()`, so `logo_key` is untouched | AC-4 |
| **D2c**: no dimension rejection within the 4000px sanity bound + CSS `max-height/max-width: 200px` rendering; `getimagesize()` guard → `UnprocessableImageException` only for unparseable or absurd images | AC-5 |
| `TrainerBrandingResolver` tiers A/B/C + `_branding.html.twig` included once in `base.html.twig`; branded for the trainer, their coaches, their ShareLink landing page, and per-row wherever a player/parent meets that trainer | AC-6 |
| `BrandingLogoController::show` behind `BrandingVoter::VIEW_BRANDING`, serving `FileStorage::read()` on an opaque `branding/<32-hex>.<ext>` key stored outside `public/`; `showForShareLink` authorised by the code | AC-7, NFR-002 |
| `TrainerBrandingFormType`'s `ColorType` + the inline `input`-listener preview setting `--color-primary` on the preview container | AC-8 |
| `Regex('/^#[0-9a-f]{6}$/i')` + `TrainerBrandingRequest`'s lowercase normalisation + `CHECK (primary_color_hex ~ '^#[0-9a-f]{6}$')`, with the previous value unchanged on refusal | AC-9 |
| `TrainerBrandingService::resetToDefault()` writing `NULL` to **both** columns and deleting the file (D1b) — so nothing persisted implies an active custom value | AC-10 |
| No cache anywhere: `TrainerBrandingResolver` reads the row on the rendering request, one indexed lookup (batched for lists) | AC-11, NFR-001 |
| **D3**: no ambient context, no fallback, no session memory — branding is only ever fetched for the trainer being rendered, and `forViewerChrome()` returns `null` rather than guessing | AC-12, BR-002 |
| Two additive nullable columns on `ProfileTrainer`, no new subtype (D1) | Spec's "Out of scope: any new `Profile` subtype" |

Edge cases, in the spec's table order:

1. **Branding saved with no logo ever uploaded** — `logo_key IS NULL`,
   `TrainerBranding::hasLogo()` is `false`, `_branding.html.twig` emits no `<img>` at all;
   there is no `src` to break.
2. **A logo replaced by a new upload** — `TrainerBrandingService::uploadLogo()` stores the
   new key, flushes, *then* deletes the previous file, so a failed flush leaves the old
   logo working rather than none; no orphan remains on success
   (`ProfileService::uploadPhoto()`'s exact order).
3. **A `.png`-renamed file of a disallowed type** — `UploadedFile::getMimeType()` is
   finfo-backed and content-sniffed; the extension never participates in the decision,
   and the stored extension is derived from the *sniffed* type.
4. **A viewer associated with two trainers who both customised branding** — D3's tiers:
   chrome is `null` for that viewer (D3b), and `/player/trainers` renders each trainer's
   own logo beside its own row from one batched query. No blend and no last-saved-wins,
   because no code path ever holds two candidate brandings for one surface.
5. **An invalid hex format** — refused by the `Regex` (and by the CHECK, and normalised by
   the DTO); the previously saved value, or the platform default when none, stays in
   effect.
6. **Two rapid colour saves** — a plain `UPDATE` of one column in one transaction per
   request; the second commit wins and there is no intermediate state to corrupt.
7. **A trainer's account is deactivated while branding is live** — decided explicitly in
   Risks rather than left open: rendering does not consult trainer status, so branding
   continues for existing associations, while `EDIT_BRANDING` requires an active trainer,
   so the brand freezes rather than vanishing.

**Every question the spec delegated to this phase is answered, and two answers are
deliberate refusals rather than mechanisms.** Branding-context resolution (**D3**, with
**D3b**/**D3c**) is resolved by establishing that no "current trainer portal" concept
exists in this codebase and scoping the slice to the surfaces that already know their
trainer — rather than inventing an ambient default whose only possible fallback is
AC-12's violation. **SVG (D2) is refused**, against the epic's own wording, with the
concrete reason (an inline-disposition, no-CSP, directly-navigable same-origin endpoint
makes `<img>`-only rendering unenforceable), the two-part condition for adding it, and a
client question raised in Risks. The 2MB cap (**D2b**), the auto-resize mechanism
(**D2c**), the platform default colour (**D4** — found in the stylesheet, not invented),
the storage shape (**D1**), the reset semantics (**D1b**) and trainer deactivation
(Risks) are each resolved with the rejected alternatives named. Nothing here is left
silently unanswered, and nothing is stubbed: the surfaces that are not branded are
enumerated as a decision, not omitted as an oversight.
