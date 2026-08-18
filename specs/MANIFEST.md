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

## Key Decisions

- Target Symfony 7.4 LTS and Symfony 8.1 while detecting each consuming project's installed versions.
- Use `.agents/skills` as the configured canonical source for shared skill parity, mirror Claude and Cursor semantics natively, and keep Codex support files under `.codex`.
- Enforce Controller -> Service -> Repository pragmatically, without requiring pass-through layers or interfaces without a real boundary.
- Deliver Epic-01 in the slices of its §13 implementation order, one spec pair per slice, starting with S1 (`auth-foundation`); TASK-001 is the governed task for S1.
- S1 blocking questions resolved 2026-08-18: one role per user plus attached profiles (G-23); email verification required before first sign-in (Q-01.05); OWASP-aligned password and rate-limit thresholds (G-22); first Super Admin created by an `app:create-super-admin` console command (G-08).

## Tech Stack

- PHP 8.2+ for Symfony 7.4 LTS; PHP 8.4+ for Symfony 8.1.
- Symfony components and conventions, Doctrine ORM/Migrations, Symfony Security, Messenger, Forms, Validator, Serializer, Twig, and Symfony UX as installed by the consuming project.

---

*This manifest is updated automatically by architect, api-designer, and frontend-design skills.*
*See `../spec-desc.md` for specification structure guidelines.*
