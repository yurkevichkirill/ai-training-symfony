# New test accounts (Epic-01 end-to-end verification)

Created 2026-08-20/21 via real HTTP requests against `http://localhost:8080` (simulating
the browser), plus the one console command that is the intended, only way to bootstrap a
Super Admin. Emails were captured in Mailpit (`http://localhost:32769`) and the
invitation/coach-invitation/verify-email links inside them were followed exactly as a
user's browser would. Existing seed users (`admin@example.test`, `trainer@example.test`,
`coach@example.test`, `player@example.test`) were left untouched.

| Role | Email | Password | How created | Verified via UI (sign-in) |
|---|---|---|---|---|
| Super Admin | `admin2@example.test` | `Tr0ub4dor&3-Zephyr-Quartz-9182` | `docker exec ai-training-symfony-php-1 php bin/console app:create-super-admin -n --force` (with `SUPER_ADMIN_EMAIL`/`SUPER_ADMIN_PASSWORD` in the real process env) — the **only** intended path; there is no HTTP self-registration for Super Admin by design (BR: only a Super Admin can create Trainer accounts; console-only bootstrap per decision G-08). | Yes — `POST /login`, redirected to `/admin` (Super Admin dashboard). |
| Trainer | `trainer2b@example.test` | `Falcon-Meridian-77-Cobalt-Reef` | Logged in as `admin2@example.test`, then `POST /admin/users/create` with the `create_trainer_form`. This queued an `AccountInvitation` email (captured in Mailpit), whose `/invitations/{token}` link was opened and its `change_password_form` submitted to set the password. | Yes — `POST /login`, redirected to `/trainer` (Trainer dashboard). |
| Coach | `coach2@example.test` | `Harbor-Solstice-42-Amberwood` | Logged in as the new trainer, then `POST /trainer/coaches/invite` with `coach_invitation_form`. This queued a coach-invitation email; its `/coach-invitation/{token}` link was opened and `coach_registration_form` submitted (name, password, phone). Account then required email verification — the "Welcome, verify your email" email's `/verify-email/{token}` link was opened to activate it. | Yes — `POST /login`, redirected to `/coach` (Coach dashboard). |
| Player | `player2@example.test` | `Meadow-Kinetic-63-Pinnacle` | Fully anonymous, self-serve: `GET /join/uscguzvGE2H3` (the new trainer's own static ShareLink code, read from `/trainer/share-link`) redirected to `/join/uscguzvGE2H3/register`, where `player_share_link_registration_form` was submitted (contact + player details). Required email verification, completed via the `/verify-email/{token}` link from the resulting email. No admin or trainer action was needed beyond the trainer's link already existing. | Yes — `POST /login`, redirected to `/player` (Player dashboard). |

## Is self-registration through the UI possible for each role?

- **Super Admin**: No. By design, console-only (`app:create-super-admin`); there is no HTTP
  route for it at all.
- **Trainer**: No. Only an existing Super Admin can create a Trainer account
  (`POST /admin/users/create`); the trainer then only sets their own password via the
  invitation link — they never "register" themselves from scratch.
- **Coach**: No, not unprompted. A coach can only join after a Trainer sends them an invite
  (`POST /trainer/coaches/invite`); the coach then completes registration via that
  invitation's token link.
- **Player**: Yes. Once any Trainer's static ShareLink exists, anyone can self-register as
  a player with zero admin or trainer action per registration — `GET /join/{code}` →
  `POST /join/{code}/register`, followed by the standard email-verification step.

## Operational notes / snags encountered

- **Stateless CSRF**: this app uses stateless CSRF (`framework.csrf_protection.stateless_token_ids`
  covers `submit`, `authenticate`, `logout`). The rendered `_token`/`_csrf_token` fields are a
  literal placeholder (`csrf-token`) filled in client-side by a Stimulus controller; validation
  is same-origin based (`Origin`/`Referer`/`Sec-Fetch-Site` headers), not token-content based.
  All POSTs above included `-H "Origin: http://localhost:8080"`, a matching `Referer`, and
  `Sec-Fetch-Site: same-origin`, and submitted the literal placeholder value — this is
  accepted exactly like a real browser submission would be.
- **Mail is async and, by default, discarded**: `SendEmailMessage` is dispatched to the
  `async` Messenger transport (Doctrine-backed), and the repo's committed `.env` sets
  `MAILER_DSN=null://null` (mail silently dropped) even though a `mailer` (Mailpit) service
  exists via `compose.override.yaml`. Running `messenger:consume async` without overriding
  `MAILER_DSN` acknowledges (and permanently loses) queued emails against the null transport.
  The fix used here: run the consumer with `-e MAILER_DSN="smtp://mailer:1025"` on
  `docker exec` so only that consume invocation talks to Mailpit, e.g.:
  `docker exec -e MAILER_DSN="smtp://mailer:1025" ai-training-symfony-php-1 php bin/console messenger:consume async --limit=3 --time-limit=10`.
  One trainer-invitation email (`trainer2@example.test`) was lost this way before the DSN
  override was in place; that email address was abandoned in favor of `trainer2b@example.test`.
- **Coach and Player accounts require a post-registration email verification step**
  (a separate "Welcome — verify your email address" message) before they can sign in;
  the Trainer's own invitation flow does not have this extra step (the invitation link
  itself is the verification).

## Demo dataset (`app:seed-demo-data`)

A large, realistic demo dataset (2026-08-21) was seeded via
`docker exec ai-training-symfony-php-1 php bin/console app:seed-demo-data --force`
(`src/Command/SeedDemoDataCommand.php`) so the app looks lived-in when browsing
`http://localhost:8080`. It goes entirely through the real application services
(`TrainerOnboardingService`, `CoachInvitationService`/`CoachRegistrationService`,
`PlayerRegistrationService`/`PlayerShareLinkService`,
`ChildAccountService`/`ChildTrainerService`, `AvailabilityService`/
`CoachAvailabilityService`, `TrainerBrandingService`, `ProfileService`) — the only
shortcut taken is calling `User::markEmailVerified()` directly right after each
account is created, instead of following a real `/verify-email/{token}` link, so
every seeded account can sign in immediately.

**Shared password for every seeded account:** `DemoPass!2026`

Seeded in the run recorded here: 4 trainers (each with distinct branding —
`primaryColorHex`), 10 coaches (2–3 per trainer, one per trainer with a public
`ProfileCoach` bio and a weekly availability grid), 30 players (6–9 per trainer,
one per trainer additionally connected to a second trainer, some with weekly
availability grids), 5 parent accounts with 7 child accounts total (some
children connected to a trainer at creation, some connected afterward), 4
trainer static ShareLinks (visible via each trainer's `/trainer/share-link`),
and 1 pending `ChildTrainerRequest` (a blocked ShareLink click, visible on the
blocking parent's family page).

Every email the command creates has the shape `demo-<role><n>-<batch>@example.test`
(a random 5-hex-char batch suffix per run), so re-running the command is safe —
it never collides with an existing row, it just adds another batch. None of the
pre-existing seed/demo accounts above were touched.

To inspect the full seeded list rather than re-deriving it from the command's
naming scheme, query the database directly, e.g.:

```sql
SELECT email, role, status, email_verified_at IS NOT NULL AS verified
FROM app_user
WHERE email LIKE 'demo-%@example.test'
ORDER BY role, email;
```
