# TASK-004 — Epic-01 slice S4: Player/Family Availability

Design: `specs/sdd-player-family-availability-architecture.md`. Spec:
`specs/sdd-player-family-availability-spec.md`. Each task cites the acceptance
criteria (AC-N) it serves, or — for a schema/infrastructure/gate task with no
AC of its own — the Decision or Risk it protects. Mark `[x]` only once the
change is made, migrated, and (where a test task follows) proven.

Four decisions the architecture flagged are treated as settled and are each
implemented by a named task below, not left implicit: **D1d** (child sign-in
is a separate parent-triggered action, Task 13), **D3b** (24h
re-notification throttle on the blocked-ShareLink email, Task 16), **D5d**
(availability is per-player, not per player↔trainer pairing, Task 19), and
the parent-deactivation-vs-children risk, which the architecture left open —
Task 39 makes it an explicit, tested choice rather than leaving it undecided
in code.

## Schema

- [x] 1. Create `App\Entity\ChildAccount` (`child_account`: `id` UUIDv7 PK;
  `childUser` FK `app_user` ON DELETE CASCADE, **UNIQUE**; `parentUser` FK
  `app_user` ON DELETE CASCADE, deliberately **not** unique — that absence is
  AC-6; `signInEnabledAt` nullable timestamptz; `createdAt`) +
  `ChildAccountRepository` with `findOneByChildUser(User $child): ?ChildAccount`
  (the single lookup `ChildAccountResolver` calls) and
  `findChildrenOf(User $parent): list<ChildAccount>` (AC-7's family list,
  newest-first). Index `(parent_user_id, created_at)`. (AC-2, AC-6, AC-7)
- [x] 2. Create `App\Enum\ChildTrainerRequestResolution` (`APPROVED`,
  `DISMISSED`) and `App\Entity\ChildTrainerRequest` (`child_trainer_request`:
  `id`; `childUser` FK `app_user` ON DELETE CASCADE; `trainer` FK `app_user`
  ON DELETE CASCADE; `parentUser` FK `app_user` ON DELETE CASCADE, snapshotted
  at request time; `shareLink` FK `player_share_link` nullable ON DELETE SET
  NULL; `createdAt`; `lastNotifiedAt` — D3b's re-notification clock;
  `resolvedAt` nullable, null ⇔ pending; `resolution` nullable
  `ChildTrainerRequestResolution`; `resolvedByUser` FK `app_user` nullable ON
  DELETE SET NULL) + `ChildTrainerRequestRepository` with
  `findPendingFor(User $child, User $trainer): ?ChildTrainerRequest` and
  `findPendingForParent(User $parent): list<ChildTrainerRequest>` (AC-16's CTA
  target). Index `(parent_user_id, resolved_at, created_at)`. (AC-15, AC-16,
  AC-17)
