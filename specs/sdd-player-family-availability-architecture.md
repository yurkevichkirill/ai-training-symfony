# Design: Player/Family Availability (Epic-01, slice S4)

> Answers *how*. The *what* and *why* live in `specs/sdd-player-family-availability-spec.md`
> (AC-1…AC-24); this file does not restate them. Governed task: TASK-004. Feature slug:
> `player-family-availability`.
>
> Builds on three shipped, frozen slices — `specs/auth-foundation-architecture.md` (S1),
> `specs/sdd-user-management-architecture.md` (S2), `specs/sdd-sharelink-invitations-architecture.md`
> (S3). Nothing they froze is edited here: `User`, `Profile`, `ProfileTrainer`,
> `TrainerPlayerAssociation`, `PlayerShareLink`, `AccountEvent`, `AccountInvitation` and
> `UserRole` keep every column and every case they already have. This slice adds three new
> tables, one nullable column on `profile_player`, six `AccountEventType` cases, two email
> templates, and one additive method signature on an existing S3 service.
>
> **Ground truth re-verified against source, not against the docs** (the spec's own rule,
> and S3's lesson). Two facts matter that the spec's ground-truth section predates or
> could not have known:
> 1. **`TrainerPlayerAssociation` already has `ended_at`**, added by S3's post-implementation
>    hardening pass (Task 36), with `UNIQUE (trainer_id, player_id) WHERE ended_at IS NULL`
>    replacing the old full unique index, an `end()` method, `PlayerShareLinkService::leave()`
>    as its only writer, and `findOneFor()`/`findRosterFor()`/`findActiveForPlayer()` all
>    filtering `ended_at IS NULL`. AC-9 ("the connection ends, the historical record is
>    preserved") therefore needs **no schema change at all** — the mechanism already exists
>    and already has the right constraint shape. This is the single largest saving in the
>    slice.
> 2. **`PlayerShareLinkService::associate()` already sends
>    `TEMPLATE_PLAYER_TRAINER_CONNECTED` to `$player->getEmail()`** on every genuinely new
>    association. For a child account that address is a non-deliverable placeholder (see
>    Decision **D1c**), so this slice must route that mail through a recipient resolver
>    rather than leave it addressed to a mailbox that does not exist.
>
> Also verified: `User` has no child marker and no `parent_user_id`; `UserRole` has exactly
> four cases; `ProfilePlayer` carries only `playerName`/`declaredAge`/`gender`; `Profile`
> enforces `UNIQUE (user_id, type)` over a JOINED hierarchy whose discriminator map holds
> `TRAINER` and `PLAYER`; `AccountEvent.subject_user_id` is NOT NULL / `ON DELETE RESTRICT`
> and `type` is a plain `varchar(64)`; `AccountInvitation(User $user, ?User $issuedBy, …)`
> targets an **existing** `User` row and is exactly the "set your own password" flow;
> `PhotoController::show()` currently allows only the photo's owner and a Super Admin;
> there is **no self-service account-deletion route** anywhere in the app (only
> `admin_users_delete`).

## Approach

Six shaping choices carry the slice.

1. **A child is a `User` with role `PLAYER`, and a new `child_account` row is what makes it
   a child.** Not a new `UserRole` case, not a new `Profile` subtype, not a column on
   `app_user`. The frozen `TrainerPlayerAssociation.player_id` FK, the frozen
   `#[IsGranted('ROLE_PLAYER')]` gates, and `PlayerShareLinkService::associate()`'s
   `UserRole::PLAYER` guard all already do the right thing for a child *because* a child is
   a player — AC-13's allow-list ("browse events, view content, view progress, submit
   feedback, edit own profile, view tokens") is satisfied by inheritance, not by
   re-implementation. What a child is *not* allowed to do (AC-14) is a short, explicit
   deny-list expressed as voter attributes plus service guards, not a role.

2. **The parent link is a relationship, so it lives in its own table, not in either
   party's entity.** `child_account (child_user_id UNIQUE, parent_user_id)` makes "a child
   has exactly one parent" and "a parent may have many children" (AC-6) database facts,
   and makes "is this account a child?" a single indexed lookup. `Profile` is explicitly
   *capability data for one role a user plays* (S1's frozen contract) — a link between two
   accounts is not capability, so it does not belong there; and putting `parent_user_id`
   on `app_user` would edit an entity three slices depend on to express something only
   players use.

3. **Everything a child does about trainers goes through a parent-actor.** The existing
   S3 method that creates an association is reused verbatim, widened only by an optional
   `?User $actor` argument, so AC-17's "exactly the same outcome as AC-8's flow — no
   second, parallel connection mechanism" is true by construction rather than by
   discipline: there is one `TrainerPlayerAssociation` writer in the codebase and all four
   S4 paths (create-child-with-trainers, add-by-ShareLink-code, add-from-My-Trainers,
   approve-a-blocked-request) call it. The `ended_at`/`leave()` mechanism S3 already
   shipped is reused the same way for AC-9.

4. **A blocked ShareLink click is a durable row, not just an email.** AC-15's message and
   AC-16's parent notification are two views of one record — `child_trainer_request` —
   which is also what AC-17's "Review Registration" page reads and what makes approval
   idempotent. A partial unique index `(child_user_id, trainer_id) WHERE resolved_at IS NULL`
   means a child clicking the same link ten times produces one pending request; the
   *blocking message* still renders on every click (AC-15 is unconditional), and the
   *email* is re-sent only when the row is new or its `last_notified_at` is over 24h old
   (see Decision **D3b** — the one deliberate, declared narrowing in this design).

5. **Availability is stored as ranges that exist, never as days that are marked empty.**
   `player_availability_slot` holds one row per (player, weekday, start, end). "Not
   Available" is the *absence* of rows for that weekday, so AC-24's "a day with no time
   range is Not Available, never unknown" is not a rule the filter has to remember — it is
   what an `INNER JOIN` does. Saving a week is one `DELETE` scoped by `player_id` plus the
   new rows in one transaction, so AC-20's isolation between siblings is a `WHERE` clause,
   not a merge algorithm.

6. **Contexts are returned as a list of per-player objects, never a merged collection.**
   `PlayerContextProvider` returns `list<PlayerContext>` where each context owns exactly
   one player and that player's own trainers. AC-11's "never combined into one
   undifferentiated list" and AC-12's "only that child's own connections" are then the same
   guarantee at the type level; a caller that wanted the merged list would have to build it
   itself. This is the data shape the deferred context-switcher UI will read.

## Components

### Entities and schema

**`App\Entity\ChildAccount`** → `child_account`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUIDv7, generated in the constructor (S1 convention) |
| `child_user_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | **UNIQUE** |
| `parent_user_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | AC-2's "linked to the parent's own account" |
| `sign_in_enabled_at` | `timestamptz` NULL | non-null once the child has real credentials (D1d) |
| `created_at` | `timestamptz` NOT NULL | |

Constraints: `UNIQUE (child_user_id)` — one parent per child, and the fact that makes
"is this a child?" a single-row lookup; hand-written
`CHECK (child_user_id <> parent_user_id)` so an account cannot parent itself. Index
`(parent_user_id, created_at)` — AC-7's family list and AC-11's context set in one query.
**No unique constraint on `parent_user_id`** — that absence is AC-6.

The row's *existence* is FR-024's "Child vs Self marker". Its *deletion* is the entire
age-18 transition the spec defers: the child keeps its `User`, its `ProfilePlayer`, its
associations and its availability, and simply stops being someone's child. Nothing else
in this design encodes childhood, which is what keeps that future change to one row.

**`App\Entity\ChildTrainerRequest`** → `child_trainer_request`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `child_user_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | AC-15 |
| `trainer_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | AC-16 names this trainer |
| `parent_user_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | who was actually notified, snapshotted at request time |
| `share_link_id` | `uuid` NULL FK `player_share_link` ON DELETE SET NULL | which link was clicked; nullable for the same reason S3 made the association's nullable |
| `created_at` | `timestamptz` NOT NULL | |
| `last_notified_at` | `timestamptz` NOT NULL | D3b's re-notification clock |
| `resolved_at` | `timestamptz` NULL | null ⇔ pending |
| `resolution` | `varchar(16)` NULL | `App\Enum\ChildTrainerRequestResolution`: `APPROVED`, `DISMISSED` |
| `resolved_by_user_id` | `uuid` NULL FK `app_user` ON DELETE SET NULL | the parent who acted (AC-17) |

Constraints: partial unique index
`CREATE UNIQUE INDEX uniq_child_trainer_request_pending ON child_trainer_request (child_user_id, trainer_id) WHERE resolved_at IS NULL`
— the same technique and the same **pre-parenthesized** `options: ['where' => '(resolved_at IS NULL)']`
declaration S3's Risks section proved necessary to stop DBAL re-diffing the index forever.
Hand-written `CHECK ((resolved_at IS NULL) = (resolution IS NULL))` so a half-resolved row
is unstorable. Index `(parent_user_id, resolved_at, created_at)` for the parent's pending
list.

**`App\Entity\PlayerAvailabilitySlot`** → `player_availability_slot`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `player_id` | `uuid` NOT NULL FK `app_user` ON DELETE CASCADE | adult or child alike (AC-23) |
| `day_of_week` | `smallint` NOT NULL | ISO-8601, Monday = 1 … Sunday = 7 (`date('N')`) |
| `starts_at_minute` | `smallint` NOT NULL | minutes from local midnight, 0…1439 |
| `ends_at_minute` | `smallint` NOT NULL | 1…1440; 1440 is "midnight at the end of the day" |
| `created_at` | `timestamptz` NOT NULL | |

Hand-written checks: `CHECK (day_of_week BETWEEN 1 AND 7)`,
`CHECK (starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)`.
Index `(player_id, day_of_week, starts_at_minute)` — the per-player grid read and the
AC-22 summary. Index `(day_of_week, starts_at_minute, ends_at_minute)` — AC-23's
roster filter, which joins from the trainer's active associations into this table.
**No `is_unavailable` flag and no row-per-day placeholder**: absence *is* "Not Available"
(Approach 5). **No time zone column** — the spec puts time zones out of scope; every value
is facility-local wall-clock time, and the absence of a column is what keeps that
assumption visible instead of silently defaulted (see Risks).

**`App\Entity\ProfilePlayer`** gains exactly one nullable column:
`school varchar(160) NULL` (FR-024's optional school). Additive, no default, no backfill —
nothing that already shipped changes meaning. It goes here, not on `child_account`,
because it is player identity data of the same kind as `playerName`/`declaredAge`/`gender`,
and putting it on the relationship table would mean moving it the day an adult player wants
to record a school. The child's photo is `app_user.photo_key`, reusing S2's `FileStorage`
mechanism unchanged.

**`App\Enum\AccountEventType`** gains `CHILD_ACCOUNT_CREATED`, `CHILD_TRAINER_CONNECTED`,
`CHILD_TRAINER_DISCONNECTED`, `CHILD_SHARE_LINK_BLOCKED`, `CHILD_SIGN_IN_ENABLED`,
`PLAYER_AVAILABILITY_UPDATED`. `account_event.type` is a plain `varchar(64)`, so no
migration is needed for these. Every one of them is a *parent acting on a child*, which is
precisely the actor/subject shape S2 built `AccountEvent` for ("one user acting on
another") — so this slice reuses that log rather than inventing a parallel one. Actor =
the parent, subject = the child; `CHILD_SHARE_LINK_BLOCKED`'s actor and subject are both
the child (a self-action, the shape S3 used for `PLAYER_TRAINER_ASSOCIATED`).

**Migration.** One migration, `Version…PlayerFamilyAvailability`: create `child_account`,
`child_trainer_request`, `player_availability_slot`; `ALTER TABLE profile_player ADD school`;
then the hand-written SQL DBAL does not diff (four CHECK constraints and the one partial
unique index). Down-migration drops in reverse and drops the column. **No data backfill**:
every table is new, the one new column is nullable, and no existing row's meaning changes.

### Controllers → services

Every route below is authenticated. **`security.yaml` gains no new `PUBLIC_ACCESS` line** —
the `^/` catch-all covers all of them, and S1's `RouterSweepTest` (which reads that file)
therefore keeps passing untouched. The one public route this slice modifies
(`/join/{code}`) keeps its existing allow-list entry.

| Route | Controller | Delegates to |
|---|---|---|
| `GET /family` (`app_family_index`) | `Family\ChildController::index` | `ChildAccountRepository::findChildrenOf`, `TrainerPlayerAssociationRepository::findActiveForPlayers` (AC-7) |
| `GET\|POST /family/children/new` (`app_family_child_new`) | `Family\ChildController::create` | `ChildAccountService::createChild` (AC-1…AC-6) |
| `POST /family/children/{id}/photo` (`app_family_child_photo`) | `Family\ChildController::uploadPhoto` | `ProfileService::uploadPhoto` (S2, reused unchanged) |
| `GET\|POST /family/children/{id}/sign-in` (`app_family_child_sign_in`) | `Family\ChildController::enableSignIn` | `ChildAccountService::enableSignIn` (D1d) |
| `GET\|POST /family/children/{id}/trainers/add` (`app_family_child_trainer_add`) | `Family\ChildTrainerController::add` | `PlayerShareLinkResolver::resolve` (code path) then `ChildTrainerService::connect` (AC-8) |
| `GET /family/children/{id}/trainers/{trainerId}/remove` (`…_remove_confirm`) | `Family\ChildTrainerController::confirmRemove` | read-only; renders the named confirmation + RSVP warning (AC-9) |
| `POST /family/children/{id}/trainers/{trainerId}/remove` (`…_remove`) | `Family\ChildTrainerController::remove` | `ChildTrainerService::disconnect` (AC-9, AC-10) |
| `GET /family/requests/{id}` (`app_family_request_review`) | `Family\ChildTrainerRequestController::review` | `ChildTrainerRequestRepository::findPendingForParent` (AC-16's CTA target) |
| `POST /family/requests/{id}/approve` (`…_approve`) | `Family\ChildTrainerRequestController::approve` | `ChildTrainerService::approveRequest` → `ChildTrainerService::connect` (AC-17) |
| `POST /family/requests/{id}/dismiss` (`…_dismiss`) | `Family\ChildTrainerRequestController::dismiss` | `ChildTrainerService::dismissRequest` |
| `GET\|POST /player/availability` (`app_player_availability`) | `Player\AvailabilityController::edit` | `PlayerContextProvider::contextsFor`, `AvailabilityService::replaceWeek` (AC-19…AC-21) |

Modified existing controllers:

- **`PlayerShareLinkController::follow()`** — after the resolver and before
  `denyAccessUnlessGranted(FOLLOW_PLAYER_SHARE_LINK)`, a child branch:
  `ChildAccountResolver::childAccountOf($user)` non-null ⇒ call
  `ChildTrainerService::recordBlockedClick($childAccount, $link)` and render
  `share_link/child_blocked.html.twig` ("Ask your parent to register you with this
  trainer") with HTTP 200. **No association call is reached on this branch, ever, and no
  condition — including an already-existing active association with that same trainer —
  short-circuits it** (AC-15, AC-16, and the spec's fourth edge-case row read literally).
  The `ShareLinkVoter` is deliberately *not* the place for this: a voter can only refuse
  with a 403, and AC-15 requires an informative page plus a side effect.
- **`Trainer\PlayerRosterController::index()`** — takes an optional
  `AvailabilityFilterFormType` (day + time). Unfiltered it calls the existing
  `findRosterFor($trainer)`; filtered it calls the new
  `findRosterAvailableAt($trainer, $dayOfWeek, $minute)` (AC-23). Either way it passes the
  roster's player ids to `PlayerAvailabilitySlotRepository::findForPlayers()` — one query,
  no N+1 — and renders `AvailabilitySummaryFormatter::summarize()` on each card (AC-22).
- **`PhotoController::show()`** — the owner-or-Super-Admin rule gains one clause: a parent
  may read their own child's photo (`FamilyVoter::MANAGE_CHILD`). Without it AC-1's
  optional child photo would be uploadable and never viewable.
- **`Player\TrainerRosterController::leave()`** — gains
  `denyAccessUnlessGranted(PlayerActionVoter::MANAGE_OWN_TRAINER_CONNECTIONS)` (AC-14).
  `index()` stays open to children: viewing their own trainers is on AC-13's allow-list.

### Services

**`ChildAccountResolver`** — `childAccountOf(User $user): ?ChildAccount`,
`isChild(User $user): bool`. One `findOneBy(['childUser' => $user])` against the
`UNIQUE (child_user_id)` index, served from Doctrine's identity map for the rest of the
request. This is the single answer to "is the signed-in account a child?" that every
voter, service guard and mail-recipient decision in the slice calls; nothing re-derives it.

**`ChildAccountService`**
- `createChild(User $parent, CreateChildRequest $request): ChildAccount` — AC-1…AC-6.
  Two phases in `TrainerOnboardingService`'s established shape, because
  `UserAccountService::create()` commits and closes its own EntityManager:
  1. `UserAccountService::create($placeholderEmail, $unusableSecret, UserRole::PLAYER)`,
     where `$placeholderEmail` comes from `ChildEmailFactory` (D1c) and `$unusableSecret`
     is `base64_encode(random_bytes(32))` that is hashed and immediately discarded — no
     one, including the parent, ever holds a credential for this account.
  2. A second transaction on a fresh manager: `ProfilePlayer` (name, age, gender,
     school), `ChildAccount`, `User::setName()`/`photoKey` if a photo was uploaded, and
     one `TrainerPlayerAssociation` per selected trainer via
     `ChildTrainerService::connect()` (AC-4). `catch (\Throwable)` → `resetManager()`,
     compensating `DELETE FROM app_user WHERE id = :id`, log at `critical` on a failed
     compensation, rethrow — verbatim the discipline `TrainerOnboardingService` and
     `PlayerRegistrationService` already carry.
  3. Post-commit: `AccountEventRecorder::record(CHILD_ACCOUNT_CREATED)` (actor = parent,
     subject = child, context `{trainerCount}`). **No email** — nothing was sent anywhere
     and the parent is looking at the result.
- `enableSignIn(User $parent, ChildAccount $child, string $email, ?\DateTimeImmutable $now): void`
  — D1d. Replaces the placeholder email with a real, platform-unique one
  (`EmailAlreadyInUseException` surfaces as a field error; this form is authenticated and
  parent-only, so S3's enumeration concern does not apply), issues an `AccountInvitation`
  through `SelectorVerifierTokenFactory` exactly as `TrainerOnboardingService::createTrainer()`
  does, sets `sign_in_enabled_at`, dispatches `TEMPLATE_CHILD_SIGN_IN_INVITATION`, records
  `CHILD_SIGN_IN_ENABLED`. The child then sets their own password through S2's already-shipped
  `/invitations/{token}` flow. No new credential machinery exists in this slice.
- `findChildrenOf(User $parent): list<ChildAccount>` delegates to the repository.
- `findSimilar(User $parent, string $name, int $age): list<ChildAccount>` — BR-019's
  duplicate check, used for the soft warning (never to block; see Forms).

**`ChildTrainerService`**
- `connect(User $parent, ChildAccount $child, User $trainer, ?PlayerShareLink $link): TrainerPlayerAssociation`
  — AC-4, AC-8, AC-17, and the single writer for all of them. Guards: the parent must own
  this child (`FamilyVoter` at the edge *and* a service re-check, per S3's Decision Q4
  defence-in-depth rule); the trainer must be role `TRAINER` and `ACTIVE`. Then it calls
  `PlayerShareLinkService::associateWithTrainer($childUser, $trainer, $link, actor: $parent)`
  — the widened S3 method (D2b) — which supplies the idempotent insert, the partial-unique-index
  race recovery, the `usage_count` increment when a link was used, and the
  `PLAYER_TRAINER_ASSOCIATED` event. Post-commit this method adds
  `CHILD_TRAINER_CONNECTED` (actor = parent, subject = child, context `{trainerId}`).
  Re-confirming an existing active pairing returns the existing row untouched: AC-8's
  no-op and the double-submit edge case are the same database fact.
- `disconnect(User $parent, ChildAccount $child, User $trainer): void` — AC-9, AC-10.
  Delegates to `PlayerShareLinkService::endAssociation()` (D2c: a conditional
  `UPDATE … SET ended_at = :now WHERE trainer_id = :t AND player_id = :p AND ended_at IS NULL`
  whose affected-row count is the answer) — `1` ⇒ success plus a
  `CHILD_TRAINER_DISCONNECTED` event; `0` ⇒ `NoActiveTrainerAssociationException`, which
  the controller renders as "already removed", not an error. Because the statement names
  one `(trainer, player)` pair, AC-10's "changes nothing about any other connection" is a
  `WHERE` clause. Nothing deletes the row and nothing touches
  `player_availability_slot` — which is exactly the spec's "availability is preserved as
  historical data" edge case.
- `recordBlockedClick(ChildAccount $child, PlayerShareLink $link): ChildTrainerRequest`
  — AC-15, AC-16. Unconditional: it is called before any association check and regardless
  of whether one exists. One transaction: find the pending request for
  `(child, trainer)` or insert one, catching `UniqueConstraintViolationException` on the
  partial index and re-reading the winner against a freshly reset manager (S3's
  closed-EntityManager discipline, unchanged). Post-commit: record
  `CHILD_SHARE_LINK_BLOCKED`, then dispatch `TEMPLATE_CHILD_SHARE_LINK_REQUEST` to
  `NotificationAddressResolver::forPlayer($childUser)` — the parent's address — if the row
  was newly created or `last_notified_at` is older than 24 hours (D3b), updating
  `last_notified_at` in the same statement.
- `approveRequest(User $parent, ChildTrainerRequest $request): TrainerPlayerAssociation`
  — AC-17. Marks the request `APPROVED`/`resolved_by` and calls `connect()` with the
  request's own `share_link_id`. There is no second connection path; a request whose
  pairing already exists resolves against the existing association.
- `dismissRequest(User $parent, ChildTrainerRequest $request): void` — marks `DISMISSED`.
  Both resolvers refuse an already-resolved request with
  `ChildTrainerRequestAlreadyResolvedException` rather than silently re-resolving, the
  project's established convention for an invalid state transition.

**`PlayerShareLinkService`** (S3, extended — no behavior change to existing callers)
- `associateWithTrainer(User $player, User $trainer, ?PlayerShareLink $link, ?User $actor = null): TrainerPlayerAssociation`
  — the existing `associate()` body, generalized: the trainer comes in directly, the link
  is optional (`usage_count` increments only when one was used, preserving S3's "count uses
  that created something"), and `$actor` defaults to `$player`. The existing
  `associate(User $player, PlayerShareLink $link)` becomes a one-line wrapper, so every S3
  call site and every S3 test is untouched.
- **New guard inside it:** if `$actor === $player` and
  `ChildAccountResolver::isChild($player)`, throw `ChildActionNotPermittedException` —
  a child cannot connect itself to a trainer through *any* route, forged or otherwise
  (AC-14, and the spec's fifth edge-case row).
- `leave()` gains the same guard, and `endAssociation()` (D2c) is added beside it and used
  by both `leave()` and `ChildTrainerService::disconnect()`.

**`AvailabilityService`**
- `replaceWeek(User $player, WeeklyAvailability $week, User $actor): void` — AC-19, AC-20.
  One transaction: `DELETE FROM player_availability_slot WHERE player_id = :player`, then
  insert the normalized rows. Scoped by `player_id`, so a save for one child cannot read
  or write another player's rows — AC-20's isolation is the `WHERE` clause, not a diffing
  algorithm. Post-commit `PLAYER_AVAILABILITY_UPDATED` (actor = the parent or the player
  themselves, subject = the player).
- `weekFor(User $player): WeeklyAvailability` — the grid read.
- `App\Availability\WeeklyAvailability` / `TimeRange` — plain immutable value objects,
  no Doctrine. `WeeklyAvailability::normalized()` sorts each day's ranges and merges
  overlapping or touching ones, so two submissions describing the same availability
  produce byte-identical rows and AC-24's evaluation is never ambiguous.

**`PlayerContextProvider`** — `contextsFor(User $user): list<PlayerContext>`, where
`PlayerContext` is a readonly DTO `{User player, string label, bool isSelf, list<TrainerPlayerAssociation> trainers}`.
For an adult parent: the self context first (label "Me"), then one per child, each with
that child's own active associations (AC-11). For a child: a single self context (AC-12,
AC-18) — the provider reads `ChildAccountResolver` first and never widens. One
`findActiveForPlayers(list<User>)` query with the trainer eagerly joined feeds all of
them, so the family page is O(1) queries, not O(children).

**`NotificationAddressResolver`** — `forPlayer(User $player): string`. Returns the parent's
address when the player is a child, the player's own otherwise. **All transactional mail
about a child routes to the parent**, always, regardless of whether the child has real
sign-in credentials (BR-011: the parent owns the family's contact information). This is
what G-07's "shares the parent's contact info" means concretely, and it is also what stops
`PLAYER_TRAINER_CONNECTED` from being queued to a `.invalid` placeholder address.

**`ChildEmailFactory`** — `forChild(Uuid $childUserId): string`, returning
`child_<uuid>@children.invalid` (D1c). Derived from the account's own immutable id, so it
cannot collide; lowercase hex plus a lowercase domain, so S1's
`CHECK (email = lower(email))` holds; RFC 2606 `.invalid`, so it can never be delivered to
or typed at a sign-in form by accident. `isPlaceholder(string $email): bool` is what the
UI uses to decide whether to offer "Enable sign-in".

**`AvailabilitySummaryFormatter`** — `summarize(list<PlayerAvailabilitySlot> $slots, int $maxDays = 3): string`,
producing AC-22's "Mon 5-8pm, Wed 6-9pm" with a "+2 more" tail. A service, not a Twig
filter, so the same string is available to a future API/export without touching templates.

### Forms and validation

- **`ChildProfileFormType`** over a `CreateChildRequest` DTO — `childName` (`NotBlank`,
  `Length(max: 160)`), `age` (`NotNull`, **`Range(min: 1, max: 18)`** — AC-5, BR-019),
  `gender` (`NotNull`, `Choice` over `PlayerGender`), `school` (optional,
  `Length(max: 160)`), `photo` (optional, S2's `Image` constraint set reused),
  `trainerIds` (`ChoiceType` multiple, choices built from the parent's own active
  associations and **re-validated server-side** against that same set on submit — a forged
  trainer id is refused, not silently connected). AC-3's three shapes are one form
  rendered three ways: zero trainers ⇒ the field is not added at all; exactly one ⇒
  rendered as a single Yes/No `CheckboxType` labelled "Will [Child] also train with
  [Trainer]?"; more than one ⇒ the multi-checkbox list.
- **Duplicate soft-warning** (BR-019, edge-case row 6): `ChildAccountService::findSimilar()`
  runs on submit; a hit re-renders the form with a warning and a hidden
  `duplicateAcknowledged` field, and the next submit saves. It is never a validation error
  — the parent can always proceed, which is what "does not block saving" requires.
- **`ChildTrainerAddFormType`** over `AddChildTrainerRequest` — `shareLinkCode` (optional
  text) and `trainerId` (optional choice from "My Trainers"), with an
  `Assert\Callback` requiring **exactly one** to be present. An unknown or inactive code
  raises `ShareLinkUnavailableException` from the existing resolver and becomes a field
  error.
- **`AvailabilityWeekFormType`** — a `CollectionType` of seven `DayAvailabilityFormType`,
  each a `CollectionType` of `TimeRangeFormType` (`start`/`end` as `TimeType` widgets)
  with `allow_add`/`allow_delete`, plus a "Not Available" checkbox that clears the day
  client-side. Canonical storage stays "zero rows"; the checkbox is a UI affordance, never
  a stored value. Constraints: `Count(max: 6)` per day, `Range(0, 1440)` per endpoint,
  and a `Callback` asserting `start < end`. A `MinutesFromMidnightTransformer` converts
  between the widget's `\DateTimeImmutable` and the stored `smallint`.
- **`AvailabilityFilterFormType`** — `dayOfWeek` (`ChoiceType` 1…7) + `time`
  (`TimeType`), both optional; submitting neither means an unfiltered roster.

Note on AC-5's 1–18 rule: it is a **validation-layer** rule, not a database CHECK, because
`profile_player.declared_age` is shared with adult players registered through S3 (whose
form permits 1–120). Putting a CHECK on that column would break a shipped path. See Risks.

### Authorization

Three voters, all reading `User::role`, `User::status` and `ChildAccount` — none reads a
`Profile`, so S1's frozen "authorization never reads a Profile" invariant holds.

| Voter | Attribute | Subject | Granted when |
|---|---|---|---|
| `FamilyVoter` | `MANAGE_FAMILY` | none | active `PLAYER` who is **not** a child |
| `FamilyVoter` | `MANAGE_CHILD` | `User` (the child) | a `ChildAccount(child, parent = token user)` exists, both accounts active |
| `PlayerActionVoter` | `MANAGE_OWN_TRAINER_CONNECTIONS` | none | active player who is **not** a child (AC-14) |
| `PlayerActionVoter` | `DELETE_OWN_ACCOUNT` | none | same |
| `PlayerActionVoter` | `MANAGE_PAYMENT_METHOD` | none | same |
| `PlayerActionVoter` | `COMPLETE_PURCHASE` | none | same |
| `AvailabilityVoter` | `EDIT_AVAILABILITY` | `User` (the player) | subject is the token user, or the token user is the subject's parent |
| `AvailabilityVoter` | `VIEW_AVAILABILITY` | `User` (the player) | the above, **or** the token user is a trainer with an active association to the subject (AC-22, AC-23) |

`MANAGE_PAYMENT_METHOD` and `COMPLETE_PURCHASE` have **no caller in this slice** — no
payment route exists (Epic-05). They ship anyway, with unit tests, so the future payments
slice fails closed: the attribute already exists and already denies children, and adding
`denyAccessUnlessGranted()` is the only thing that slice has to remember. `DELETE_OWN_ACCOUNT`
likewise has no route today (only `admin_users_delete`); alongside the attribute,
`AccountLifecycleService::delete()` gains a guard refusing a **child as actor**, which keeps
a Super Admin's ability to delete a child account intact.

Defence in depth, per S3's Decision Q4: every deny-list rule exists as a voter *and* as a
service guard. The voter gives the clean 403 at the HTTP edge; the service guard is what
survives a console command, a future API controller, or a forged request that never passes
through the annotated action — which is precisely what AC-14's "server-side, not merely
hidden from the UI" and the spec's forged-request edge case demand.

### Mail

Two new templates, both through S1's existing `SendEmailMessage` → `SendEmailMessageHandler`
→ `async` Doctrine transport, dispatched **after** the surrounding transaction commits.
No new mailer or Messenger configuration; the message's `array<string, scalar>` context is
unchanged.

| Template | Constant | Sent to | For |
|---|---|---|---|
| `emails/child_share_link_request.html.twig` | `TEMPLATE_CHILD_SHARE_LINK_REQUEST` | the **parent** | AC-16: names the child and the trainer, one "Review Registration" button linking to `app_family_request_review` |
| `emails/child_sign_in_invitation.html.twig` | `TEMPLATE_CHILD_SIGN_IN_INVITATION` | the child's new address | D1d: S2's set-your-password invitation link, worded for a child rather than a trainer |

This is Q-01.04's answer for this slice: the notification **reuses the existing template
family and mechanism** (`templates/emails/*.html.twig`, one new `TEMPLATE_*` constant, one
new `buildContext()` branch) but **needs its own file and copy** — no existing template is
close enough. `player_trainer_connected` is a confirmation of a completed connection with
no call to action; `duplicate_registration_attempt` is the right *shape* (notifying account
A about something someone else attempted) but its copy is wrong and it deliberately has no
action button, because there is nothing safe for its recipient to do. AC-16 needs both a
named CTA and two named people, so it is a second file.

The email's CTA links to an **authenticated** page, not a tokenised one — the parent signs
in and lands on the review screen. AC-16 says the action is taken "from their own account",
and this avoids minting a third single-use-token family for an action already reachable
from `/family`.

### Layer placement

| Concern | Layer | Class |
|---|---|---|
| Family list, child creation, photo, sign-in enablement | Controller | `Family\ChildController` |
| Child↔trainer add/remove screens | Controller | `Family\ChildTrainerController` |
| Blocked-request review/approve/dismiss | Controller | `Family\ChildTrainerRequestController` |
| Availability grid | Controller | `Player\AvailabilityController` |
| ShareLink child branch | Controller | `PlayerShareLinkController` (extended) |
| Roster summary + availability filter | Controller | `Trainer\PlayerRosterController` (extended) |
| Child account lifecycle | Service | `ChildAccountService` |
| Child↔trainer connect/disconnect/blocked-request workflow | Service | `ChildTrainerService` |
| The one association writer (insert, end, idempotency, counters) | Service | `PlayerShareLinkService` (S3, extended additively) |
| Availability read/write | Service | `AvailabilityService` |
| "Is this a child, and whose?" | Service | `ChildAccountResolver` |
| Context-selector data shape | Service | `PlayerContextProvider` |
| Mail recipient for anything about a child | Service | `NotificationAddressResolver` |
| Placeholder-email construction | Service | `ChildEmailFactory` |
| AC-22 summary string | Service | `AvailabilitySummaryFormatter` |
| Account creation, unique-email mapping | Service | `UserAccountService` (S1, unchanged) |
| Password-set invitation | Service | `AccountInvitationService` + `SelectorVerifierTokenFactory` (S2, unchanged) |
| Photo storage | Service | `ProfileService` / `FileStorage` (S2, unchanged) |
| Audit write | Service | `AccountEventRecorder` (S2, unchanged) |
| Parent/child authorization | Security | `FamilyVoter` |
| Child deny-list | Security | `PlayerActionVoter` |
| Availability access | Security | `AvailabilityVoter` |
| Queries and persistence | Repository | `ChildAccountRepository`, `ChildTrainerRequestRepository`, `PlayerAvailabilitySlotRepository`, `TrainerPlayerAssociationRepository` (extended) |

Transaction, controller, service and repository boundaries are unchanged from S1's rules:
one transaction per service method, controllers never `flush()`, services never return a
`Response`, repositories never authorize.

### Tests this slice must produce

Functional — **family**: create a child with zero/one/many parent trainers and assert the
three AC-3 form shapes; "Yes"/"No" and checklist selections produce exactly the selected
associations (AC-4); age 0, 19 and a missing gender are refused and create **nothing** —
no `User`, no `ProfilePlayer`, no `ChildAccount` (AC-5); two children under one parent
(AC-6); the family list shows each child's trainers with connection dates (AC-7); adding a
trainer by code and from My Trainers produce identical rows, and a repeat of either is a
no-op (AC-8); removing shows a confirmation naming both parties and warning about RSVPs,
then ends exactly one association while a sibling's and the same child's other trainers
are untouched (AC-9, AC-10); a parent cannot manage another parent's child (403 on every
`/family/children/{id}/…` route).

Functional — **child session**: a signed-in child sees only its own trainers from
`PlayerContextProvider`, never the parent's or a sibling's (AC-12, AC-18); the child can
open the dashboard, its own profile form, its own photo upload and its own trainer list
(AC-13); the child is refused — with a 403, not a redirect — on leave-trainer, on any
`/family` route, and on a direct forged `POST` to the trainer add/remove routes (AC-14 and
the forged-request edge case); a child following any ShareLink gets the blocking page, no
association row appears, and exactly one parent email is queued (AC-15, AC-16); **a child
following the ShareLink of a trainer it is already connected to gets the same blocking page
and the same parent email** (edge-case row 4, no carve-out); the parent's Review
Registration approves and the child ends with exactly one association, identical in shape
to an AC-8 connection (AC-17); approving twice is refused as already-resolved, not
duplicated.

Functional — **availability**: set ranges and "Not Available" days and read them back
(AC-19); a parent switches between self and two children and each save leaves the other two
untouched (AC-20); the post-save confirmation names trainers (AC-21); a trainer's roster
card shows the summary string (AC-22) and the day/time filter returns only matching players,
adult and child alike (AC-23); a player with no rows for the filtered day never matches
(AC-24); a parent with no children sees no switcher.

Repository integration, against the real database: the partial unique index
`(child_user_id, trainer_id) WHERE resolved_at IS NULL` admits a second request only after
the first resolves; `UNIQUE (child_user_id)` refuses a second parent for one child; the
`child_user_id <> parent_user_id`, `day_of_week`, `starts < ends` and
`resolved_at`/`resolution` CHECK constraints refuse bad values; two concurrent
`disconnect()` calls yield affected-row counts of exactly 1 and 0 (edge-case row 3); two
concurrent `connect()` calls for the same pairing yield one row (edge-case row 2);
`doctrine:schema:update --dump-sql` reports nothing to update on a **second** run — S3's
partial-index normalization trap.

Unit: `WeeklyAvailability::normalized()` merging, sorting and boundary cases (touching
ranges, 0 and 1440, an empty day); `AvailabilitySummaryFormatter` output and truncation;
`ChildEmailFactory` collision-freedom, lowercase invariant and `isPlaceholder()`;
`NotificationAddressResolver` for adult, placeholder-email child and sign-in-enabled child;
the truth tables of all three voters, parameterized over every role × child/adult ×
active/deactivated combination.

Regression: S1's AC-1…AC-25, S2's AC-1…AC-24 and S3's AC-1…AC-21 must still hold — in
particular S3's ShareLink tests, which the `associate()` → `associateWithTrainer()`
extraction must leave byte-for-byte passing.

## Stack

| Choice | Version | Over the alternative, because |
|---|---|---|
| PostgreSQL partial unique index, declared pre-parenthesized (`'(resolved_at IS NULL)'`) | built-in | Over an application "one pending request per pair" check: only the index survives a double click from two devices. The parenthesized declaration is not cosmetic — S3's Risks section documents that DBAL's literal string comparison against `pg_get_expr`'s canonical form otherwise re-diffs the index on every `schema:update`. |
| `smallint` minutes-from-midnight for availability endpoints | — | Over `TIME WITHOUT TIME ZONE`: an integer makes "ends after starts" a plain CHECK, makes the AC-23 overlap query pure integer comparison, represents 24:00 (end-of-day) which `time` cannot, and — decisively for a slice that puts time zones out of scope — never round-trips through a `\DateTime` that carries a date and a zone nobody meant. |
| RFC 2606 `.invalid` TLD for placeholder child emails | — | Over a real-looking domain or a plus-address on the parent's mailbox: `.invalid` is reserved never to resolve, so a placeholder can never be delivered to by accident, and it is instantly recognizable in the Users directory. A parent plus-address would be deliverable, would leak the parent's address into a login field, and would break the day the parent changes providers. |
| No new Composer package | — | Every mechanism this slice needs exists: `UserAccountService` for accounts, `AccountInvitation` + `SelectorVerifierTokenFactory` for the child's password-set link, `FileStorage` for photos, `SendEmailMessage` for queued mail, `AccountEventRecorder` for audit, the partial-unique-index technique for both new idempotency rules. |

Not added: a calendar/recurrence library (a weekly grid with no dates, no exceptions and no
time zones is seven integers-and-ranges, not an RRULE problem); `symfony/lock` (every race
here is settled by a unique index or a conditional `UPDATE`); a new rate limiter (D3b's
re-notification window is a column, not a cache pool — which also avoids S1's per-node
limiter caveat).

## Decisions

| Decision | Chosen | Rejected | Because |
|---|---|---|---|
| **D1. (G-07) What a child account *is*** | Its **own `User` row**, `role = PLAYER`, `status = ACTIVE`, its own `ProfilePlayer`, plus a `child_account` row naming the parent | (a) no `User` row at all — a `ProfileChild` hanging off the parent; (b) a new `UserRole::CHILD` case; (c) a nullable `parent_user_id` on `app_user` | (a) is unbuildable without editing a frozen entity: `TrainerPlayerAssociation.player_id` is a NOT NULL FK to `app_user`, so a child with no `User` row cannot be connected to a trainer at all — and AC-4/AC-8/AC-9 are entirely about that. (b) would silently lock children out of AC-13's allow-list: every `#[IsGranted('ROLE_PLAYER')]` route, `PlayerShareLinkService::associate()`'s role guard and `ShareLinkVoter` would stop granting, so a "child" role means re-auditing every authorization rule S1–S3 shipped to re-grant what a child *is* allowed to do — the wrong direction, since AC-14's deny-list is five items and AC-13's allow-list is open-ended. (c) edits an entity three slices depend on to express something only players have, and puts a family relationship in the authentication table. The chosen shape leaves S1/S2/S3 byte-identical and confines "childhood" to one row whose deletion is the whole age-18 transition. |
| **D1b. Where the parent link lives** | A new `child_account` table, `UNIQUE (child_user_id)` | A `ProfileChild` subtype of the frozen `Profile` hierarchy | `Profile`'s own contract is "capability data for one role a User plays, never authority". A link between two accounts is neither capability nor role data. Worse, `UNIQUE (user_id, type)` plus the child needing a `ProfilePlayer` anyway (that is what trainer rosters and AC-1's name/age/gender read) would force the child's identity fields to be duplicated across two profile rows or split between them. A relationship gets a relationship table — which also gives `UNIQUE (child_user_id)` as the "one parent per child" guarantee, unexpressible in the profile hierarchy. |
| **D1c. The child's login identifier** | A derived, non-deliverable placeholder `child_<uuid>@children.invalid`, replaced by a real address only when the parent enables sign-in | (a) reuse the parent's address — impossible, `UNIQUE (email)`; (b) a parent plus-address (`parent+child1@…`); (c) make `app_user.email` nullable | (a) is refused by the schema. (c) edits a frozen S1 column that is the login identifier itself and would make `getUserIdentifier()` nullable — the single change most likely to break authentication. (b) is deliberately not the default: it is deliverable (so a child's blocked-ShareLink mail could land twice in the parent's inbox), it breaks when the parent changes providers, and it puts the parent's real address into a child's login field. The placeholder is derived from the account's own immutable id, so it cannot collide; it satisfies NOT NULL, UNIQUE and S1's `CHECK (email = lower(email))`; and `.invalid` guarantees no mail is ever delivered to it. The parent's address is still where everything about the child is *sent* — that is `NotificationAddressResolver`, D3c. |
| **D1d. How a child obtains credentials** | A separate, optional parent action ("Enable sign-in for [Child]") that sets a real email and issues S2's existing `AccountInvitation`, letting the child set their own password | (a) the parent chooses the child's password on the creation form; (b) every child gets credentials at creation | AC-1's form is name/age/gender (+ optional school/photo) — it does not ask for an email or a password, and a child profile must be creatable for a five-year-old who will never sign in. So credentials cannot be mandatory at creation. (a) means the parent knows the child's password forever, which is a shared-credential design no audit trail can untangle. The chosen split reuses the S2 flow that already exists for exactly this ("an account exists; its owner sets its own password"), adds no new token machinery, and keeps AC-13…AC-18's "a signed-in child account" reachable. **Note:** the spec has no AC for this step — it is design-necessary infrastructure implied by AC-13…AC-18, flagged as such. |
| **D2. AC-3/AC-8 "My Trainers" scope** | **Confirmed as the spec reads it**: the parent's *own player account's currently-active* trainer connections only (`findActiveForPlayer($parent)`), never the family aggregate | A family-wide set unioning every child's trainers | AC-3's single-trainer prompt names one trainer ("Will [Child] also train with [Trainer]?"). Under the aggregate reading that count changes as *siblings* connect, so the same parent creating two children an hour apart could get a Yes/No prompt for the first and a checklist for the second with no action of their own in between — a form whose shape depends on unrelated history. FR-026/G-23 also make the parent account a player account, and "My Trainers" is possessive about *that* account. Decisively, the narrow reading loses no capability: AC-8's ShareLink-code path already reaches any trainer at all, including one known only through another child. Widening later is one repository method; narrowing later means unwinding connections parents made to trainers they never trained with. |
| **D2b. Who writes a `TrainerPlayerAssociation`** | One writer: S3's `PlayerShareLinkService`, generalized to `associateWithTrainer(player, trainer, ?link, ?actor)`; `associate()` stays as a wrapper | A separate `ChildTrainerService` insert path for the family flows | AC-17 requires the parent-review path to produce "exactly the same outcome as AC-8's flow — no second, parallel connection mechanism". A second writer would have to re-implement the idempotency pre-check, the partial-unique-index race recovery, the `usage_count` increment and the connection email, and would drift from them. The signature widening is purely additive: no existing call site or S3 test changes. |
| **D2c. Ending a connection under concurrency** | A conditional `UPDATE … SET ended_at = :now WHERE trainer_id = :t AND player_id = :p AND ended_at IS NULL`, with the affected-row count as the answer (1 ⇒ ended, 0 ⇒ "already removed") | S3's read-then-`end()`-then-flush, reused as-is | The spec's third edge case wants exactly one of two simultaneous removals to succeed while the other sees "already removed", not an error. Read-then-write lets both racers report success and lets the loser overwrite the recorded end time, so the audit trail records the wrong moment. The conditional update is a single statement whose row count *is* the distinction. (S3's `leave()` is refactored onto the same helper, fixing the same latent race for the adult path.) |
| **D3. Already-connected child re-clicks a ShareLink** | **Unconditional block + notify, exactly as the spec reads US-01.06** — the child branch runs before any association lookup, and no existing connection short-circuits either the message or the parent request | Skipping the block/notify when the pairing already exists ("nothing new would be created") | The narrower reading is nowhere in the epic, and the spec explicitly flags it as unconfirmed rather than adopting it. Structurally it is also the safer default: the branch is placed before any association query, so "already connected" is not even *knowable* at that point in the flow — the carve-out cannot be reintroduced by accident, and adding it later is one condition in one method. From the child's side the two cases are indistinguishable anyway (they cannot see their own association state on a trainer's landing page), so a silent success would be the confusing outcome. |
| **D3b. Repeat-click notification volume** | The block page and the pending `child_trainer_request` row are unconditional on **every** click; the **email** re-sends only when the request row is newly created or its `last_notified_at` is over 24 hours old | (a) one email per click, literally; (b) one email ever, per pairing | This is the **one declared narrowing of AC-16 in this design, and it is not silent.** (a) is a mail-bomb primitive: a child holding a key or a script can put unbounded mail in the parent's inbox from an authenticated session, and every other mail-sending endpoint in this project acquired a throttle in S3's hardening pass for exactly this reason. (b) loses the parent who deleted the first mail. The 24-hour window keeps AC-16 true the moment the situation arises and on every subsequent day, and the pending row means the "Review Registration" action is always waiting in the parent's own account regardless of mail. A column, not a rate limiter, so the window survives a restart and does not inherit S1's per-node cache-pool caveat. |
| **D3c. Who receives mail about a child** | Always the parent (`NotificationAddressResolver`), regardless of whether the child has real credentials | Send to the child's own address once sign-in is enabled; send to both | BR-011 gives the parent the family's contact information, which is the operative half of G-07's "shares the parent's contact info". It also fixes a live defect this slice would otherwise introduce: S3's `associate()` already queues `PLAYER_TRAINER_CONNECTED` to `$player->getEmail()`, which for a child is a `.invalid` placeholder — an undeliverable message queued on the `async` transport with nothing to fail against. One rule, one resolver, no per-call-site decision. |
| **D3d. "Review Registration" link target** | An authenticated deep link to `/family/requests/{id}` | A single-use `SelectorVerifierTokenFactory` link that acts without a session | AC-16 says the parent takes the action "from their own account". A tokenised link would let anyone holding the email connect a child to a trainer, and would mint a third single-use-token family for an action already reachable from `/family`. Requiring the session costs one sign-in and makes the audit trail's actor unambiguous. |
| **D4. (Q-01.04) AC-16's template** | Its **own** file, `emails/child_share_link_request.html.twig`, on the existing `SendEmailMessage` mechanism (one new `TEMPLATE_*` constant, one new `buildContext()` branch) | Reusing `player_trainer_connected` or `duplicate_registration_attempt` | `player_trainer_connected` confirms a *completed* connection and has no call to action — the opposite of AC-16, where nothing happened and something must. `duplicate_registration_attempt` has the right shape (notifying account A about something someone else attempted) but deliberately has no action button, because there is nothing safe for its recipient to do; AC-16 needs a named "Review Registration" CTA and both people's names. The *mechanism* is reused entirely; only the copy is new. A second new template (`child_sign_in_invitation`) comes from D1d for the same reason — S2's `trainer_invitation` copy is trainer-specific. |
| **D5. Availability storage** | Rows that exist for available ranges only; "Not Available" is the absence of rows | An `is_unavailable boolean` per (player, day); a placeholder row per empty day; a JSON blob on `profile_player` | AC-24 says an unsaved day must be treated as Not Available, "never as unknown". With absence as the encoding, the filter's `INNER JOIN` gives that for free and there is no third state to get wrong. A boolean or placeholder row introduces exactly the "unknown" case AC-24 forbids (row missing vs. row present-and-false), and both need a writer to keep them consistent. A JSON blob makes AC-23's filter a scan instead of an index lookup. |
| **D5b. What availability hangs off** | `player_id` FK to `app_user` | FK to `profile_player.id` | AC-23's filter joins the trainer's roster (`trainer_player_association.player_id` → `app_user`) to availability; an FK to the profile adds a hop through `profile`/`profile_player` on the hottest query in the feature for no gain. It also matches `TrainerPlayerAssociation`'s own precedent of keying players by `app_user`. |
| **D5c. Availability granularity** | Free-form ranges, minute resolution, normalized (sorted, overlaps merged) on save | Fixed hourly blocks (G-16's other reading); storing exactly what was submitted, unmerged | The spec resolved G-16 in favour of free-form ranges to match the epic's own "Monday 5:00 PM–8:00 PM" example. Normalizing at the boundary means two submissions describing the same availability produce identical rows, so AC-24's evaluation and AC-22's summary string are deterministic and diffable — and an overlapping pair, which the spec never defines behavior for, cannot reach storage. |
| **D5d. Availability is per player, not per (player, trainer)** | One weekly grid per player, visible to all of that player's trainers | A grid per child↔trainer pairing, per BR-011's "Best Times **per trainer**" wording | AC-19…AC-21 describe one grid the parent sets per child via a *profile* switcher, and AC-22/AC-23 describe trainers *reading* it — no AC anywhere asks a player to answer the same question once per trainer. BR-011's phrase is the only support, and reading it strictly would multiply the form by the connection count for a feature whose whole point is "tell trainers when you can train". Adding a nullable `trainer_id` to the slot table later is additive if the client confirms the stricter reading. **Flagged in Risks.** |
| **D6. AC-14 enforcement** | Voter attributes **and** service guards, for every deny-list item — including the two (payments, purchase) that have no route yet | Voter only; service guard only; a firewall/`access_control` rule | A voter alone cannot cover a console command, a future API controller, or a request that never reaches the annotated action, and `access_control` cannot express "this player is a child" at all. A service guard alone gives up the clean 403 and pushes the rule into templates. Shipping the two callerless attributes now is what makes the future payments slice fail closed rather than requiring it to remember a rule written in another slice's spec. |
| **D7. Child age 1–18** | A validator constraint on the child creation DTO, not a database CHECK | `CHECK (declared_age BETWEEN 1 AND 18)` on `profile_player` | That column is shared with adult players registered through S3's public form, which permits 1–120. A CHECK there would refuse a shipped path. This is a deliberate exception to this project's "invariants are database facts" habit, and the reason is named rather than assumed. **Flagged in Risks.** |
| **D8. Audit log for family actions** | Reuse `AccountEvent` with six new `AccountEventType` cases, actor = parent, subject = child | A new `family_event` table; no audit at all | `AccountEvent` was built for exactly "one user acting on another" and already carries both an actor and a subject with the right nullability. `type` is a plain `varchar(64)`, so the six cases need no migration. A parallel log would split S6's future reporting across two tables for no structural reason. |

## Risks

- **`account_event.subject_user_id` is `ON DELETE RESTRICT`, and children are new subjects.**
  A child account that accumulates events can no longer be hard-deleted — which is correct
  and matches S2's anonymize-in-place deletion, but it means a parent who creates a child by
  mistake gets a deactivated account, not a vanished one. Cheapest early check: exercise
  `AccountLifecycleService::delete()` on a child in the integration suite and confirm the
  anonymize path handles a `ChildAccount` row (it should be deleted or the child detached —
  **decide this explicitly during implementation; the spec does not cover it**).
- **What happens to children when the *parent* is deactivated or deleted is undefined.**
  The spec has no AC and no edge case for it; `child_account.parent_user_id` is
  `ON DELETE CASCADE`, but S2 never actually deletes an `app_user` row, so the practical
  outcome today is a child whose parent cannot sign in — the child keeps working, and
  nobody can manage its connections. Cheapest early check: a functional test asserting the
  current behavior, so whatever it is, it is chosen rather than discovered. This is the
  most likely source of a support ticket in this slice.
- **D7's 1–18 rule is not a database fact.** A future import, console command, or admin
  edit can store a child with age 30 and nothing refuses it. Mitigation: keep child
  creation behind the one DTO, and if a second writer ever appears, move the rule into a
  shared `ChildAgeRange` validator rather than copying the constraint.
- **D5d (per-player availability) contradicts one reading of BR-011.** If the client
  confirms "Best Times **per trainer**" literally, this table needs a nullable `trainer_id`
  and the filter needs a coalesce (per-trainer override falling back to the general grid).
  Additive, but it changes the form from one grid to N. Ask before building the UI, not
  after.
- **Time zones are absent by construction.** Every stored minute is facility-local
  wall-clock. The day a second facility in another zone exists, every stored range is
  ambiguous and there is no column recording what was meant. Deliberate (the spec puts it
  out of scope) and cheap to fix while row counts are small: a `time_zone` column on the
  player or the facility plus a normalization pass. Revisit before Epic-02 schedules
  anything against these ranges.
- **AC-13's allow-list is "everything not denied", which fails open for future features.**
  A child keeps `ROLE_PLAYER`, so any route a later slice adds is reachable by children
  unless that slice remembers a `PlayerActionVoter` check. D6 ships the payment/purchase
  attributes early to blunt this, but the general hazard remains. Mitigation: a functional
  test that enumerates the router and asserts every route reachable by a child is on a
  reviewed list — the same shape as S1's `RouterSweepTest`, which is the precedent for
  catching this class of drift.
- **The placeholder-email design puts non-addresses in the Users directory.** S2's admin
  directory searches and displays `email`; children will show as
  `child_<uuid>@children.invalid`. Functionally harmless, visually confusing, and a Super
  Admin might try to "fix" one. Mitigation: `ChildEmailFactory::isPlaceholder()` already
  exists — have the directory render "(child account)" instead, and cover it with a
  display test.
- **`associate()` → `associateWithTrainer()` touches the most-tested service in S3.** The
  extraction is behavior-preserving by construction (the wrapper keeps the old signature),
  but it moves the guard order and adds a child check to a hot path. Cheapest early check:
  run S3's full ShareLink functional suite *before* adding any S4 caller, and require it
  green with zero test edits.
- **Two email templates against a still-unanswered Q-01.04.** The transactional email list
  remains client-owned (this was S3's risk too, now with two more files). Nothing depends
  on the answer — each is a file — but expect rewrites if the client supplies copy.
- **`ChildAccountResolver` is consulted on hot paths.** It is one indexed lookup served from
  the identity map after the first call, but it is called from voters, which run per
  authorization check. Cheapest early check: assert the query count on the family page and
  the trainer roster page in a functional test; if it grows with rows, memoize per request
  explicitly.

## Traceability

| Component | Acceptance criteria |
|---|---|
| `ChildProfileFormType` + `ChildAccountService::createChild` + `ProfilePlayer` (+`school`) + `app_user.photo_key` | AC-1 |
| Child's own `User` + own `ProfilePlayer` + `child_account.parent_user_id` | AC-2 |
| `TrainerPlayerAssociationRepository::findActiveForPlayer($parent)` driving the form's three shapes (D2) | AC-3 |
| `ChildTrainerService::connect` called once per selected trainer inside `createChild`'s transaction | AC-4 |
| `CreateChildRequest` constraints (`Range(1,18)`, `NotBlank`, `Choice`) + the two-phase create's compensating delete | AC-5 |
| No unique constraint on `child_account.parent_user_id` | AC-6 |
| `Family\ChildController::index` + `findChildrenOf` + `findActiveForPlayers` (association `created_at` is the start date) | AC-7 |
| `ChildTrainerAddFormType` (code **or** My Trainers) → `ChildTrainerService::connect` → `associateWithTrainer` + `UNIQUE (trainer_id, player_id) WHERE ended_at IS NULL` | AC-8 |
| `Family\ChildTrainerController::confirmRemove` (named copy + RSVP warning) → `disconnect` → conditional `ended_at` UPDATE (D2c); `findRosterFor` filters `ended_at IS NULL`; the row is never deleted | AC-9 |
| The conditional UPDATE's `WHERE trainer_id = :t AND player_id = :p` | AC-10 |
| `PlayerContextProvider::contextsFor` returning `list<PlayerContext>` (self + one per child) | AC-11 |
| The same provider's child branch (`ChildAccountResolver` first, single self context) | AC-12 |
| Child keeps `ROLE_PLAYER` (D1): every existing player route still grants | AC-13 |
| `PlayerActionVoter` (4 attributes) + service guards in `PlayerShareLinkService::associateWithTrainer`/`leave`/`endAssociation` and `AccountLifecycleService::delete` | AC-14 |
| `PlayerShareLinkController::follow`'s child branch → `share_link/child_blocked.html.twig`; no association call is reached | AC-15 |
| `ChildTrainerService::recordBlockedClick` → `child_trainer_request` + `TEMPLATE_CHILD_SHARE_LINK_REQUEST` to `NotificationAddressResolver::forPlayer` | AC-16 |
| `approveRequest` → the **same** `connect()` → the **same** `associateWithTrainer()` (D2b) | AC-17 |
| `PlayerContextProvider`'s child branch + `FamilyVoter::MANAGE_FAMILY` denying children + `AvailabilityVoter` | AC-18 |
| `AvailabilityWeekFormType` + `AvailabilityService::replaceWeek` + `player_availability_slot` | AC-19 |
| `PlayerContextProvider` switcher + `DELETE … WHERE player_id = :player` scoping | AC-20 |
| `Player\AvailabilityController::edit`'s post-save flash naming trainers | AC-21 |
| `AvailabilitySummaryFormatter::summarize` + `findForPlayers` on the roster card | AC-22 |
| `TrainerPlayerAssociationRepository::findRosterAvailableAt` (INNER JOIN on slots) + `AvailabilityFilterFormType` | AC-23 |
| Absence-as-Not-Available (D5): the INNER JOIN cannot match a day with no rows | AC-24 |

Edge cases, in the spec's table order:

1. **Parent with zero trainers creates their first child** — the `trainerIds` field is not
   added to the form at all; `createChild` creates no association (AC-3, AC-4).
2. **Double-submitted trainer checklist** — `associateWithTrainer`'s pre-check plus
   `UNIQUE (trainer_id, player_id) WHERE ended_at IS NULL`, with the
   `UniqueConstraintViolationException` converted to idempotent success (S3's mechanism,
   unchanged).
3. **Two sessions remove the same child from the same trainer** — D2c's conditional
   UPDATE: affected rows 1 ⇒ success, 0 ⇒ `NoActiveTrainerAssociationException` rendered
   as "already removed", never an error page.
4. **Child clicks the ShareLink of a trainer they are already connected to** — D3: the
   child branch runs before any association lookup, so the blocking page and the parent
   request happen with no carve-out. (The *email* obeys D3b's 24-hour re-notification
   window — the one declared narrowing.)
5. **Child forges a POST to the trainer add/remove route** — `PlayerActionVoter` at the
   edge and the `ChildActionNotPermittedException` guard inside
   `associateWithTrainer`/`leave`/`endAssociation`, which is what makes it true for a
   caller that never passes through the controller.
6. **Name/age close to an existing child** — `findSimilar` produces a re-rendered form with
   a warning and a `duplicateAcknowledged` field; never a validation error, never a block.
7. **A child turns 18** — no behavior built, per the spec. The design keeps it a
   single-row change: delete the `child_account` row (and give the account a real email if
   it has none) and the same `User`, `ProfilePlayer`, associations and availability
   continue unchanged as an adult player's.
8. **RSVP cancellation after disconnect** — out of scope; `confirmRemove` renders the
   epic's warning copy and `disconnect` ends the connection only. No Epic-02 integration
   point is stubbed.
9. **Availability set, then that trainer disconnected** — `disconnect` writes only
   `ended_at` on one association row; nothing in this design deletes or edits
   `player_availability_slot` on that path.
10. **Parent with no children and no trainers opens Best Times** — `PlayerContextProvider`
    returns a single self context; the template renders the switcher only when the list has
    more than one entry, and the grid is empty.

**One item is deliberately not fully answered by this design, and is not designed past
silently:** AC-16's literal "the same moment that blocking message is shown, the system
emails the parent" holds on the first click and on any click more than 24 hours after the
last notification for that same child/trainer pairing — but a second click within that
window shows the blocking message and records the request without re-sending the email
(Decision **D3b**, with its reasoning). Every other criterion and every other edge case
above is answerable from this design.
