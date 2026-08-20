# TASK-002 — Epic-01 slice S2: User Management Basics

Design: `specs/sdd-user-management-architecture.md`. Spec: `specs/sdd-user-management-spec.md`.
Each task cites the acceptance criteria (AC-N) it serves. Mark `[x]` only once the
change is made, migrated, and (where a test task follows) proven.

## Schema

- [x] 1. Add `UserStatus::DELETED`; extend `User` with `firstName`, `lastName`, `phone`,
  `photoKey`, `getDisplayName()`, `anonymize()`. (AC-19, AC-22, foundation for AC-10)
- [x] 2. Create `Profile` (abstract, JOINED) and `ProfileTrainer` entities. (AC-24, AC-11)
- [x] 3. Create `AccountInvitation` entity + repository. (AC-4, AC-5, AC-6)
- [x] 4. Create `AccountEventType` enum + `AccountEvent` entity + repository. (AC-9, AC-14, AC-17, AC-18)
- [x] 5. Create `AccountDeletionLog` entity + repository, unique on `subject_user_id`. (AC-21, AC-22, AC-23)
- [x] 6. Generate and hand-finish the migration: `app_user` alter (columns, index, status
  CHECK rewrite), `profile`/`profile_trainer` (+ `pg_trgm`), `account_invitation`,
  `account_event`, `account_deletion_log`. Run against dev + test DB. (schema for all ACs)

## Services

- [x] 7. Extract `SelectorVerifierTokenFactory` from `EmailVerificationTokenService`
  (shared crypto, no behavior change to S1 — regression-test S1's verification flow
  still passes after the extraction). (AC-5 foundation)
- [x] 8. `AccountEventRecorder` + per-event-type typed DTOs (mirrors `AuthEventRecorder`).
  (AC-9, AC-14, AC-17, AC-18)
- [x] 9. `TrainerOnboardingService::createTrainer()` — user + `ProfileTrainer` + invitation
  + audit + mail, one transaction, unique-violation mapping reused from
  `UserAccountService`. (AC-4, AC-5, AC-7, AC-9)
- [x] 10. `AccountInvitationController` (GET form / POST consume), reusing
  `ChangePasswordFormType` rather than a near-duplicate form type. (AC-5, AC-6)
- [x] 11. `trainer_invitation` mail template (html + text) wired through existing
  `SendEmailMessage`. (AC-5)
- [x] 12. `FileStorage` service (validate real MIME, size cap, opaque key, store under
  `var/uploads/`). (AC-12)
- [x] 13. `ProfileService` (`updateCommon`, `updateTrainerDetails`, `uploadPhoto`) +
  `ProfileCommonFormType`, `ProfileTrainerFormType`. (AC-10, AC-11, AC-12)
- [x] 14. `ProfileController` (`/profile`, always `$this->getUser()`) + `PhotoController`
  (`/photos/{userId}`, self or Super Admin only). (AC-10, AC-11, AC-12, AC-13)
- [x] 15. `AccountLifecycleService::deactivate/reactivate/delete` with the state-machine
  guards (refuse deactivate-on-`DELETED`, refuse reactivate-unless-`DEACTIVATED`, refuse
  delete-if-already-`DELETED`). (AC-14, AC-15, AC-16, AC-17, AC-18, AC-19, AC-20, AC-21,
  AC-22, AC-23)
- [x] 16. `UserRepository::search()` — keyset pagination, role/status filters, ILIKE
  search over name/email (+ `profile_trainer.business_name` when relevant). (AC-1, AC-2,
  AC-3)
- [x] 17. `Admin\UserController` (`index`, `create`, `edit`, `deactivate`, `reactivate`,
  `delete`), `#[IsGranted('ROLE_SUPER_ADMIN')]` on the class. Templates: directory list,
  create-trainer form, edit form, deactivate/delete confirmation. (AC-1, AC-2, AC-3, AC-4,
  AC-8, AC-13, AC-14, AC-17, AC-18)

## Tests

- [x] 18. Users-directory functional tests: access control, pagination, each filter,
  search (name/email/business-name), including the escaped-wildcard edge case. (AC-1,
  AC-2, AC-3, edge case) — `tests/Functional/AdminUsersDirectoryTest.php`.
- [x] 19. Trainer-creation functional tests: happy path, duplicate email, no-self-
  registration (via S1's router-sweep test, which already covers any new route
  automatically). (AC-4, AC-7, AC-8, AC-9, edge case) —
  `tests/Functional/TrainerOnboardingFlowTest.php`.
- [x] 20. Invitation functional tests: consume happy path, invalid token, already-
  consumed, sets password + verifies email + does not auto-sign-in. (AC-5, AC-6, edge
  case) — same file as Task 19.
- [x] 21. Profile self-service functional tests: common fields, trainer fields, business
  fields hidden for non-trainers, id-spoofing-has-nowhere-to-land, photo upload
  valid/oversized, cross-user photo access denied. (AC-10, AC-11, AC-12, AC-13, edge
  case) — `tests/Functional/ProfileSelfServiceTest.php`.
- [x] 22. Deactivate/reactivate functional tests: sign-in refused after deactivate, S1's
  `EquatableInterface` ends an open session, reactivate restores sign-in, guard rejects
  reactivating an already-active account, admin UI flow. (AC-14, AC-15, AC-16, AC-17,
  edge case) — `tests/Functional/AccountLifecycleFlowTest.php`.
- [x] 23. Deletion functional tests: anonymized fields exactly as specified, sign-in
  refused, second delete refused (no-op), deletion log row correct, deactivate-a-deleted-
  account refused, admin UI flow. (AC-18, AC-19, AC-20, AC-21, AC-22, AC-23, edge case) —
  same file as Task 22.
- [x] 24. Unit tests: `User::anonymize()`, `User::getDisplayName()` fallback, anonymized-
  email non-collision. `AccountLifecycleService`'s guards and `UserRepository::search()`'s
  keyset pagination/trigram search are exercised functionally in Tasks 18/22/23 rather
  than duplicated in isolation. — `tests/Entity/UserTest.php`.

## Review and verification

- [x] 25. `code-reviewer` + `security-reviewer` pass (IDOR on profile/photo routes, mass
  assignment on admin edit form, CSRF on every state-changing route, file-upload
  validation, anonymization completeness). Both passes complete 2026-08-20: security
  review found H-1 (invitation survives deactivation/deletion), M-1 (GDPR log retained
  real email), M-2 (orphaned photo file), M-3 (admin edit re-personalizes a DELETED
  account) — all four fixed and regression-tested. Code-quality review independently
  found the same H-1/M-1 plus three more Highs — two-phase trainer creation orphaning a
  user row on failure, the delete-guard's concurrent-double-delete race surfacing as a
  500 instead of a clean refusal, and `getDisplayName()` not returning "Deleted User" —
  all three fixed with real-DB (not mocked) regression tests. Remaining M-4 (no CSP/
  nosniff header), M-5 (migration `down()` unsafe once a row is `DELETED`), M-6 (no
  CSRF regression tests for S2's own endpoints), and the Low-severity items from both
  reviews are accepted as follow-up work, not blockers.
- [x] 26. Full regression: `bin/phpunit` (S1 + S2) — 187 tests, 702 assertions, green;
  `doctrine:schema:validate` — mapping and DB in sync; `debug:router` — every S2 route
  present, no self-registration route. S2's AC-1…AC-24 and S1's AC-1…AC-25 hold.