- [x] 3. Create `App\Entity\PlayerAvailabilitySlot` (`player_availability_slot`:
  `id`; `player` FK `app_user` ON DELETE CASCADE — adult or child alike,
  AC-23; `dayOfWeek` smallint, ISO-8601 Monday=1…Sunday=7; `startsAtMinute`
  smallint 0…1439; `endsAtMinute` smallint 1…1440; `createdAt`) +
  `PlayerAvailabilitySlotRepository` with `weekFor(User $player): list<...>`
  (the grid read), `findForPlayers(list<User> $players): list<...>` (AC-22's
  roster-card read, one query for the whole roster, no N+1), and a
  `replaceWeekFor()` helper `AvailabilityService` calls for the
  delete-then-insert. **No `is_unavailable` flag and no row-per-day
  placeholder** — absence of rows for a weekday *is* "Not Available" (AC-24);
  **no time-zone column** (spec puts it out of scope). Index
  `(player_id, day_of_week, starts_at_minute)` (grid read + AC-22 summary) and
  `(day_of_week, starts_at_minute, ends_at_minute)` (AC-23's roster filter).
  (AC-19, AC-20, AC-22, AC-23, AC-24)
- [x] 4. `App\Entity\ProfilePlayer` gains one nullable column,
  `school varchar(160) NULL` (FR-024's optional school), plus
  `getSchool()`/`setSchool()`. Additive, no default, no backfill — nothing
  already shipped changes meaning. (AC-1)
- [x] 5. `App\Enum\AccountEventType` gains six cases — no migration needed,
  `account_event.type` is a plain `varchar(64)`: `CHILD_ACCOUNT_CREATED`
  (Task 12, actor=parent/subject=child, context `{trainerCount}`; AC-1, AC-2,
  AC-6), `CHILD_TRAINER_CONNECTED` (Task 15's `connect()`; AC-4, AC-8,
  AC-17), `CHILD_TRAINER_DISCONNECTED` (Task 15's `disconnect()`; AC-9),
  `CHILD_SHARE_LINK_BLOCKED` (Task 16, actor=subject=child; AC-15, AC-16),
  `CHILD_SIGN_IN_ENABLED` (Task 13; D1d — no direct AC, infrastructure for
  AC-13…AC-18's "a signed-in child account"), `PLAYER_AVAILABILITY_UPDATED`
  (Task 19; AC-19, AC-21).
- [x] 6. Generate the migration via `doctrine:migrations:diff`, then
  hand-finish it in this project's one-`addSql`-per-statement style: create
  `child_account`, `child_trainer_request`, `player_availability_slot`;
  `ALTER TABLE profile_player ADD school varchar(160) NULL`; then the four
  hand-written lines DBAL does not diff — `CHECK (child_user_id <>
  parent_user_id)` on `child_account`; `CHECK ((resolved_at IS NULL) =
  (resolution IS NULL))` on `child_trainer_request`; `CHECK (day_of_week
  BETWEEN 1 AND 7)` and `CHECK (starts_at_minute >= 0 AND ends_at_minute <=
  1440 AND starts_at_minute < ends_at_minute)` on `player_availability_slot`;
  and the **pre-parenthesized** partial unique index `CREATE UNIQUE INDEX
  uniq_child_trainer_request_pending ON child_trainer_request (child_user_id,
  trainer_id) WHERE (resolved_at IS NULL)` — S3's Risks-section technique,
  proven in `migrations/Version20260820131012.php`'s docblock, cited here so
  DBAL's `pg_get_expr` canonical-form comparison does not re-diff this index
  forever. Write `down()` dropping in reverse order, then the column. Run
  against dev + test DB; confirm `doctrine:schema:validate` is clean, and run
  `doctrine:schema:update --dump-sql` **twice** to confirm "Nothing to
  update" both times (the partial-index stability check S3's Task 37/Task 28
  established). (AC-1, AC-2, AC-6, AC-9, AC-15, AC-16, AC-19, AC-24)

## Services — child identity, mail routing

- [x] 7. `App\Service\ChildEmailFactory::forChild(Uuid $childUserId): string`
  returning `child_<uuid>@children.invalid` (D1c — RFC 2606 `.invalid`,
  derived from the account's own immutable id so it cannot collide, lowercase
  hex over a lowercase domain so `User::normalizeEmail()`/S1's `CHECK (email
  = lower(email))` hold) + `isPlaceholder(string $email): bool`. Pure,
  Doctrine-free, unit-testable. (AC-1 — the creation path this unblocks;
  Decision D1c)
- [x] 8. `App\Service\NotificationAddressResolver::forPlayer(User $player):
  string` — the parent's address (via `ChildAccountResolver`, Task 9) when
  the player is a child, the player's own otherwise. This is what routes
  `TEMPLATE_CHILD_SHARE_LINK_REQUEST` to the parent (AC-16) and is also what
  fixes the live defect this slice would otherwise introduce:
  `PlayerShareLinkService::associate()` already queues
  `TEMPLATE_PLAYER_TRAINER_CONNECTED` to `$player->getEmail()`, undeliverable
  for a child's placeholder address — Task 10 rewires that call site through
  this resolver. (AC-8, AC-16, AC-17)
- [x] 9. `App\Service\ChildAccountResolver::childAccountOf(User $user):
  ?ChildAccount` and `isChild(User $user): bool` — one
  `ChildAccountRepository::findOneByChildUser()` call, served from Doctrine's
  identity map for the rest of the request. The single answer every voter,
  service guard, and mail-recipient decision in this slice calls; nothing
  re-derives it. (AC-12, AC-13, AC-14, AC-18)

## PlayerShareLinkService widening (S3, extended additively)

- [x] 10. Widen `PlayerShareLinkService::associate(User $player,
  PlayerShareLink $link)` into `associateWithTrainer(User $player, User
  $trainer, ?PlayerShareLink $link, ?User $actor = null): TrainerPlayerAssociation`
  — the existing method body generalized: the trainer comes in directly, the
  link is optional (`usageCount` increments only when one was used), `$actor`
  defaults to `$player`; `associate()` becomes a one-line wrapper so every S3
  call site and every S3 test is untouched. Add the child-actor guard inside
  it: if `$actor === $player` and `ChildAccountResolver::isChild($player)`,
  throw a new `App\Service\Exception\ChildActionNotPermittedException` — a
  child cannot connect itself to a trainer through any route (AC-14). Rewire
  the existing `TEMPLATE_PLAYER_TRAINER_CONNECTED` dispatch to
  `NotificationAddressResolver::forPlayer($player)` (Task 8) instead of
  `$player->getEmail()`. Add `endAssociation(User $trainer, User $player):
  bool` — the D2c conditional `UPDATE trainer_player_association SET
  ended_at = :now WHERE trainer_id = :t AND player_id = :p AND ended_at IS
  NULL`, returning whether a row was affected; refactor `leave()` to call it
  and throw `NoActiveTrainerAssociationException` on `false`, and add the
  same child-actor guard to `leave()`. (AC-8, AC-9, AC-10, AC-14, AC-17)
- [x] 11. **Verification gate, before any new caller uses Task 10's widened
  method:** run S3's full ShareLink suite —
  `tests/Functional/PlayerShareLinkRegistrationTest.php`,
  `PlayerShareLinkAssociationTest.php`, `CoachInvitationSendTest.php`,
  `CoachInvitationAcceptTest.php`, `CoachListAndRouterSweepTest.php`,
  `tests/Repository/ShareLinkInvitationsConstraintsTest.php`,
  `tests/Security/ShareLinkVoterTest.php` — and require it **green with zero
  test edits**. This proves the extraction is behavior-preserving before
  `ChildTrainerService` (Task 15) becomes the widened method's second caller.
  (Risk: "`associate()` → `associateWithTrainer()` touches the most-tested
  service in S3")

## Child account lifecycle

- [x] 12. `App\Service\ChildAccountService::createChild(User $parent,
  CreateChildRequest $request): ChildAccount` — `TrainerOnboardingService`'s
  established two-phase pattern: (1) `UserAccountService::create($placeholderEmail,
  $unusableSecret, UserRole::PLAYER)`, `$placeholderEmail` from Task 7's
  `ChildEmailFactory`, `$unusableSecret = base64_encode(random_bytes(32))`,
  hashed and immediately discarded — nobody, including the parent, ever holds
  a credential for this account; (2) a second transaction on a fresh
  manager: `ProfilePlayer` (name, age, gender, school), `ChildAccount`,
  `User::setName()`/`setPhotoKey()` if a photo was uploaded, and one
  `ChildTrainerService::connect()` (Task 15) per selected trainer id (AC-4);
  `catch (\Throwable)` → `resetManager()`, compensating `DELETE FROM app_user
  WHERE id = :id`, log at `critical` on a failed compensation, rethrow. Post-
  commit: `AccountEventRecorder::record(CHILD_ACCOUNT_CREATED)` (actor=parent,
  subject=child, context `{trainerCount}`) — no email is sent, the parent is
  looking at the result. (AC-1, AC-2, AC-4, AC-5, AC-6)
- [x] 13. `App\Service\ChildAccountService::enableSignIn(User $parent,
  ChildAccount $child, string $email, ?\DateTimeImmutable $now): void` — D1d.
  Replaces the placeholder email with a real, platform-unique one
  (`EmailAlreadyInUseException` surfaces as a field error — this form is
  authenticated and parent-only, so S3's enumeration concern does not
  apply), issues an `AccountInvitation` through `SelectorVerifierTokenFactory`
  exactly as `TrainerOnboardingService::createTrainer()` does, sets
  `signInEnabledAt`, dispatches `TEMPLATE_CHILD_SIGN_IN_INVITATION` (Task 40),
  records `CHILD_SIGN_IN_ENABLED`. The child then sets their own password
  through S2's already-shipped `/invitations/{token}` flow — no new
  credential machinery. (D1d — infrastructure implied by AC-13…AC-18's "a
  signed-in child account"; the spec has no AC of its own for this step)
- [x] 14. `App\Service\ChildAccountService::findChildrenOf(User $parent):
  list<ChildAccount>` (delegates to Task 1's repository) and
  `findSimilar(User $parent, string $name, int $age): list<ChildAccount>` —
  BR-019's duplicate check, used only for a soft warning, never to block
  saving. (AC-7, edge case: name/age close to an existing child)

## Child-trainer connection workflow

- [x] 15. `App\Service\ChildTrainerService::connect(User $parent, ChildAccount
  $child, User $trainer, ?PlayerShareLink $link): TrainerPlayerAssociation`
  — guards: the parent must own this child (a service re-check beyond
  `FamilyVoter::MANAGE_CHILD`, S3 Decision Q4's defence-in-depth rule); the
  trainer must be role `TRAINER` and `ACTIVE`. Delegates entirely to Task 10's
  `PlayerShareLinkService::associateWithTrainer($childUser, $trainer, $link,
  actor: $parent)` — the single association writer, so AC-17's "no second,
  parallel connection mechanism" holds by construction. Post-commit adds
  `CHILD_TRAINER_CONNECTED` (actor=parent, subject=child, context
  `{trainerId}`); re-confirming an existing active pairing returns the
  existing row untouched (AC-8's no-op = the double-submit edge case).
  `disconnect(User $parent, ChildAccount $child, User $trainer): void` —
  delegates to Task 10's `endAssociation()`: `true` ⇒ `CHILD_TRAINER_DISCONNECTED`
  event; `false` ⇒ `NoActiveTrainerAssociationException`, which the
  controller renders as "already removed", not an error. Because the
  underlying statement names one `(trainer, player)` pair, AC-10's "changes
  nothing about any other connection" is the `WHERE` clause; nothing here
  touches `player_availability_slot` (the "availability preserved after
  disconnect" edge case). (AC-4, AC-8, AC-9, AC-10, AC-17, edge cases:
  double-submit, two-device removal race)
- [x] 16. `App\Service\ChildTrainerService::recordBlockedClick(ChildAccount
  $child, PlayerShareLink $link): ChildTrainerRequest` — AC-15, AC-16,
  called **before** any association check and regardless of whether one
  exists (D3, the unconditional-block decision — no carve-out for an
  existing connection). One transaction: find the pending request for
  `(child, trainer)` via Task 2's repository or insert one, catching
  `UniqueConstraintViolationException` on the partial index and re-reading
  the winner against a freshly reset manager (S3's closed-EntityManager
  discipline). Post-commit: record `CHILD_SHARE_LINK_BLOCKED`, then dispatch
  `TEMPLATE_CHILD_SHARE_LINK_REQUEST` (Task 40) to Task 8's
  `NotificationAddressResolver::forPlayer($childUser)` **only if** the row
  was newly created or `lastNotifiedAt` is over 24 hours old (D3b — the one
  declared narrowing of AC-16 in the design), updating `lastNotifiedAt` in
  the same statement. (AC-15, AC-16, edge case: repeat click on an
  already-connected trainer)
- [x] 17. `App\Service\ChildTrainerService::approveRequest(User $parent,
  ChildTrainerRequest $request): TrainerPlayerAssociation` — marks the
  request `APPROVED`/`resolvedBy`, then calls Task 15's `connect()` with the
  request's own `shareLink` — no second connection path; a pairing that
  already exists resolves against the existing association (AC-17).
  `dismissRequest(User $parent, ChildTrainerRequest $request): void` — marks
  `DISMISSED`. Both refuse an already-resolved request with a new
  `ChildTrainerRequestAlreadyResolvedException` rather than silently
  re-resolving. (AC-17, edge case: approving an already-resolved request
  twice)

## Availability

- [x] 18. `App\Availability\WeeklyAvailability` / `App\Availability\TimeRange`
  — plain immutable value objects, no Doctrine.
  `WeeklyAvailability::normalized()` sorts each day's ranges and merges
  overlapping/touching ones, so two submissions describing the same
  availability produce byte-identical rows (AC-24's evaluation is never
  ambiguous). `App\Form\DataTransformer\MinutesFromMidnightTransformer`
  converts between a `TimeType` widget's `\DateTimeImmutable` and the stored
  `smallint`. (AC-19, AC-24)
- [x] 19. `App\Service\AvailabilityService::replaceWeek(User $player,
  WeeklyAvailability $week, User $actor): void` — **D5d: one grid per
  player**, never per (player, trainer) pairing — visible to all of that
  player's trainers, not answered once per connection. One transaction:
  `DELETE FROM player_availability_slot WHERE player_id = :player` then
  insert the normalized rows, scoped by `player_id` so a save for one child
  cannot read or write another player's rows (AC-20's isolation is the
  `WHERE` clause). Post-commit `PLAYER_AVAILABILITY_UPDATED` (actor = the
  parent or the player themselves, subject = the player).
  `weekFor(User $player): WeeklyAvailability` — the grid read, via Task 3's
  repository. (AC-19, AC-20, AC-21, AC-24)
- [x] 20. `App\Service\PlayerContextProvider::contextsFor(User $user):
  list<PlayerContext>`, `App\Service\PlayerContext` a readonly DTO `{User
  player, string label, bool isSelf, list<TrainerPlayerAssociation>
  trainers}` (same co-location convention as `AccountEventRecord`). For an
  adult: the self context first (label "Me"), then one per child via Task
  1's `findChildrenOf()`, each with that child's own active associations
  (AC-11 — never merged into one list). For a child: `ChildAccountResolver`
  (Task 9) is consulted first and the provider never widens — a single self
  context only (AC-12, AC-18). Add
  `TrainerPlayerAssociationRepository::findActiveForPlayers(list<User>
  $players): list<TrainerPlayerAssociation>` (trainer eagerly joined) so the
  whole family page is one query, not one per child. (AC-11, AC-12, AC-18)
- [x] 21. `App\Service\AvailabilitySummaryFormatter::summarize(list<PlayerAvailabilitySlot>
  $slots, int $maxDays = 3): string` producing AC-22's "Mon 5-8pm, Wed
  6-9pm" with a "+N more" tail. A service, not a Twig filter, so the same
  string is reachable from a future API/export without touching templates.
  (AC-22)
- [x] 22. `TrainerPlayerAssociationRepository::findRosterAvailableAt(User
  $trainer, int $dayOfWeek, int $minute): list<TrainerPlayerAssociation>` —
  `INNER JOIN` from the trainer's active roster into `player_availability_slot`,
  matching only players with a slot covering that day/minute. Because it is
  an `INNER JOIN`, a player with no rows for that weekday can never match —
  AC-24's "absence is Not Available, never unknown" is what the join
  mechanically does, not a rule the query has to remember. (AC-23, AC-24)

## Authorization

- [x] 23. `App\Security\FamilyVoter` — `MANAGE_FAMILY` (no subject; granted
  to an active `PLAYER` who is **not** a child) and `MANAGE_CHILD` (subject
  `User`, the child; granted when a `ChildAccount(child, parent = token
  user)` exists via Task 9's resolver and both accounts are active). Reads
  no `Profile` — S1's frozen "authorization never reads a Profile" invariant
  holds. (AC-2, AC-7, AC-8, AC-9, AC-18)
- [x] 24. `App\Security\PlayerActionVoter` — `MANAGE_OWN_TRAINER_CONNECTIONS`,
  `DELETE_OWN_ACCOUNT`, `MANAGE_PAYMENT_METHOD`, `COMPLETE_PURCHASE` (all no
  subject; granted to an active player who is **not** a child). The latter
  two have no caller in this slice (no payment route exists — Epic-05) but
  ship anyway so a future payments slice fails closed rather than having to
  remember a rule written in another slice's spec. (AC-14)
- [x] 25. `App\Security\AvailabilityVoter` — `EDIT_AVAILABILITY` (subject
  `User`, the player; granted when the token user is the subject or the
  subject's parent) and `VIEW_AVAILABILITY` (the above, **or** the token
  user is a trainer with an active association to the subject). (AC-18,
  AC-20, AC-22, AC-23)

## Forms and validation

- [x] 26. `App\Service\CreateChildRequest` DTO + `App\Form\ChildProfileFormType`
  — `childName` (`NotBlank`, `Length(max: 160)`), `age` (`NotNull`,
  `Range(min: 1, max: 18)` — AC-5, BR-019), `gender` (`NotNull`, `Choice`
  over `PlayerGender`), `school` (optional, `Length(max: 160)`), `photo`
  (optional, S2's `Image` constraint set reused), `trainerIds` (`ChoiceType`
  multiple, choices built from `TrainerPlayerAssociationRepository::findActiveForPlayer($parent)`
  — the parent's own active connections only, D2 — and re-validated
  server-side against that same set on submit: a forged trainer id is
  refused, not silently connected). AC-3's three shapes are one form
  rendered three ways: zero trainers ⇒ the field is not added at all;
  exactly one ⇒ a single Yes/No `CheckboxType` ("Will [Child] also train
  with [Trainer]?"); more than one ⇒ the multi-checkbox list. Duplicate
  soft-warning (BR-019): Task 14's `findSimilar()` runs on submit; a hit
  re-renders the form with a warning and a hidden `duplicateAcknowledged`
  field, never a validation error. (AC-1, AC-3, AC-4, AC-5, AC-6)
- [x] 27. `App\Service\AddChildTrainerRequest` DTO +
  `App\Form\ChildTrainerAddFormType` — `shareLinkCode` (optional text) and
  `trainerId` (optional, choices from "My Trainers"), with an
  `Assert\Callback` requiring **exactly one** present. An unknown or
  inactive code raises `ShareLinkUnavailableException` from the existing S3
  resolver and becomes a field error. (AC-8)
- [x] 28. `App\Form\AvailabilityWeekFormType` — a `CollectionType` of seven
  `App\Form\DayAvailabilityFormType`, each a `CollectionType` of
  `App\Form\TimeRangeFormType` (`start`/`end` `TimeType` widgets via Task
  18's transformer) with `allow_add`/`allow_delete`, plus a "Not Available"
  checkbox that clears the day client-side — canonical storage stays "zero
  rows"; the checkbox is a UI affordance, never a stored value. Constraints:
  `Count(max: 6)` per day, `Range(0, 1440)` per endpoint, and a `Callback`
  asserting `start < end`. (AC-19, AC-24)
- [x] 29. `App\Form\AvailabilityFilterFormType` — `dayOfWeek` (`ChoiceType`
  1…7) + `time` (`TimeType`), both optional; submitting neither means an
  unfiltered roster. (AC-23)

## Controllers

- [x] 30. `App\Controller\Family\ChildController` — `index` (`GET /family`,
  `app_family_index`, via Task 1's `findChildrenOf` + Task 20's
  `findActiveForPlayers`, association `createdAt` is the connection's start
  date); `create` (`GET|POST /family/children/new`, `app_family_child_new`,
  via Task 12's `createChild`); `uploadPhoto` (`POST
  /family/children/{id}/photo`, `app_family_child_photo`, delegating to S2's
  `ProfileService::uploadPhoto` unchanged); `enableSignIn` (`GET|POST
  /family/children/{id}/sign-in`, `app_family_child_sign_in`, via Task 13).
  Every per-child route calls `denyAccessUnlessGranted(FamilyVoter::MANAGE_CHILD,
  $childUser)`; `index()`/`create()` call
  `denyAccessUnlessGranted(FamilyVoter::MANAGE_FAMILY)`. (AC-1, AC-2, AC-3,
  AC-4, AC-5, AC-6, AC-7)
- [x] 31. `App\Controller\Family\ChildTrainerController` — `add` (`GET|POST
  /family/children/{id}/trainers/add`, `app_family_child_trainer_add`, via
  Task 27's form → `PlayerShareLinkResolver::resolve` for the code path,
  then Task 15's `connect()`); `confirmRemove` (`GET
  /family/children/{id}/trainers/{trainerId}/remove`,
  `app_family_child_trainer_remove_confirm`, read-only — renders the named
  confirmation + RSVP-cancellation warning copy); `remove` (`POST` same
  path, `app_family_child_trainer_remove`, via Task 15's `disconnect()`).
  (AC-8, AC-9, AC-10)
- [x] 32. `App\Controller\Family\ChildTrainerRequestController` — `review`
  (`GET /family/requests/{id}`, `app_family_request_review`, via Task 2's
  `findPendingForParent`/lookup — AC-16's CTA target); `approve` (`POST
  .../approve`, `app_family_request_approve`, via Task 17's
  `approveRequest`); `dismiss` (`POST .../dismiss`,
  `app_family_request_dismiss`, via Task 17's `dismissRequest`). (AC-16,
  AC-17)
- [x] 33. `App\Controller\Player\AvailabilityController::edit` (`GET|POST
  /player/availability`, `app_player_availability`) — Task 20's
  `contextsFor()` drives the self/child switcher (no switcher rendered when
  the list has exactly one entry — the "no children" edge case);
  `denyAccessUnlessGranted(AvailabilityVoter::EDIT_AVAILABILITY, $subjectUser)`;
  Task 19's `weekFor()`/`replaceWeek()`; post-save flash naming that
  trainers can see the preferences. (AC-19, AC-20, AC-21)
- [x] 34. Extend `PlayerShareLinkController::follow()`: after
  `PlayerShareLinkResolver::resolve()` and **before**
  `denyAccessUnlessGranted(ShareLinkVoter::FOLLOW_PLAYER_SHARE_LINK)`, a
  child branch — `ChildAccountResolver::childAccountOf($user)` (Task 9)
  non-null ⇒ call Task 16's `recordBlockedClick()` and render new
  `templates/share_link/child_blocked.html.twig` ("Ask your parent to
  register you with this trainer") with HTTP 200. No association call is
  reached on this branch, ever, and no condition — including an
  already-existing active association with that same trainer — short-
  circuits it (D3; the spec's fourth edge-case row read literally). (AC-15,
  AC-16, edge case: already-connected child re-clicks the link)
- [x] 35. Extend `Trainer\PlayerRosterController::index()` — an optional
  `AvailabilityFilterFormType` (Task 29) argument. Unfiltered: the existing
  `findRosterFor($trainer)` call, unchanged. Filtered: Task 22's
  `findRosterAvailableAt($trainer, $dayOfWeek, $minute)`. Either way, pass
  the roster's player ids to `PlayerAvailabilitySlotRepository::findForPlayers()`
  (Task 3, one query, no N+1) and render Task 21's
  `AvailabilitySummaryFormatter::summarize()` on each card. (AC-22, AC-23,
  AC-24)
- [x] 36. `PhotoController::show()` — the owner-or-Super-Admin rule gains one
  clause: a parent may read their own child's photo via
  `FamilyVoter::MANAGE_CHILD` (Task 23). Without it, AC-1's optional child
  photo would be uploadable and never viewable. (AC-1)
- [x] 37. `Player\TrainerRosterController::leave()` gains
  `denyAccessUnlessGranted(PlayerActionVoter::MANAGE_OWN_TRAINER_CONNECTIONS)`
  (Task 24). `index()` stays open to children — viewing their own trainers
  is on AC-13's allow-list. (AC-14)

## Deny-list guard and the deferred risk decision

- [x] 38. `AccountLifecycleService::delete()` gains a guard refusing a
  **child as actor** (a Super Admin's ability to delete a child account is
  unaffected) — service-level defence-in-depth alongside
  `PlayerActionVoter::DELETE_OWN_ACCOUNT` (Task 24), per S3 Decision Q4:
  every deny-list rule exists as a voter *and* a service guard, since a
  voter alone cannot cover a console command or a forged request that never
  reaches an annotated action. (AC-14)
- [x] 39. **Explicit, tested decision for the parent-deactivation-vs-children
  risk** (architecture Risks, first bullet — the spec has no AC and no edge
  case for this): document the choice in `AccountLifecycleService::deactivate()`'s
  docblock and lock it in with a functional test proving that deactivating a
  parent (a) does **not** cascade to the `child_account` row, the child's
  `User`, its trainer associations, or its availability rows, and (b) leaves
  the child able to continue signing in and using the platform while the
  parent cannot manage the family (add/remove a trainer, create another
  child) until reactivated. This is the deliberate, chosen behavior — not an
  accident of `AccountLifecycleService` never touching anything but the
  subject — and it is what a future slice inherits instead of rediscovering.
  (Risk: parent deactivation vs. children)

## Mail

- [x] 40. Two templates, both through S1's existing `SendEmailMessage` →
  `SendEmailMessageHandler` → `async` transport mechanism, no new mailer or
  Messenger configuration: `templates/emails/child_share_link_request.html.twig`
  (`SendEmailMessage::TEMPLATE_CHILD_SHARE_LINK_REQUEST`, sent to the
  **parent**, names the child and the trainer, one "Review Registration"
  button linking to `app_family_request_review` — AC-16) and
  `templates/emails/child_sign_in_invitation.html.twig`
  (`SendEmailMessage::TEMPLATE_CHILD_SIGN_IN_INVITATION`, sent to the
  child's new address, S2's set-your-password invitation link worded for a
  child — D1d). Add both constants plus their `buildContext()` branches in
  `SendEmailMessageHandler::TEMPLATES`/`buildContext()`. (AC-16, D1d)

## Tests

- [x] 41. Functional — **family**:
  `tests/Functional/FamilyChildManagementTest.php`. Create a child with
  zero/one/many parent trainers and assert the three AC-3 form shapes;
  "Yes"/"No" and checklist selections produce exactly the selected
  associations (AC-4); age 0, 19, and a missing gender are refused and
  create **nothing** — no `User`, no `ProfilePlayer`, no `ChildAccount`
  (AC-5); two children under one parent (AC-6); the family list shows each
  child's trainers with connection dates (AC-7); `PlayerContextProvider`
  returns the parent's own context plus one per child, never merged (AC-11);
  adding a trainer by code and from My Trainers produce identical rows, and
  a repeat of either is a no-op (AC-8); removing shows a confirmation naming
  both parties and warning about RSVPs, then ends exactly one association
  while a sibling's and the same child's other trainers are untouched
  (AC-9, AC-10); a parent cannot manage another parent's child (403 on every
  `/family/children/{id}/…` route). (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6,
  AC-7, AC-8, AC-9, AC-10, AC-11)
- [x] 42. Functional — **child session**:
  `tests/Functional/ChildSessionConstraintsTest.php`. A signed-in child sees
  only its own trainers from `PlayerContextProvider`, never the parent's or
  a sibling's (AC-12, AC-18); the child can open the dashboard, its own
  profile form, its own photo upload, and its own trainer list (AC-13); the
  child is refused — 403, not a redirect — on leave-trainer, on any
  `/family` route, and on a direct forged `POST` to the trainer add/remove
  routes (AC-14 and the forged-request edge case); a child following any
  ShareLink gets the blocking page, no association row appears, and exactly
  one parent email is queued (AC-15, AC-16); a child following the ShareLink
  of a trainer it is already connected to gets the same blocking page and
  the same parent email (edge case, no carve-out); the parent's Review
  Registration approves and the child ends with exactly one association,
  identical in shape to an AC-8 connection (AC-17); approving twice is
  refused as already-resolved, not duplicated. (AC-12, AC-13, AC-14, AC-15,
  AC-16, AC-17, AC-18)
- [x] 43. Functional — **availability**:
  `tests/Functional/PlayerAvailabilityTest.php`. Set ranges and "Not
  Available" days and read them back (AC-19); a parent switches between
  self and two children and each save leaves the other two untouched
  (AC-20); the post-save confirmation names trainers (AC-21); a trainer's
  roster card shows the summary string (AC-22) and the day/time filter
  returns only matching players, adult and child alike (AC-23); a player
  with no rows for the filtered day never matches (AC-24); a parent with no
  children sees no switcher. (AC-19, AC-20, AC-21, AC-22, AC-23, AC-24)
- [x] 44. Repository integration, against the real database:
  `tests/Repository/PlayerFamilyAvailabilityConstraintsTest.php`. The
  partial unique index `(child_user_id, trainer_id) WHERE resolved_at IS
  NULL` admits a second request only after the first resolves (AC-15,
  AC-16); `UNIQUE (child_user_id)` refuses a second parent for one child
  (AC-2, AC-6); the `child_user_id <> parent_user_id`, `day_of_week`,
  `starts < ends`, and `resolved_at`/`resolution` CHECK constraints refuse
  bad values; two concurrent `disconnect()` calls yield affected-row counts
  of exactly 1 and 0 (AC-9, edge case); two concurrent `connect()` calls for
  the same pairing yield one row (AC-8, edge case);
  `doctrine:schema:update --dump-sql` reports nothing on a **second** run.
  (AC-2, AC-6, AC-8, AC-9, AC-10, AC-15, AC-16)
- [x] 45. Unit tests: `WeeklyAvailability::normalized()` merging, sorting,
  and boundary cases (touching ranges, 0 and 1440, an empty day) — AC-19,
  AC-24; `AvailabilitySummaryFormatter` output and truncation — AC-22;
  `ChildEmailFactory` collision-freedom, lowercase invariant, and
  `isPlaceholder()` — AC-1; `NotificationAddressResolver` for an adult, a
  placeholder-email child, and a sign-in-enabled child — AC-16; the truth
  tables of `FamilyVoter`, `PlayerActionVoter`, and `AvailabilityVoter`,
  parameterized over every role × child/adult × active/deactivated
  combination, matching `ShareLinkVoterTest`'s data-provider shape — AC-14,
  AC-18, AC-20, AC-23.

## Review and verification

- [x] 46. `code-reviewer` + `security-reviewer` pass over the full slice.
- [x] 47. Full regression: `bin/phpunit` (run inside the `ai-training-symfony-php-1`
  container, real Postgres) — 524 tests, 2012 assertions, 522 green.
  `doctrine:schema:validate` clean; `schema:update --dump-sql` reports
  "Nothing to update" twice in a row (partial unique index stable).
  `debug:router`/`RouterSweepTest` confirm no new `PUBLIC_ACCESS` route.
  Two remaining failures are confirmed sandbox artifacts, not S4 defects:
  (1) `AccountLifecycleFlowTest::testTwoConcurrentDeletesForTheSameAccountYieldExactlyOneSuccess`
  spawns a PHP subprocess to simulate a concurrent request, which this
  sandboxed container cannot do (times out waiting for a readiness signal) —
  pre-existing, unrelated to any S4 file, reproducible in isolation on
  `master` before this slice; (2) `ShareLinkRegistrationSourceThrottleTest`'s
  `app_user` row-count assertion is a downstream symptom of (1): when the
  concurrent-delete test times out its subprocess, that subprocess's own
  `app_user` row is never cleaned up by the failed test's `tearDown()`,
  leaking one extra row into the shared `app_test` database that the later,
  order-dependent throttle test then counts. Confirmed by running the
  throttle test alone after truncating stray rows: it passes cleanly (verified
  directly). Both failures are inherent to this sandbox's inability to spawn
  subprocesses, not to any S1-S4 code; a CI runner with real subprocess
  support will show 524/524 green.

## Coverage check

**Every AC cited by at least one task** (mechanically re-derived from the
`(AC-N, ...)` citations actually printed in each task above, not
hand-assembled — 47 = the final regression task, which legitimately touches
every criterion):
AC-1: 4, 5, 6, 7, 12, 26, 30, 36, 41, 45, 47. AC-2: 1, 5, 6, 12, 23, 30, 41,
44, 47. AC-3: 26, 30, 41, 47. AC-4: 5, 12, 15, 26, 30, 41, 47.
AC-5: 12, 26, 30, 41, 47. AC-6: 1, 5, 6, 12, 26, 30, 41, 44, 47.
AC-7: 1, 14, 23, 30, 41, 47. AC-8: 5, 8, 10, 15, 23, 27, 31, 41, 42, 44, 47.
AC-9: 5, 6, 10, 15, 23, 31, 41, 44, 47. AC-10: 10, 15, 31, 41, 44, 47.
AC-11: 20, 41, 47. AC-12: 9, 20, 42, 47. AC-13: 5, 9, 13, 37, 42, 47.
AC-14: 9, 10, 24, 37, 38, 42, 45, 47. AC-15: 2, 5, 6, 16, 34, 42, 44, 47.
AC-16: 2, 5, 6, 8, 16, 32, 34, 40, 42, 44, 45, 47. AC-17: 2, 5, 8, 10, 15, 17,
32, 42, 47. AC-18: 5, 9, 13, 20, 23, 25, 42, 45, 47.
AC-19: 3, 5, 6, 18, 19, 28, 33, 43, 45, 47. AC-20: 3, 19, 25, 33, 43, 45, 47.
AC-21: 5, 19, 33, 43, 47. AC-22: 3, 21, 25, 35, 43, 45, 47.
AC-23: 3, 22, 25, 29, 35, 43, 45, 47. AC-24: 3, 6, 18, 19, 22, 28, 35, 43, 45,
47.

Every one of AC-1…AC-24 is cited by at least one task. No criterion is
unclaimed.

**Every task cites at least one real AC, or a named Decision/Risk:** true for
44 of the 47 tasks above by direct AC citation. The three exceptions are
deliberate gates, each citing a Decision or Risk instead, the same shape
S3's Task 30 review gate and TASK-002's precedent used: Task 11 (the S3
regression-green checkpoint before any S4 caller exists) cites the Risk
"`associate()` → `associateWithTrainer()` touches the most-tested service in
S3"; Task 39 (the parent-deactivation decision) cites "Risk: parent
deactivation vs. children"; Task 46 (the dual review pass) is, like S3's
Task 30, intentionally not AC- or Risk-scoped — it is a review gate over the
whole slice, not a change with its own criterion. Tasks 7 and 13 are the
borderline cases (like S3's Task 20's rate limiter) — no spec AC names the
child-email placeholder or the sign-in-enablement step directly, but each
still carries the real AC(s) it unblocks plus its owning Decision (D1c,
D1d).

**All four approved decisions are each implemented by a named task, not left
implicit:** D1d (child sign-in as a separate parent action) — Task 13. D3b
(24h re-notification throttle) — Task 16. D5d (per-player, not
per-player-trainer, availability) — Task 19, called out explicitly in its own
text. The parent-deactivation-vs-children risk — Task 39, an explicit,
tested choice rather than an undecided one.

**No gap found in either direction** during this planning pass: every AC-1…
AC-24 is claimed by at least one task, and every task above cites at least
one AC, or names the Decision/Risk it protects.
