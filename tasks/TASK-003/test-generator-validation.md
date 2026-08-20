---
description: Which Tasks 22-24 (player-side ShareLink functional test) requirements are held up by which tests, and which currently fail against real implementation behavior.
---

# Validation Map — TASK-003 Tasks 22-24 (Player-side ShareLink functional tests)

| Requirement | Source | Test | State |
| --- | --- | --- | --- |
| AC-6: usage is attributed to the specific link, tally retained (registration path) | `specs/sdd-sharelink-invitations-spec.md` | `PlayerShareLinkRegistrationTest::testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9` | covered, test currently **fails** (real bug, see below) |
| AC-6: usage is attributed to the specific link, tally retained (signed-in follow path) | spec | `PlayerShareLinkAssociationTest::testSignedInPlayerFollowingALinkCreatesAnInstantAssociationWithNoFormAc6Ac11` | covered, test currently **fails** (same bug) |
| AC-6: idempotent re-follow does not increment the tally | spec | `PlayerShareLinkAssociationTest::testFollowingTheSameLinkTwiceIsIdempotentAc6Ac13` | covered, test currently **fails** (same bug) |
| AC-7: anonymous follow leads to a registration form capturing name/email/password/phone/player name/age/gender | spec | `PlayerShareLinkRegistrationTest::testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9` | covered (the redirect-to-form half passes; the full-submission half fails on the same bug) |
| AC-8: submitting creates exactly one account, one trainer-player association naming the right trainer, and the trainer's roster shows the player | spec | `PlayerShareLinkRegistrationTest::testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9` | covered, test currently **fails** (same bug) |
| AC-9: completing registration sends a confirmation email | spec | `PlayerShareLinkRegistrationTest::testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9` | covered, test currently **fails** — and this is the literal assertion the bug prevents from ever being true in production, not just in the test |
| AC-10: an email already in use is refused with a field-level error, no duplicate account, no unhandled failure | spec | `PlayerShareLinkRegistrationTest::testRegisteringWithAnEmailAlreadyInUseIsRefusedWithAFieldErrorAndNoOrphanAc10` | covered, **passes** |
| AC-11: a signed-in Player following a link gets instant association, no form, no separate confirmation step | spec | `PlayerShareLinkAssociationTest::testSignedInPlayerFollowingALinkCreatesAnInstantAssociationWithNoFormAc6Ac11` | covered, test currently **fails** (same bug) |
| AC-12: a signed-in Player with an existing association who follows a different trainer's link gets exactly one new association; the first is untouched; no second account | spec | `PlayerShareLinkAssociationTest::testFollowingASecondTrainersLinkAddsANewAssociationWithoutTouchingTheFirstAc12` | covered, test currently **fails** (same bug, on the very first follow in the sequence) |
| AC-13: following a link for a trainer already associated with is idempotent, no duplicate association | spec | `PlayerShareLinkAssociationTest::testFollowingTheSameLinkTwiceIsIdempotentAc6Ac13` | covered, test currently **fails** (same bug) |
| AC-20: a player ShareLink only ever creates/extends a Player-role association; Coach/Trainer/Super Admin refused | spec | `PlayerShareLinkAssociationTest::testSignedInCoachFollowingAPlayerLinkIsRefusedAc20`, `::testSignedInTrainerFollowingAPlayerLinkIsRefusedAc20`, `::testSignedInSuperAdminFollowingAPlayerLinkIsRefusedAc20` | covered, **all pass** |
| Edge case: a DEACTIVATED player follows a player ShareLink → refused | spec | `PlayerShareLinkAssociationTest::testADeactivatedPlayerCannotAssociateViaAShareLink` | covered, **passes** |
| Edge case: a DELETED player follows a player ShareLink → refused | spec | `PlayerShareLinkAssociationTest::testADeletedPlayerCannotAssociateViaAShareLink` | covered, **passes** |
| Edge case: the trainer who owns a ShareLink is DEACTIVATED → "no longer available", same as an unknown code | spec | `PlayerShareLinkAssociationTest::testFollowingALinkWhoseTrainerIsDeactivatedIsRefusedAsNoLongerAvailable` | covered, **passes** |
| Edge case: the trainer who owns a ShareLink is DELETED → "no longer available", same as an unknown code | spec | `PlayerShareLinkAssociationTest::testFollowingALinkWhoseTrainerIsDeletedIsRefusedAsNoLongerAvailable` | covered, **passes** |

