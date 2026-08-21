# Spec: Trainer Portal Branding (Epic-01, slice S7)

> Naming note: filed as `sdd-trainer-branding-spec.md`, consistent with S2–S6's `sdd-*`
> pairs. The feature slug is `trainer-branding` everywhere else (this file's body, the
> architecture file to follow, and its `specs/MANIFEST.md` row).
>
> Scope: **slice S7 only**, covering exactly one user story from
> `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`: US-01.14 (Trainer
> Customizes Portal Branding, lines 551–590), including its own "Validation" and
> "Scope for MVP" sub-blocks. Per the requirements analysis, this is the last remaining
> unbuilt item in Epic-01's in-scope MVP list — confirmed against the epic's §10
> Epic-Level Acceptance Criteria: "Trainer can generate ShareLinks" (shipped S3),
> "Trainer can view player Best Times" (shipped S4/S5 roster) are already accounted for
> and not re-scoped here; "Trainer can assign coaches to events" is explicitly named and
> deferred to Epic-02 by S5's own spec (no `Event` entity exists in this codebase) and is
> likewise not re-scoped here. Source: `tasks/TASK-007/requirements-analyst-requirements.md`'s
> FR-001…FR-004, NFR-001…NFR-002, BR-001…BR-002, and its Gap Analysis.
>
> Builds on three shipped, frozen slices: `specs/sdd-user-management-spec.md` /
> `-architecture.md` (S2: `ProfileTrainer`, the organization anchor this slice's columns
> attach to, and `FileStorage`/`PhotoController`'s authorization-checked file-upload
> pattern), and S6's `base.html.twig` single-include precedent for a "must render on
> every authenticated page" concern (the impersonation banner). Verified directly against
> current source: no branding code exists anywhere (`branding|logo|primary_color`
> matches nothing in `src/` or `templates/` except one stale docblock reference — see
> "Ground truth confirmed" below). This document answers *what* and *why*. The design
> lives in `specs/sdd-trainer-branding-architecture.md` (not yet written).

## Ground truth confirmed against source

- **No branding code exists anywhere.** A repo-wide scan for
  `branding|logo|primary_color|primaryColor` hits nothing in `src/` or `templates/`
  except a stale comment in `src/Entity/ProfileTrainer.php`'s docblock naming "S3's coach
  association, S8's branding" — inconsistent slice numbering left from an earlier plan;
  this slice is filed as **S7**, the actual next unbuilt slice in the epic's order.
- **`ProfileTrainer` (S2) is the organization anchor.** It already carries
  `businessName`, `website`, `address`, `description`. A logo reference and a primary
  color are additive columns on this same entity, not a new `Profile` subtype — there is
  nothing about branding that is a distinct kind of profile.
