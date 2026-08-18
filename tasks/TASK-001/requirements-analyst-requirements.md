# TASK-001 — Epic-01: User Management & Authentication — Requirements Analysis

> Requirements analysis only. **No solution design** — this document covers the parsed
> specification, delivery slices, dependencies between them, and open questions.

## Overview

The platform's foundation epic: a multi-role user system (Super Admin, Trainer, Coach,
Player/Parent), authentication, RBAC, multi-tenancy (data isolation between trainer
organizations), ShareLink onboarding, family accounts (parent/child), availability
(Best Times / My Times), impersonation, soft delete and GDPR anonymization, and trainer
portal branding.

- **Priority**: P0 — blocks Epic-02 … Epic-08.
- **Complexity**: High.
- **User Stories**: **14** in fact (US-01.01 … US-01.14), not 12 as the spec footer states.

## Source

- `Task/Epics/Epic-01_User_Management_Authentication_SPEC.md` (1100 lines)
- `Task/Epics/Epic_Areas_Plan.md` (epic dependency map)
- Implementation order taken from **§13 "Implementation Notes" → "Suggested Implementation Order"**.

---

## Functional Requirements

Grouped by slice (S1…S7 come from §13; S8 is not placed anywhere in the §13 order — see Gap G-13).

| ID | Requirement | Source | Slice | Priority |
|:---|:---|:---|:---:|:---:|
| FR-001 | Email/password authentication for all 4 roles | §3, §10 AC | S1 | High |
| FR-002 | Password hashing, email uniqueness | §9, §10 AC | S1 | High |
| FR-003 | Session management (login, logout, expiry) | §3, §9 | S1 | High |
| FR-004 | Password reset (link valid 1 hour) | §3, §9, §13 | S1 | High |
| FR-005 | Email verification (link valid 24 hours) | §3, §9, §13 | S1 | High |
| FR-006 | RBAC: exactly one role per user, role-appropriate dashboard, no access outside role, enforcement on both UI and backend | §9 | S1 | High |
| FR-007 | Rate limiting on authentication endpoints; CSRF on all state-changing operations | §13 | S1 | High |
| FR-008 | Users tool — global user directory with pagination, filters, and tool-specific (not global) search | §3, §10 AC | S2 | High |
| FR-009 | US-01.01: Super Admin creates a trainer account (business name, name, email, phone), invitation email, forced password change on first login, creation audit entry | US-01.01 | S2 | High |
| FR-010 | Trainer self-registration is forbidden — Super Admin only | §9 | S2 | High |
| FR-011 | US-01.11: every role edits its own profile; role-specific fields; read-only fields (email, role, skill level, created date) | US-01.11 | S2 | High |
| FR-012 | Profile photo upload to file storage + thumbnail generation | US-01.11 | S2 | Medium |
| FR-013 | Super Admin edits any account | §10 AC | S2 | High |
| FR-014 | US-01.12: deactivation (soft delete) — login blocked, all history preserved, reactivation possible | US-01.12 | S2 | High |
| FR-015 | US-01.13: deletion (GDPR) — PII anonymized, history shows "Deleted User", irreversible, deletion logged | US-01.13 | S2 | High |
| FR-016 | Multi-tenancy: a trainer sees only their own organization's data; 0% leakage between organizations | §9, §10 AC | S2 (cross-cutting) | High |
| FR-017 | ShareLink: generation, unique URL-safe code, type (static for players — unlimited, no expiry; unique for coaches — single use, 7 days), owning trainer, usage counter, active flag | §8, §9 | S3 | High |
| FR-018 | US-01.02: player/parent registration via ShareLink, auto-association with the trainer, player profile created in the trainer's CRM, confirmation email; if already logged in — instant association | US-01.02 | S3 | High |
| FR-019 | US-01.02 (multi-trainer): existing account opening another trainer's ShareLink → new association, no duplicate account; for a parent — family member selection checklist | US-01.02 | S3 | High |
| FR-020 | Separated views: trainer context switcher in navigation, fully isolated data per context, selected context persists across the session, no combined view | US-01.02, US-01.04 | S3/S4 | High |
| FR-021 | US-01.08: trainer invites a coach (unique ShareLink, 7 days, single use), invitation statuses (Pending/Accepted/Expired), resend | US-01.08 | S3 | High |
| FR-022 | A coach is active under exactly one trainer — validated at link registration | §9, US-01.08 | S3 | High |
| FR-023 | ShareLink usage tracking (which link, when, by whom) for Epic-06 analytics | §9 | S3 | Medium |
| FR-024 | US-01.03: parent creates a child profile (name, age, gender; optional school, photo), Child vs Self marker, linked to the parent account | US-01.03 | S4 | High |
| FR-025 | US-01.03: trainer selection when creating a child (single-trainer prompt / checklist when multiple / none) | US-01.03 | S4 | High |
| FR-026 | The parent account is itself a player account (a parent can train themselves) | §3, US-01.03 | S4 | High |
| FR-027 | US-01.04: manage child↔trainer associations (add via ShareLink or from "My Trainers"; remove, cancelling upcoming RSVPs and soft-deleting the child's data with that trainer) | US-01.04 | S4 | High |
| FR-028 | US-01.04: context selector for parents ("Me" + children × trainers) and for children (own trainers only) | US-01.04, US-01.06 | S4 | High |
| FR-029 | US-01.06: child login with constraints (allow-list of permitted actions and deny-list of blocked ones) | US-01.06 | S4 | High |
| FR-030 | US-01.06: ShareLink blocked for children + email to the parent with a "Review Registration" CTA | US-01.06 | S4 | High |
| FR-031 | US-01.05: parent approval of child purchases — USD always requires approval; tokens follow a per-child setting (default OFF = approval required) | US-01.05 | S4 (partly blocked by Epic-05) | High |
| FR-032 | US-01.05: parent notifications (email + in-app), Approve / Deny / Request more info actions, auto-deny after 48 hours | US-01.05 | S4 | High |
| FR-033 | US-01.09: player/child Best Times — grid of weekdays and time ranges, set separately per child | US-01.09 | S4 | Medium |
| FR-034 | US-01.09: trainer views player availability, filters by "available on [day/time]", summary on the player card | US-01.09 | S4 (consumed by Epic-02/03) | Medium |
| FR-035 | US-01.10: coach My Times — recurring weekly schedule, multiple slots per day | US-01.10 | S5 | Medium |
| FR-036 | US-01.10: conflict warning when assigning a coach to an event + override with a mandatory reason, logged | US-01.10 | S5 (needs Epic-02) | Medium |
| FR-037 | Coach public profile (bio, credentials, certifications, public visibility checkbox) | §3, US-01.11 | S5 | Medium |
| FR-038 | US-01.07: Super Admin impersonation — confirmation, exact match of permissions/data, sticky colored banner, exit | US-01.07 | S6 | High |
| FR-039 | US-01.07: impersonating other Super Admins is forbidden | US-01.07, §9 | S6 | High |
| FR-040 | US-01.07: impersonation audit (who, whom, start, end, duration), "Impersonation History" report, session expiry after 1 hour | US-01.07 | S6 | High |
| FR-041 | Audit logs for all sensitive operations (impersonation, user deletion, availability override) | §13, §10 AC | S6 (cross-cutting) | High |
| FR-042 | US-01.14: trainer portal branding — logo upload and primary color selection, preview, reset to default, applied to everyone in the organization | US-01.14 | S8 (outside §13) | Medium |
| FR-043 | Camp-to-User Conversion: after camp form submission — prompt to create an account with pre-filled data, auto-assign to the trainer, or send a ShareLink by email | §3 | outside Epic-01 (Epic-08) | Medium |

## Non-Functional Requirements

| ID | Requirement | Metric | Source |
|:---|:---|:---|:---|
| NFR-001 | Dashboard load | < 2 s | §11, §10 AC |
| NFR-002 | User list at 10,000 records | < 3 s with pagination | §11 |
| NFR-003 | Profile save | < 1 s | §11 |
| NFR-004 | ShareLink registration | < 2 s; handles 100 concurrent registrations | §11, §12 |
| NFR-005 | Platform concurrency | 1,000 concurrent users | §10 AC |
| NFR-006 | Best Times queries | fast with thousands of players | §12 |
| NFR-007 | Accessibility | WCAG 2.1 AA: keyboard navigation on all forms, screen reader support, contrast, visible focus | §13 |
| NFR-008 | Mobile | responsive layout, touch-friendly controls, mobile-optimized forms and uploads | §13 |
| NFR-009 | Session security | protection against token theft/XSS, CSRF on state-changing operations, login rate limiting | §13 |
| NFR-010 | Self-service parent/child profile creation success rate | ≥ 95% | §2 |
| NFR-011 | Data isolation between organizations | 0% leakage | §2, §10 AC |

## Business Rules

| ID | Rule | Source |
|:---|:---|:---|
| BR-001 | Email is unique platform-wide and serves as the login; changing it is a separate flow (read-only in MVP) | §9, US-01.11 |
| BR-002 | Passwords hashed with an industry-standard approach; login is rate-limited | §9 |
| BR-003 | Token lifetimes: password reset 1 h, email verification 24 h, impersonation session 1 h | §9, §13 |
| BR-004 | A user has exactly one role | §9 |
| BR-005 | Only Super Admin creates trainer accounts (payment verification and quality control) | §9 |
| BR-006 | A player may be associated with multiple trainers; no duplicate accounts | §9 |
| BR-007 | Multi-trainer players get **separated** contexts with no unified view | §9 |
| BR-008 | A coach is active under strictly one trainer at a time | §9 |
| BR-009 | Static ShareLink: unlimited uses, no expiry. Unique coach ShareLink: one use, 7 days | §9 |
| BR-010 | All players under 18 require a parent-managed account (**conflicts with Q-01.05 in US-01.06**, see Gap G-02) | §9 |
| BR-011 | The parent owns the family's contact information; each child has their own calendar, RSVP status, and Best Times **per trainer** | §9, US-01.03 |
| BR-012 | Child↔trainer associations are explicit (except the single-trainer prompt) and changeable by the parent at any time | US-01.03 |
| BR-013 | Child USD purchases always require parent approval; tokens follow a per-child setting (default: approval required) | §9, US-01.05 |
| BR-014 | An approval request expires after 48 hours → auto-denied with notification | US-01.05 |
| BR-015 | Super Admin can impersonate anyone except other Super Admins | §9 |
| BR-016 | Deactivation preserves all history and is reversible; deletion anonymizes PII and is irreversible | §9 |
| BR-017 | After deletion, analytics aggregates (player counts, revenue, attendance) stay unchanged | §9, US-01.13 |
| BR-018 | Availability is a planning suggestion, not a restriction; a coach conflict may be overridden by the trainer with a mandatory reason, logged | §9 |
| BR-019 | Validation: email and phone format, required fields, child age 1–18, unique ShareLink codes, duplicate warning for similar child name/age | §9, US-01.03 |
| BR-020 | Permissions enforced on both frontend and backend | §9 |

---

## Implementation Slices (order from §13)

The §13 order is reproduced verbatim; user stories, FRs, and data are mapped onto each step.

### S1 — Core authentication and authorization
- **User stories**: cross-cutting (the basis for everything); partly US-01.01 (forced password change), US-01.06 (the legal basis for child constraints).
- **FRs**: FR-001…FR-007.
- **Data**: users (email, hashed password, role, status, email verified, reset tokens, last login, timestamps).
- **Done when**: all 4 roles can log in, password reset and email verification work end-to-end, role dashboards are separated, rate limiting and CSRF are in place.

### S2 — User management basics (CRUD, profiles)
- **User stories**: US-01.01, US-01.11, US-01.12, US-01.13; Users tool.
- **FRs**: FR-008…FR-016.
- **Data**: profiles (common + role-specific), trainer profiles, player profiles (without child specifics), deletion compliance log.
- **Done when**: Super Admin creates trainers and edits any user; every user edits their own profile and photo; deactivation/deletion work with history preserved.

### S3 — ShareLink invitation system
- **User stories**: US-01.02, US-01.08; the blocking branch of US-01.06 (child clicks a link).
- **FRs**: FR-017…FR-023, entry into FR-020.
- **Data**: share_links, trainer↔player associations (which link, when, status), coach↔trainer association.
- **Done when**: player and coach registration via link, multi-trainer association without duplicates, coach exclusivity, counters and expiry enforced.

### S4 — Player/Parent features (profiles, children, Best Times)
- **User stories**: US-01.03, US-01.04, US-01.05, US-01.06, US-01.09.
- **FRs**: FR-024…FR-034, completion of FR-020.
- **Data**: parent↔child link, per-child token setting, availability (player), child purchase approvals.
- **Done when**: family profiles, child↔trainer association management, context switching, child login constraints, Best Times.
- **Constraint**: FR-031/FR-032 (purchase approval) can only be completed together with Epic-05 — see Gap G-05.

### S5 — Coach features (My Times, availability)
- **User stories**: US-01.10, the coach part of US-01.11.
- **FRs**: FR-035…FR-037.
- **Data**: availability (coach), coach availability overrides.
- **Constraint**: FR-036 (conflict warning and override on event assignment) requires events from Epic-02 — see Gap G-06.

### S6 — Super Admin tools (impersonation, audit logs)
- **User stories**: US-01.07.
- **FRs**: FR-038…FR-041.
- **Data**: impersonation audit log; deletion and override audit rolled up into reports.
- **Done when**: impersonation with banner, Super Admin target blocked, 1-hour expiry, history report available.

### S7 — Testing and refinement
- **Coverage**: §12 scenarios (Key Scenarios, Security Testing, Performance Testing) + NFR-001…NFR-011.
- Layers: integration HTTP tests (validation, authorization, persistence), unit tests for domain logic, E2E for the key flows in §10 (Flow 1–7).

### S8 — Portal branding (US-01.14) — **not placed in §13**
- **FRs**: FR-042. Technically depends only on S2 (trainer account) and file storage; it is absent from the suggested order, so its position needs a decision (Gap G-13). A placement with no critical-path impact: after S2, in parallel with S3–S5.

---

## Dependencies Between Slices

```
                 S1  Core auth & authz
                          │
                          ▼
                 S2  User mgmt basics ──────────────► S8  Portal branding (outside §13)
                          │
                ┌─────────┴─────────┐
                ▼                   ▼
        S3  ShareLinks        S6  Super Admin tools
                │                (impersonation, audit)
        ┌───────┴────────┐
        ▼                ▼
  S4 Player/Parent   S5 Coach features
        │                │
        └────────┬───────┘
                 ▼
        S7  Testing & refinement (cross-cutting, runs from S1 onward)
```

| From | To | Type | Why |
|:---|:---|:---|:---|
| S1 | S2…S6 | hard | without authentication and roles there is nothing to protect and no one to own data |
| S2 | S3 | hard | a ShareLink belongs to a trainer, and only Super Admin creates trainers (BR-005) |
| S2 | S6 | hard | impersonation and the Users tool sit on top of the user list and statuses |
| S3 | S4 | hard | player↔trainer associations and multi-trainer contexts originate from links; the "child clicked a link" branch closes in S4 |
| S3 | S5 | hard | a coach only enters the system through a unique ShareLink (US-01.08) |
| S2 | S4 | hard | the child profile is an extension of the player profile |
| S1 | S4 (child login) | hard | child constraints are expressed through the RBAC built in S1 |
| S6 | S2 (deletion) | soft | the deletion audit entry is written in S2; reporting over it is assembled in S6 |
| S4 (Best Times) | S5 | none | player and coach availability models are independent, though they benefit from a shared data shape — an architecture decision |
| S7 | all | cross-cutting | tests are written alongside each slice; the final run follows S6 |

### External Dependencies and Epic Boundaries

| Dependency | Required by | Status |
|:---|:---|:---|
| Email service (transactional mail) | S1 (reset, verification), S2 (trainer invitation), S3 (coach invitation, registration confirmation), S4 (parent notifications) | external; the set of emails is undefined (Q-01.04) |
| File storage | S2 (profile photos), S8 (logo) | external; provider not chosen |
| Scheduler / background jobs | S4 (auto-deny after 48 h), S1 (expired token cleanup) | not mentioned in the spec — Gap G-09 |
| Epic-05 Payments & Tokens | S4: FR-031, FR-032 | blocks full delivery of purchase approval |
| Epic-02 Event Management | S5: FR-036 (coach assignment, conflict, override); S4: FR-034 (availability filter during event creation); US-01.04 (RSVP cancellation when a child is unlinked) | blocks part of the acceptance criteria |
| Epic-03 CRM | S3: "player profile created in the trainer's CRM"; S4: player card with Best Times | boundary needs clarification |
| Epic-08 Forms & Registration | FR-043 Camp-to-User Conversion | §3 assigns it to Epic-01, Epic_Areas_Plan assigns it to Epic-08 (Gap G-04) |
| Epic-06 Marketing | FR-023 ShareLink tracking — consumer | only the producer side lives in Epic-01 |

**This epic blocks**: Epic-02, Epic-03, Epic-04, Epic-05, Epic-06, Epic-07, Epic-08. It has no inbound dependencies.

---

## Open Questions

### From §12 of the spec (as-is)

| ID | Question | Prio | Status | Owner | Blocks |
|:---|:---|:---:|:---|:---|:---|
| Q-01.01 | Skill level definitions (Beginner/Intermediate/Advanced/Elite or custom?) | P2 | Open | Client | S2 (player profile field) |
| Q-01.02 | How are age groups defined (birth year, ranges, grade levels?) | P2 | Open | Client | S4 (child profile) |
| Q-01.04 | Which automated emails are required (welcome, reset, invite, others?) | P1 | Open | Client | S1, S2, S3, S4 |
| Q-01.05 | Email verification: required before login or optional? | P1 | Open | Client | **S1 — on the critical path** |
| Q-01.06 | Should a coach be notified when their availability is overridden? | P2 | Open | Client | S5 |
| Q-01.07 | Session lifetime (1 / 7 / 30 days)? | P2 | Open | Client | S1 |
| Q-01.03 | **Missing from the table** — identifier skipped | — | — | — | — |

### Open questions embedded in user story text (absent from §12)

| ID | Question | Source | Blocks |
|:---|:---|:---|:---|
| Q-A | Must all players under 18 have parent accounts, or may 16–18 year olds hold independent ones? COPPA considerations | US-01.06 "Open Question (Q-01.05)" | S4 |

> Note: the spec uses the identifier **Q-01.05 twice** for different questions (COPPA in US-01.06 and email verification in §12). The question register needs rebuilding.

### Gap Analysis — contradictions and holes found while parsing

| ID | Issue | Where | Impact |
|:---|:---|:---|:---|
| G-01 | 12 user stories claimed, 14 present; the §4 note cites "portal branding — US-01.12", but US-01.12 is user deactivation — branding is US-01.14 | §4, footer | requirements traceability confusion |
| G-02 | §9 states "ALL players under 18 require parent-managed accounts" as settled, while US-01.06 keeps it open | §9 vs US-01.06 | S4, legal risk (COPPA/GDPR) |
| G-03 | Duplicated section numbers: two "§10", two "§11", two "§12" | document structure | section references are ambiguous |
| G-04 | Camp-to-User Conversion is listed in Epic-01's MVP scope, but Epic_Areas_Plan assigns it to Epic-08 | §3 vs Epic_Areas_Plan | epic boundary undefined |
| G-05 | Child purchase approval (US-01.05) operates on USD payments and tokens that do not exist in Epic-01 — they live in Epic-05 | US-01.05 | part of S4's acceptance criteria cannot close inside this epic |
| G-06 | Conflict warning and override (US-01.10) require events and assignments from Epic-02 | US-01.10 | part of S5's acceptance criteria cannot close inside this epic |
| G-07 | Child login mechanics are undescribed: email is unique and serves as the login (BR-001), yet a child "shares the parent's contact info" | US-01.03, US-01.06 | S4 — blocks design |
| G-08 | Bootstrapping the first Super Admin is never described (who creates it and how) | §7 as a whole | S1/S2 |
| G-09 | 48-hour approval expiry and 1-hour impersonation expiry imply background jobs / a scheduler — that infrastructure is missing from §5 External Dependencies | US-01.05, US-01.07 | S4, S6 |
| G-10 | US-01.01: "temporary password **OR** invite link" — the choice is not made | US-01.01 | S2 |
| G-11 | Logo file type contradiction: "PNG, JPG, max 2MB" in the description vs "PNG, JPG, SVG" in the validation block | US-01.14 | S8 |
| G-12 | Coach status after registration is "Pending **or** Active" with no criterion; how a coach leaves a trainer and whether they can be reassigned is undescribed (exclusivity "at a time" implies history) | US-01.08, §9 | S3/S5 |
| G-13 | Portal branding (US-01.14) is absent from the §13 implementation order | §13 vs US-01.14 | S8 planning |
| G-14 | GDPR conflict: US-01.13 requires PII erasure, but §8 "For User Deletion Compliance" requires retaining the original email and a "backup of original data" — legal basis and retention period unspecified | US-01.13 vs §8 | S2, compliance |
| G-15 | The anonymized email `deleted_[user_id]@example.com` must coexist with the email uniqueness constraint — behavior on repeated deletions is undescribed | US-01.13 | S2 |
| G-16 | The availability model is undecided: "hourly blocks **or** custom ranges" — both offered, neither chosen; time zones are never mentioned anywhere in the epic | US-01.09, US-01.10 | S4, S5 |
| G-17 | "Tool-specific search (not global)" is ambiguous; the required search behavior is not described | §3, §10 AC | S2 |
| G-18 | "Trainer manages own organization users" appears in scope but has no user story: may a trainer deactivate/delete their own players and coaches, or is that Super Admin only | §3 vs US-01.12/13 | S2, authorization |
| G-19 | US-01.04 covers unlinking a **child** from a trainer; unlinking the parent or an adult player from a trainer is not described | US-01.04 | S4 |
| G-20 | "Current trainer context persists across session" — whether the context is persisted server-side (surviving a device change) is unstated | US-01.02 | S4 |
| G-21 | Behavior when the 1-hour impersonation session expires is undescribed (return to admin, or full logout) | US-01.07 | S6 |
| G-22 | Password policy (complexity, length, history) and rate-limiting thresholds are unspecified | §9, §13 | S1 |
| G-23 | Role model: "exactly one role per user" (BR-004) versus a parent who is simultaneously a player, and a child with their own login — the "role vs profile" distinction is undescribed | §9 vs US-01.03 | S1, S4 — foundational to the schema |
| G-24 | No requirements for a load environment or data set to verify the NFRs (10,000 users, 1,000 concurrent) | §11, §12 | S7 |
| G-25 | "Request more info" as a parent action in US-01.05 is absent from §9 and has no status in the data model (pending/approved/denied/expired) | US-01.05 vs §8 | S4 |

---

## Validation Checklist

- [x] All functional requirements from §3/§7 mapped to FR-001…FR-043 and distributed across slices
- [x] Happy path covered (Flows 1–7 in §10)
- [x] Error cases identified (duplicate email, expired link, coach already under another trainer, deactivated user login, ShareLink blocked for a child)
- [x] Edge cases recorded (multi-trainer, parent-as-player, 48-hour and 1-hour expiry, repeated deletion)
- [x] Security requirements isolated (§13, §12 Security Testing → FR-006, FR-007, FR-039, FR-041, NFR-009)
- [x] Performance requirements recorded (NFR-001…NFR-006)
- [x] Testing strategy identified as slice S7
- [ ] **Open questions unresolved** — 7 from §12/user stories plus 25 gaps (above); Q-01.04, Q-01.05 (email verification), and G-23 block the start of S1

## Next Steps (Suggested)

Presented as options; nothing is executed automatically.
