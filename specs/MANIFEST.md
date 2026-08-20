# Project: Symfony Layered Architecture Accelerator

An AI-assisted development accelerator for Symfony 7.4 LTS and Symfony 8.1 projects. It provides native Claude Code, Cursor, and Codex workflows centered on pragmatic Controller -> Service -> Repository architecture and Symfony conventions.

## Specs Index

| File | Purpose | Depends On | Last Updated |
|------|---------|------------|--------------|
| architect-architecture.md | System design, components, data flow | - | - |
| api-designer-spec.md | Endpoints, schemas, authentication | architect-architecture | - |
| frontend-design-spec.md | Pages, components, state management | architect-architecture, api-designer-spec | - |
| docs-generator-implementation.md | Build process, deployment, tooling | - | - |
| auth-foundation-spec.md | Epic-01 slice S1: core authentication and authorization (FR-001…FR-007) — problem, scenarios, AC-1…AC-25, edge cases, resolved decisions | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-18 |
| auth-foundation-architecture.md | Epic-01 slice S1 design: User/token/audit schema and the frozen User↔Profile contract, firewall and uniform-failure authentication, default-deny authorization, reset and verification token services, rate limiting, queued mail, auth event logging, accessible Twig surface | auth-foundation-spec.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-18 |
| sdd-user-management-spec.md | Epic-01 slice S2: Users directory, Super-Admin-creates-trainer (invitation flow), profile editing + photo upload, deactivation, GDPR deletion — problem, scenarios, AC-1…AC-24, edge cases, resolved decisions (G-10, G-14, G-15, G-17, G-18) | auth-foundation-spec.md, tasks/TASK-001/requirements-analyst-requirements.md | 2026-08-20 |
| sdd-user-management-architecture.md | Epic-01 slice S2 design: builds the frozen Profile hierarchy (Profile/ProfileTrainer), AccountInvitation (reuses S1 token discipline), AccountEvent/AccountDeletionLog, anonymize-in-place GDPR deletion, keyset-paginated Users directory | sdd-user-management-spec.md, auth-foundation-architecture.md | 2026-08-20 |
| sdd-sharelink-invitations-spec.md | Epic-01 slice S3: the ShareLink invitation system — US-01.02 (player registration via ShareLink, multi-trainer association) and US-01.08 (trainer invites coach) only — problem, scenarios, AC-1…AC-21, edge cases, resolved decisions (Parent/Child deferred, coach account status, coach exclusivity window, no email-verification exception) | Task/Epics/Epic-01_User_Management_Authentication_SPEC.md, sdd-user-management-spec.md, auth-foundation-spec.md | 2026-08-20 |
| sdd-sharelink-invitations-architecture.md | Epic-01 slice S3 design: two separate link entities (static per-trainer PlayerShareLink with a monotonic usage counter; single-use 7-day CoachInvitation addressed to an email, reusing SelectorVerifierTokenFactory), two separate association entities (TrainerPlayerAssociation with UNIQUE(trainer,player) for idempotency; TrainerCoachAssociation with a partial unique index on the active coach for exclusivity), ProfilePlayer as the second Profile subtype, two-phase registration with compensating cleanup over UserAccountService, voter + service-guard role/email integrity, one new source rate limiter and three email templates | sdd-sharelink-invitations-spec.md, sdd-user-management-architecture.md, auth-foundation-architecture.md | 2026-08-20 |

## Key Decisions

- Target Symfony 7.4 LTS and Symfony 8.1 while detecting each consuming project's installed versions.
- Use `.agents/skills` as the configured canonical source for shared skill parity, mirror Claude and Cursor semantics natively, and keep Codex support files under `.codex`.
- Enforce Controller -> Service -> Repository pragmatically, without requiring pass-through layers or interfaces without a real boundary.
- Deliver Epic-01 in the slices of its §13 implementation order, one spec pair per slice, starting with S1 (`auth-foundation`); TASK-001 is the governed task for S1.
- S1 blocking questions resolved 2026-08-18: one role per user plus attached profiles (G-23); email verification required before first sign-in (Q-01.05); OWASP-aligned password and rate-limit thresholds (G-22); first Super Admin created by an `app:create-super-admin` console command (G-08).
- S1 (`auth-foundation`, TASK-001) shipped and fully tested 2026-08-19 (AC-1…AC-25). S2 (`user-management`, TASK-002) shipped and fully tested 2026-08-20 (AC-1…AC-24): frozen Profile hierarchy, Users directory, trainer invitation flow, profile editing, deactivation, and GDPR deletion. Combined security + code-quality review found 2 High/2 Medium fixed pre-ship (invitation surviving deactivation, GDPR email retention, orphaned photo file, admin-edit-on-deleted-account) plus 3 further High fixed (two-phase trainer-creation orphan, delete-guard concurrency race, deleted-user display name) — all with real-DB regression tests, full suite green (187 tests).
- S3 (`sharelink-invitations`, TASK-003) shipped and fully tested 2026-08-20 (AC-1…AC-21):
  player self-registration via a static per-trainer ShareLink, existing-player
  multi-trainer association, and coach invitations via a single-use 7-day link,
  built on `PlayerShareLink`/`CoachInvitation`/`TrainerPlayerAssociation`/
  `TrainerCoachAssociation`/`ProfilePlayer`. The partial-unique-index approach (verified
  working, not a risk) makes AC-16's coach exclusivity and AC-13's idempotency database
  facts rather than app-level checks. Dual code-review + security-review pass (Task 30)
  found 2 Major correctness bugs (a `usage_count` lost-update race; a blank refusal
  message on the deactivated-trainer coach path) and 4 security Mediums on the slice's
  first anonymous-writable endpoints (unthrottled coach-invite mail, email enumeration
  on registration, account pre-hijack/squatting, a one-click GET-triggered PII
  disclosure with no revoke path) — all fixed, including three product decisions
  (enumeration-resistant registration response, a same-pass account-squatting cleanup
  sweep command, and a player leave/revoke path with its own migration, mirroring the
  coach side's partial-unique-index pattern). Final regression: 278 tests, 1393
  assertions, green; schema clean; every route present and correctly gated.

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