- **An existing, reusable file-upload pattern.** `App\Service\FileStorage` (used today by
  S2's `ProfileService::uploadPhoto()` and read back through `PhotoController`) content-
  sniffs the real MIME type (not the filename extension), enforces a byte cap, stores
  outside `public/` under an opaque `<prefix>/<random-hex>.<ext>` key, and is served only
  through an authorization-checked controller — never a static, guessable URL. Two gaps
  against this slice's needs, both left for architecture: `FileStorage`'s
  `ALLOWED_MIME_TYPES` allow-list today is `image/jpeg`, `image/png`, `image/webp`
  only — no SVG, which US-01.14's own "Validation" sub-block requires — and
  `MAX_BYTES` is a single shared 5MB constant, stricter than the epic's 2MB logo cap.
- **`templates/base.html.twig` is the confirmed single injection point** for a "must
  render on every authenticated page" concern — S6's impersonation banner is exactly one
  `{{ include(...) }}` there, added once. Org-wide branding (logo in header, color
  driving accents) is the same shape of requirement.
- **Multi-tenancy complicates "org-wide."** S3/S4 give players, coaches, and parents
  potential associations with more than one trainer. A single global "the branding row"
  cannot answer "which trainer's logo/color renders" for a viewer connected to several
  trainers, or for that viewer's own (trainer-less) dashboard. The epic text never
  addresses this — it assumes one trainer, one portal — so this is the central open
  question carried into architecture below.

## Problem

Every prior Epic-01 slice ships a Trainer a functional but visually generic portal — the
epic itself names brand identity as a real trainer need ("Elite Basketball Academy...
Players see 'Elite Basketball Academy' branding when they log in"). Without this slice, a
Trainer has no way to put their own logo or brand color in front of the players, coaches,
and parents who use their portal, and nothing in the platform lets a multi-trainer
platform look like anything other than one undifferentiated product.

## User scenarios

1. **A Trainer** wants to put their logo in their portal's header.
   Path: navigate to "My Portal Settings" / "Branding" → "Upload Logo" → select a PNG,
   JPG, or SVG file under 2MB → see a preview before saving → save → the logo now renders
   in the header on every page in that trainer's portal.
2. **A Trainer** wants their brand's primary color reflected in the UI.
   Path: same Branding page → open the color picker → pick a hex color → see a real-time
   preview of the UI gradient/accent color update before saving → save → the color is
   applied everywhere that accent color renders.
3. **A Trainer** decides their custom color isn't working and wants the platform default
   back.
   Path: Branding page → "Reset to default" → the custom color is cleared and the
   platform default renders immediately, with no lingering custom value implied active.
4. **A Player, Coach, or Parent** connected to that Trainer visits the portal after the
   Trainer saves new branding.
   Path: no re-login, no delay — the next page they view already shows the new logo and
   color.
5. **A Trainer** uploads a file that fails validation.
   Path: an oversized file, or a file whose real content is not one of the accepted
   image types (regardless of its filename extension), is rejected with a clear error;
   nothing is saved and no partial/broken logo state is left behind.

## Acceptance criteria

**Settings page and access (US-01.14)**

- [ ] **AC-1** A Trainer can navigate to a "My Portal Settings" / "Branding" page from
  their own portal navigation. (FR-001)
- [ ] **AC-2** Only the owning Trainer (or a Super Admin, consistent with S2's existing
  admin-can-edit-any-account precedent) can view or change that trainer's branding;
  every other role gets a server-side (not merely UI-hidden) refusal. (BR-001)

**Logo upload (FR-002)**

- [ ] **AC-3** A Trainer can select a PNG, JPG, or SVG file of at most 2MB and see a
  preview of it before saving. (US-01.14 Validation)
- [ ] **AC-4** A file exceeding 2MB, or whose real, content-sniffed type is not PNG, JPG,
  or SVG (regardless of its filename extension), is rejected with a clear error before
  any save; no logo is changed by a rejected attempt.
- [ ] **AC-5** An accepted logo image larger than the recommended 200x200px is
  auto-resized rather than rejected on dimensions alone.
- [ ] **AC-6** After saving, the logo renders in the header of that trainer's portal, and
  is visible to that trainer's players, coaches, and parents.
- [ ] **AC-7** The stored logo is served through an authorization-aware read (never a
  directly browsable, guessable static file path) — same discipline as S2's photo
  storage — even though a logo is intentionally visible to an entire organization rather
  than to one user.

**Color selection (FR-003)**

- [ ] **AC-8** A Trainer can pick a primary brand color via a hex-format color picker,
  and sees the UI gradient/accent color update in real time before saving. (US-01.14
  Validation)
- [ ] **AC-9** Only a valid hex color value can be saved; an invalid value is rejected
  with a clear error and does not change the persisted color.
- [ ] **AC-10** "Reset to default" clears any custom color override; after reset, the
  platform's default color renders, and no data implies a custom color is still active.

**Applying changes (FR-004)**

- [ ] **AC-11** Saving branding (logo, color, or both) applies the change immediately —
  every subsequent page view by that trainer's players, coaches, and parents reflects the
  new branding with no required re-login, cache-clear, or publish delay.
- [ ] **AC-12** One trainer's branding never renders on a page scoped to a different
  trainer, even for a viewer (player, coach, or parent) associated with both. (BR-002)

## Edge cases

| Case | Expected |
|---|---|
| A Trainer saves branding with no logo ever uploaded | The header shows the platform default placeholder (e.g. no logo, or a generic mark) — never a broken image reference. |
| A Trainer removes/replaces an existing logo with a new upload | The new logo replaces the old one; the previous file is not left orphaned on disk (mirrors S2's existing photo-replace-on-upload cleanup). |
| A file is renamed with a `.png` extension but its real content is a different or disallowed type | Rejected — content-sniffing (not extension) decides acceptance, per `FileStorage`'s existing discipline. |
| A viewer (player/coach/parent) is associated with two trainers who have both customized branding | Each trainer's pages show that trainer's own branding; never a blended or last-saved-wins result across trainers (see Open questions for exactly how "which trainer's context" is resolved on pages not scoped to one org). |
| A Trainer picks a hex color with invalid format (e.g. missing `#`, wrong length, non-hex characters) | Rejected with a clear error; the previously saved color (or default, if none) remains in effect. |
| Two rapid saves of different colors in quick succession | The last successful save wins; no crash or corrupted intermediate state. |
| A Trainer's account is deactivated | Not addressed by the epic text; flagged, not designed here (see Open questions) — most likely branding continues to render for existing associations until/unless a future slice says otherwise, consistent with this being a display concern rather than an access-control one. |

## Out of scope

- **Multiple logos (light/dark mode).** Explicitly Phase 2 per the epic's own "Scope for
  MVP" block.
- **Font customization.** Explicitly Phase 2.
- **Full layout customization.** Explicitly Phase 2.
- **Any change to `ShareLink` generation (S3), Best Times views (S4/S5), or the
  coach-assignment-to-events flow (deferred to Epic-02 by S5).** Confirmed already
  accounted for or deferred elsewhere; not re-scoped or touched by this slice.
- **Any new `Profile` subtype.** Branding data lives on the existing `ProfileTrainer`
  entity, not a new one.

## Open questions

None of the items below change the acceptance criteria above if answered differently —
each AC describes observable behavior, not implementation — but each is flagged because
it is load-bearing for the architecture phase.

- **Where branding is rendered/read from (central decision).** Does every trainer-org
  page need a shared Twig global (or a request-scoped service) providing the "active
  trainer" whose logo/color to render — mirroring how S6's banner reads the live
  security token rather than a stored attribute — and if so, what supplies "the active
  trainer" on a page that is not obviously scoped to one org (e.g. a multi-trainer
  player's own dashboard, or a parent's cross-child view)? The epic's own text assumes a
  single-trainer portal and does not answer this; architecture must pick a resolution
  rule (e.g. "the trainer of the ShareLink/association the viewer most recently
  navigated through," or "the trainer selected in an existing context-selector," per S4's
  Flow 2 profile switcher) before AC-6/AC-11/AC-12 can be implemented consistently.
- **"Org-wide" scoping given multi-trainer players/coaches.** AC-12 requires no
  cross-trainer bleed; the mechanism (per-request trainer context vs. per-page explicit
  parameter) is not decided here.
- **SVG safety.** The epic requires accepting SVG logos; SVG can carry embedded
  script/XSS payloads if rendered inline. `FileStorage`'s current allow-list has no SVG
  case at all. Architecture must decide between sanitizing SVG content on upload or
  rendering all logos (including SVG) only via `<img src="...">` (never inline `<svg>`),
  and must decide whether `FileStorage` is extended in place or a parallel path is added.
- **2MB cap vs. `FileStorage`'s shared 5MB constant.** `FileStorage::store()` has no
  per-call max-bytes parameter today; architecture must decide whether to add one or
  build a separate thin validation step for the logo path, without changing behavior for
  existing callers (S2's profile photo upload).
- **Auto-resize mechanism.** "Auto-resize if larger than 200x200px" implies an image
  processing capability (e.g. GD or Imagick) not present anywhere in this codebase today;
  architecture must name the concrete dependency and its failure behavior (e.g. an
  unprocessable image after upload).
- **Platform default color.** No default primary color is defined anywhere in the
  codebase today; architecture must pick a concrete default hex value (or a config
  source for one) for AC-10's "reset to default" to revert to.
- **Trainer account deactivation while branding is live.** Not addressed by the epic
  text; the edge-case row above names it but no rule is written here.

## Traceability

| Requirement | Acceptance criteria |
|---|---|
| FR-001 "My Portal Settings"/"Branding" page | AC-1 |
| FR-002 Logo upload with preview, applied in header, visible org-wide | AC-3, AC-4, AC-5, AC-6, AC-7 |
| FR-003 Color picker, real-time preview, reset to default | AC-8, AC-9, AC-10 |
| FR-004 Immediate org-wide application | AC-11, AC-12 |
| NFR-001 Cheap branding lookup, not scan-derived | AC-11 |
| NFR-002 No directly-browsable static logo URL | AC-7 |
| BR-001 Only the owning Trainer (or Super Admin) may edit | AC-2 |
| BR-002 No cross-trainer branding bleed | AC-12 |

Slice S7 is done when AC-1 … AC-12 hold, on top of S1's AC-1…AC-25, S2's AC-1…AC-24, S3's
AC-1…AC-21, S4's AC-1…AC-24, S5's AC-1…AC-16, and S6's AC-1…AC-14 continuing to hold
(regression, not just addition). This slice is the last remaining unbuilt item in
Epic-01's in-scope MVP list per the requirements analysis — no further Epic-01 slice is
expected after S7 ships.
