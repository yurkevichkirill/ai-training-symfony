# Trainer Portal Branding (Epic-01 slice S7) Requirements

## Overview

US-01.14 "Trainer Customizes Portal Branding" is the one remaining unbuilt item in
Epic-01's in-scope MVP list. A Trainer uploads a logo and picks a primary brand color;
both are applied org-wide (visible to that trainer's players, coaches, and parents) with
a real-time preview and a reset-to-default option.

## Source

- `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md`, US-01.14 (lines 551–590).
- Epic §10 Epic-Level Acceptance Criteria confirmed: "Trainer can generate ShareLinks"
  (shipped S3), "Trainer can view player Best Times" (shipped S4/S5 roster) — both
  already accounted for, not re-scoped here. "Trainer can assign coaches to events" is
  explicitly named and deferred to Epic-02 by S5's own spec (no `Event` entity exists) —
  also not re-scoped here. This slice, S7, covers **only** US-01.14.

## Ground truth confirmed against source

- No branding code exists: repo-wide grep for `branding|logo|primary_color|primaryColor`
  hits nothing in `src/` or `templates/` except one stale docblock reference —
  `src/Entity/ProfileTrainer.php`'s comment names "S3's coach association, S8's
  branding," which is inconsistent slice numbering left over from an earlier plan; this
  slice is filed as **S7** per the epic's actual remaining-work order.
- `ProfileTrainer` (S2) is the organization anchor this slice attaches to: it already
  carries `businessName`, `website`, `address`, `description` — a `logoKey` and
  `primaryColorHex` are additive columns on the same entity, not a new Profile subtype.
- An existing file-upload pattern is reusable: `App\Service\FileStorage` (used by S2's
  `ProfileService::uploadPhoto()` and `PhotoController`) validates content-sniffed MIME
  type against an allow-list, enforces a byte cap, stores outside `public/` under
  `var/uploads/<prefix>/<random-hex>.<ext>`, and is read back through an
  authorization-checked controller rather than a static URL. Two gaps vs. this slice's
  needs, flagged for architecture: (1) `FileStorage`'s `ALLOWED_MIME_TYPES` allow-list is
  `image/jpeg`, `image/png`, `image/webp` only — no SVG, which the epic's own
  "Validation" sub-block explicitly requires; SVG can carry embedded script/XSS payloads,
  so accepting it safely needs either sanitization or `<img>`-only rendering (never
  inline `<svg>`), a decision for architecture, not assumed here. (2) `MAX_BYTES` is a
  single class constant (5 MB) shared by every caller; the epic's 2MB cap for logos is
  stricter than the photo cap, so either `store()` needs a per-call max-bytes parameter
  or the logo path needs its own thin wrapper — an implementation decision, not a
  behavior change to `FileStorage`'s existing callers.
- `templates/base.html.twig` is confirmed as the one page-wide injection point already
  used for a comparable "must render on every authenticated page" concern: S6's
  impersonation banner is a single `{{ include(...) }}` there. Org-wide branding (logo in
  header, primary color driving gradient/accent) is the same shape of requirement and is
  expected to use the same single-include pattern rather than per-template edits.
- Multi-tenancy: players/coaches/parents each may be associated with more than one
  trainer (S3/S4's multi-trainer support). "Org-wide" therefore cannot mean "the one
  branding row" globally — it must resolve to *whichever trainer's context* the viewer is
  currently in. The epic's own Flow 2 has a parent-driven profile/context selector, and a
  player/coach can belong to multiple trainers; which trainer's branding renders when a
  user is not actively "inside" one org's page (e.g. their own dashboard) is not answered
  by the epic text and is the central open question for architecture.

## Functional Requirements

1. **FR-001**: A Trainer can navigate to a "My Portal Settings" / "Branding" page.
   - Acceptance: page reachable from the trainer's own navigation; gated to
     `ROLE_TRAINER` (own org only).
   - Priority: High
2. **FR-002**: A Trainer can upload a logo image (PNG, JPG, or SVG, max 2MB), see a
   preview before saving, and have it appear in the header of their portal.
   - Acceptance: reject files over 2MB or of an unsupported type with a clear error;
     accepted files are content-sniffed, not trusted by extension (mirroring
     `FileStorage`'s existing discipline); oversized images are auto-resized (recommended
     200x200px) rather than rejected on dimensions alone.
   - Priority: High
3. **FR-003**: A Trainer can pick a primary brand color via a color picker (hex format),
   see it applied as a real-time preview before saving, and reset it to the platform
   default.
   - Acceptance: only a valid hex value persists; "Reset to default" clears any custom
     override, reverting to the platform's default color with no leftover row/value
     implying a custom color is still active.
   - Priority: High
4. **FR-004**: Saving branding changes applies them immediately, org-wide, to every user
   currently associated with that trainer (players, coaches, parents viewing a child's
   connection to that trainer).
   - Acceptance: no publish delay, no per-user cache requiring logout/login to see the
     change.
   - Priority: High

## Non-Functional Requirements

1. **NFR-001**: Branding read must not add a materially expensive query to every page
   render — mirrors S6's banner concern (`is_granted`/token-derived, not a scan) but here
   there is real persisted data (logo key, color) to fetch, so a cheap, keyed lookup (by
   trainer id) is expected, not named as a metric by the epic.
2. **NFR-002**: Logo storage must not introduce a directly-browsable, guessable static
   URL — consistent with existing `PhotoController`/`FileStorage` discipline (AC-12 in
   S2), even though a logo is intentionally *more* public than a profile photo (visible
   to an entire org, not just the owner).

## Business Rules

1. **BR-001**: Only the owning Trainer (or a Super Admin, per existing admin-can-edit-any
   precedent from S2) may change that trainer's branding.
2. **BR-002**: Branding is scoped per-trainer; one trainer's branding never bleeds into
   another trainer's rendered pages, even for a viewer associated with both.

## Task Breakdown

### Entities
| Entity | Properties | Relations |
|--------|------------|-----------|
| `ProfileTrainer` (additive columns) | `logoKey` (nullable string), `primaryColorHex` (nullable string) | existing — no new subtype |

### Services
| Service | Purpose | Methods |
|---------|---------|---------|
| Branding update path (existing `ProfileService` or a new thin service — architecture decision) | Validate + persist logo upload and color, with reset-to-default | `uploadLogo`, `setPrimaryColor`, `resetToDefault` |
| `FileStorage` (reused, possibly extended) | Store/validate the logo file | `store` (needs SVG + 2MB-cap support — decision for architecture) |

### Controllers
| Controller | Endpoints | Purpose |
|------------|-----------|---------|
| Trainer branding controller (new) | `/trainer/branding` (GET/POST) | Show settings page with preview; handle save/reset |
| Logo read endpoint (new, mirroring `PhotoController`) | `/branding/logo/{trainerId}` (GET) | Serve the logo through an authorization-checked read, not a static path |

## Gap Analysis

- [ ] **Rendering scope for a multi-trainer viewer**: which trainer's branding renders on
  pages that are not "inside" one trainer's context (own dashboard, cross-trainer views)?
  Not answered by the epic text — flagged for architecture/brainstorming.
- [ ] **SVG safety**: the epic requires accepting SVG logos; `FileStorage`'s current
  allow-list does not include SVG at all, and SVG is an XSS vector if rendered inline.
  Needs an explicit sanitization-or-`<img>`-only decision.
- [ ] **2MB vs. existing 5MB shared cap**: `FileStorage::MAX_BYTES` is a single constant
  today; the per-caller cap needs a mechanism.
- [ ] **Auto-resize mechanism**: "auto-resize if larger than 200x200px" implies an image
  processing step (GD/Imagick) not present anywhere in this codebase today — needs a
  dependency decision.
- [ ] **Default color/branding**: no "platform default" primary color is defined anywhere
  in the codebase today; needs a concrete default value decided in architecture.

## Next Steps (Suggested)

Not auto-executed — presented for the user to choose:
- `brainstorming TASK-007: Trainer Portal Branding requirements analyzed` — resolve the
  multi-trainer rendering-scope question and SVG-safety approach collaboratively before
  locking the spec's open questions.
- `architect TASK-007: Trainer Portal Branding requirements analyzed` — proceed straight
  to architecture if the open questions above are acceptable to resolve directly in the
  design phase (consistent with how S4/S5/S6 handled their own flagged questions).