## Bug found during this pass (not fixed — out of this delegation's boundary)

**Symptom:** `HTTP 500`, `LogicException: Attempting to change readonly property App\Entity\User::$id.`

**Root cause:** `PlayerShareLinkResolver::findActiveByCode()` (via `PlayerShareLinkRepository`) resolves a `PlayerShareLink` with an `INNER JOIN` to `trainer` used only for the `WHERE trainer.status = :status` filter — `trainer` is never in the `SELECT`/`addSelect()` list. Doctrine therefore leaves `PlayerShareLink::$trainer` as an uninitialized lazy proxy. The first time *any* method is called on that proxy (triggering its lazy hydration), Doctrine's hydrator tries to re-set `User::$id` — a `readonly` property — on the already-identifier-bearing proxy object, and PHP 8.5 / Doctrine ORM 3.6.8's `ReadonlyAccessor` refuses the second write.

Confirmed by direct reproduction outside the HTTP layer (calling `PlayerShareLinkResolver::resolve()` then any method on `->getTrainer()`, and separately via a bare `EntityManager::getReference(User::class, $id)` then any method call) — this is a general "any lazily-loaded `User` proxy crashes on first non-identifier access" defect, not specific to the new S3 code. It was never triggered by Tasks 1-21 because every existing to-one traversal onto `User` in this codebase eager-loads it first (e.g. `TrainerPlayerAssociationRepository::findRosterFor()`'s explicit `addSelect('player')`).

**Where it actually fires in the two new call sites:**
- `PlayerRegistrationService::registerViaShareLink()` — fires on `'trainerName' => $link->getTrainer()->getDisplayName()` when building the confirmation email's context, **after** the `User`/`ProfilePlayer`/`TrainerPlayerAssociation` rows and the `usageCount` increment have already committed. Net effect: registration silently succeeds in the database, but every real user gets a raw 500 instead of the "check your email" page, and the confirmation email is never dispatched (AC-9 violated in production, not just in the test).
- `PlayerShareLinkService::associate()` — fires on `$trainer->isActive()`, evaluated **before** any write. Net effect: a signed-in Player can never actually follow a ShareLink at all; every attempt 500s.

**Not affected:** the role-refusal paths (`ShareLinkVoter` denies access before `associate()` is ever called, and never touches the trainer relation) and the trainer-deactivated/-deleted edge cases (`PlayerShareLinkResolver` filters those out in its `WHERE` clause before any proxy is even created).

**Suggested fix shape (for whoever picks this up — not applied here):** add `addSelect('trainer')` to `PlayerShareLinkRepository::findActiveByCode()`'s query, mirroring `TrainerPlayerAssociationRepository::findRosterFor()`'s existing pattern.

**Status as of Task 39 (2026-08-20): fixed.** `PlayerShareLinkRepository::findActiveByCode()` now does `select('link', 'trainer')` with the `innerJoin('link.trainer', 'trainer')` it already had — confirmed by reading the file directly, not assumed. The bug above no longer reproduces.

---

# Validation Map — TASK-003 Task 39 (Hardening round test coverage)

| Requirement | Source | Test | State |
| --- | --- | --- | --- |
| `share_link_registration_source` 429 on `PlayerShareLinkController::register()` | `tasks/TASK-003/writing-plans-plan.md` Task 39 | `ShareLinkRegistrationSourceThrottleTest::testExceedingShareLinkRegistrationSourceLimiterOnPlayerRegistrationProduces429` | covered |
| `share_link_registration_source` 429 on `CoachInvitationController::accept()`'s registration branch | Task 39 | `ShareLinkRegistrationSourceThrottleTest::testExceedingShareLinkRegistrationSourceLimiterOnCoachInvitationRegistrationProduces429` | covered |
| `coach_invitation_source` 429 on `Trainer\CoachController::invite()` | Task 39 (AC-5, AC-19) | `CoachInvitationSendTest::testExceedingCoachInvitationSourceLimiterProduces429` | covered |
| `coach_invitation_account` field-level error (never 429) on `Trainer\CoachController::invite()` | Task 39 (AC-5, AC-19) | `CoachInvitationSendTest::testExceedingCoachInvitationAccountLimiterRendersAFieldErrorNeverA429` | covered |
| Deactivated inviting trainer → real, non-empty "no longer available" message on `/coach-invitation/{token}` | Task 33, Task 39 (AC-18 edge case) | `CoachInvitationAcceptTest::testAcceptingACoachInvitationWhoseTrainerHasBeenDeactivatedIsRefusedWithARealMessage` | covered |
| Deleted (GDPR-anonymized) inviting trainer → same outcome | Task 33, Task 39 | `CoachInvitationAcceptTest::testAcceptingACoachInvitationWhoseTrainerHasBeenDeletedIsRefusedWithARealMessage` | covered |
| `AccountEventType::PLAYER_REGISTERED_VIA_SHARE_LINK` recorded, actor = subject = new player | `specs/sdd-sharelink-invitations-architecture.md` (AC-9) | `PlayerShareLinkRegistrationTest::testFollowingAPlayerLinkWhileSignedOutRegistersExactlyOneAccountAc6Ac7Ac8Ac9` (extended) | covered |
| `AccountEventType::PLAYER_TRAINER_ASSOCIATED` recorded, actor = subject = player, only on a genuinely new association | architecture doc (resolved 2026-08-20 gap) | `PlayerShareLinkAssociationTest::testSignedInPlayerFollowingALinkCreatesAnInstantAssociationWithNoFormAc6Ac11` (extended) | covered |
| `AccountEventType::COACH_INVITATION_ACCEPTED` recorded, actor = subject = coach, context carries `{trainerId, invitationId}` | architecture doc (AC-15) | `CoachInvitationAcceptTest::testAcceptingAsABrandNewCoachCreatesAccountAssociationAndMarksInvitationAcceptedAc14Ac15` (extended) | covered |
| Two genuinely concurrent connections both incrementing `usage_count` lose no increment (the real Task 32 fix, not the retired `incrementUsage()` entity method) | Task 32, Task 39 (AC-6) | `PlayerShareLinkUsageCountConcurrencyTest::testTwoGenuinelyConcurrentConnectionsBothIncrementingUsageCountLoseNoIncrementAc6` | covered |
| Leave then rejoin creates a genuinely new association (not resurrected), tallies `usageCount` again, hides/restores both rosters | Task 36 (AC-11 amendment) | `PlayerTrainerRosterLeaveTest::testLeavingATrainerThenRejoiningCreatesANewAssociationNotAResurrectedOne` | covered |
| AC-12 still holds once leaving exists: leaving Trainer A never touches a still-active association with Trainer B | AC-12, Task 36 | `PlayerTrainerRosterLeaveTest::testLeavingOneTrainerNeverTouchesAStillActiveAssociationWithAnotherTrainer` | covered |
| Duplicate-email and novel-email registration responses are byte-identical (status and body) | Task 35 (AC-10 amendment) | `PlayerShareLinkRegistrationTest::testDuplicateAndNovelEmailRegistrationResponsesAreByteIdenticalAc10` | covered |
| Duplicate-email notice email is sent to the existing account, not the prober | Task 35 | `PlayerShareLinkRegistrationTest::testRegisteringWithAnEmailAlreadyInUseGetsTheSameSuccessResponseAndNotifiesTheExistingAccountAc10` (pre-existing, Task 35's own coverage) | covered |
| `app:sweep-unverified-accounts` dry-run vs. real delete, second-run no-op, invalid `--hours` | Task 37 | `SweepUnverifiedAccountsCommandTest` (4 test methods) | covered |

No implementation bug was found by this pass. One general, pre-existing ORM defect (not introduced by Tasks 32-38) was re-encountered while writing `PlayerTrainerRosterLeaveTest`: fully re-hydrating a `PlayerShareLink` by id via `EntityManager::find()`, after a `TrainerPlayerAssociation` carrying that same `PlayerShareLink` as its lazy `shareLink` association has already been loaded in the same identity-map generation, crashes with `LogicException: Attempting to change readonly property App\Entity\PlayerShareLink::$id` — the same class of defect this file's Tasks 22-24 section documents for `User` proxies, now confirmed to generalize to `PlayerShareLink` proxies reached via a different association path. Worked around test-side with a raw SQL read instead of `find()`; no production code path was found that both loads a `TrainerPlayerAssociation` and independently re-fetches its `PlayerShareLink` by id in the same request, so this is not currently reachable in production, but is worth the general framework-level fix (an eager `addSelect` at the relevant call sites, or a Doctrine ORM upgrade past the affected version) whenever someone picks up the Tasks 22-24 finding above.
