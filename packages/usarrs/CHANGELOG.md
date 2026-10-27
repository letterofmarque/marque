# Changelog

All notable changes to `marque/usarrs` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md). This changelog starts
2026-08-26 — earlier releases aren't backfilled; see `git log` or
[docs/upgrading.md](../../docs/upgrading.md) for the story up to this point.

## [9.0.0] — 2026-10-27

> Staff are managed from above: only an owner manages admins and grants Admin or Owner, nobody acts on themselves, the last owner can't be demoted, and usarrs:owner sets the first.

### Breaking

- **Admins can no longer ban, unban or change the role of another admin, or of the
  owner.** Before, any admin could demote or ban any other admin, the tracker's owner
  included. Admins and owners are now managed by an owner alone, and only an owner grants
  Admin or Owner. Nobody bans themselves or changes their own role. The last owner can't be
  demoted (`AuthorizationException`).
- **An admin can no longer revoke an admin's or the owner's invite.** An invite is revoked
  by its creator, or by anyone who could manage the creator (`InvitePolicy::revoke`, which
  the invites screen now uses).
- **`InviteServiceInterface::revoke()` takes optional `actor` and `reason`.** An
  implementation of the interface must accept them.
- **Requires `marque/trove` ^5.0**, for `Role::Owner` and `StaffActionsInterface`.

### Added

- **`UserPolicy::setRole($user, $target, Role $role)` and `unban`.** `ban`, `unban`,
  `promote` and `setRole` hold the rules above.
- **Staff actions are recorded** through trove's `StaffActionsInterface`: a ban, an unban
  and a role change made by an actor (`usarrs.user.banned`, `unbanned`, `role_changed`, with
  the status or role before and after), and revoking someone else's invite
  (`usarrs.invite.revoked`). With no store installed, nothing is kept. Recording happens
  inside the change's transaction, so a change rolled back isn't recorded.
- **`UserAdminService::ban()`, `unban()` and `setRole()` take optional `actor` and `reason`.** Given
  one, they check `UserPolicy` against it, so code calling the service gets the same answers
  as the screen. With no actor (app code, the console) the staff rules don't apply. The
  last-owner rule holds either way. It locks the owner rows, so two owners demoting each
  other at once can't leave nobody.
- **`php artisan usarrs:owner {email}`** makes a user an owner. Only an owner grants
  Admin, so a tracker with no owner can never make another admin. This sets the first.
- The user screen offers only the roles the actor may give, hides Ban from those who may
  not use it, refuses either when it's called directly (403), and answers 422 to a role
  that doesn't exist.

### Upgrading

Run `php artisan usarrs:owner you@example.com` once after upgrading. Until someone is an
owner, admins still manage users, uploaders and moderators, but nobody can grant or remove
Admin.

## [8.6.2] — 2026-10-24

> Verifying an email address lands on usarrs.home, not always on /.

### Fixed

- **Verifying an email address lands on `usarrs.home`** when nothing else was asked for
  (#11102). It fell back to `/`, the one post-sign-in redirect that ignored the setting, so
  an app whose `/` is a splash page sent a member who had just verified there. A member
  the `verified` middleware turned away still goes back to that page.

## [8.6.1] — 2026-10-23

> The announce key carries its own text colour, so it reads inside any layout.

### Fixed

- **The announce key has its own text colour in both modes** (#11099). It set a
  background in each and no colour, so under a layout with no body colour, deck's included
  until 2.0.2, it was black on `zinc-800`. It now reads inside any layout, an app's own
  included.

## [8.6.0] — 2026-10-22

> Members see what they owe seeding on under the tracker's hit-and-run rule, and their strikes.

### Added

- **Seeding owed** (Job #256). `/profile/stats` lists the torrents a member still owes
  seeding on under the tracker's hit-and-run rule: seeding so far against what's required,
  the ratio where the tracker accepts one, strikes with when they lapse, and pre-warnings.
  The dashboard's Tracker Stats panel shows the count and links to the list. It's read from
  trove 4.9's `SeedingObligationsInterface`, which bloodhound 9.1 binds, so it requires
  `marque/trove ^4.9`. If you published usarrs' views, re-publish `profile/stats.blade.php`
  and `dashboard/panels/tracker-stats.blade.php`.

## [8.5.0] — 2026-10-22

> Members see where they stand against the tracker's ratio rule: on watch until a date, downloads paused, or exempt.

### Added

- **The member's standing against the tracker's ratio rule**, on the Tracker Stats panel
  and `/profile/stats` (#10732). On watch shows the deadline and what to do, in the
  tracker's words. Paused downloads show why and how to recover. Exempt says so. Ok says
  nothing. It's read from trove 4.8's `TrackerStats::$standing`, which bloodhound 9
  reports, so it requires `marque/trove ^4.8`. If you published usarrs' views, re-publish
  `partials/tracker-figures.blade.php`.

## [8.4.0] — 2026-10-21

> An app's own /dashboard is reported and can coexist, login returns to the page it was going to, a member without an announce key can get one from the dashboard, and the stats panel shows seed and leech time.

### Added

- **`usarrs.dashboard.path`** (`USARRS_DASHBOARD_PATH`, default `dashboard`), so an app
  that keeps its own `/dashboard` can move usarrs' elsewhere. The route name stays
  `dashboard.index`.
- **A log error when an app route has taken the dashboard's path** (#11070). A starter
  kit's `Route::get('dashboard')` loads after usarrs' and replaces it, taking the name
  `dashboard.index` with it, and nothing said why. The error names the route that took the
  path and gives both ways out (panels, or `usarrs.dashboard.path`). It's logged once a day,
  checked after boot, or on the first request when routes are cached. With deck 2.0.1 the
  dead nav link is left out instead of failing every page.
- **`usarrs.home`** (`USARRS_HOME`, default `/`) is where a login lands when the user
  wasn't sent to it from another page.
- **Seed time and leech time on the Tracker Stats panel** and on `/profile/stats`, shown as
  days and hours (never months). Needs `marque/trove ^4.7` for leechtime.

### Changed

- **A login goes back to the page the user was sent to log in from** (#11070). This covers
  password, magic link, OAuth, passkey, after registering, and across a two-factor challenge.
  Otherwise it goes to `usarrs.home`. Before, every login landed on `url('/')`. Password
  confirmation falls back to `usarrs.home` too.
- **The Announce Key panel shows whether or not the user has a key yet.** A verified user
  without one gets the Generate button, and an unverified user is told to verify. Before,
  the panel hid until a key existed, so an imported user, or one whose key was never
  issued, saw nothing on the dashboard.
- **The announce key row wraps, and the key breaks anywhere**, so a narrow dashboard card
  no longer clips the Regenerate button.

## [8.3.0] — 2026-10-20

> Members manage their seedboxes and end their own tracker sessions, admins approve seedboxes, a banned member's API token is refused, and the magic-link mail states the link's real lifetime.

### Added

- **Seedbox screens.** When a tracker binds trove's `SeedboxesInterface` (bloodhound 8.0),
  members register, view and remove their seedboxes at `/profile/seedboxes`, linked from
  `/profile/stats`, and admins approve or revoke them at `/admin/seedboxes`, advertised to
  the admin panel. Without such a tracker, both routes answer 404 and nothing links to
  them. Requires the trove release that adds the contract.
- **Sessions screen.** When a tracker binds trove's `TrackerSessionsInterface` (bloodhound
  8.0), members see their live sessions at `/profile/sessions`, linked from
  `/profile/stats`: torrent, seeding or leeching, client, address and last announce, 50 to
  a page, with **End** on each. Ending one frees a client restarted somewhere else from the
  tracker's location limits. Without such a tracker the route answers 404 and nothing
  links to it.

### Changed

- **No longer requires `marque/threepio` directly.** Nothing in this package's code uses
  it; it arrives through `marque/trove`, which does. So a threepio major no longer needs a
  release of this package. The published versions' `threepio ^3.0` would have blocked
  threepio 4.0, which bloodhound 8.0 requires. Nothing changes for an install.
- **Requires `marque/trove` ^4.6**, for `TrackerSessionsInterface`.

### Fixed

- **A banned member's API token kept working** (#10920). A ban ended web sessions only:
  cennad's routes are `['api', 'auth:api']`, and the `api` group never ran the check.
  `EnsureUserIsActive` now runs in the `api` group too, ordered after authentication, and
  answers a token route with a 403 and the reason. A token guard has no session or
  logout, so nothing else happens; reinstating the member makes the same token work
  again. Both registrations go through the HTTP kernel, so a later kernel change can't
  silently drop them.
- **The magic-link mail said the link lasts 15 minutes; it lasts the password broker's
  expiry** (Laravel's default is 60), and now says so (#10839). It also named the site
  from `id.app_name`, a key gone since `id` became `deck`; it reads `deck.app_name`, then
  `app.name`.

## [8.2.1] — 2026-10-03

> Ships what 8.2.0 should have contained: 8.2.0 reached Packagist without its last fix (manage_auth off no longer errors for unverified members) and its README corrections.

### Fixed

- **8.2.0 on Packagist was missing its last commits.** The split that publishes this
  package committed nothing (an apostrophe in a commit message broke the split tool) and
  tagged the previous tree, so 8.2.0 lacked the two fixes below. Take 8.2.1. The split is
  fixed, and the release tooling now checks the published tree against the tagged one.
- **With `manage_auth` off, an unverified member hit a route-not-found error.** It happened
  at the invite form and on the announce-key notice, because both pointed at
  `verification.notice`, which usarrs registers only while it manages auth. The invite
  form now refuses with a 403 and the notice drops its link.
- README corrections: which statuses are refused, how passkey sign-in is refused, and the
  installer's dashboard option shipping since `marque/marque` 1.0.0.

## [8.2.0] — 2026-10-03

> Security: banned users are refused everywhere, sign-in and the two-factor challenge are rate-limited, and adding passkeys, two-factor, invites or announce keys needs a verified address; passkeys now work on current Fortify, and invite_only lets invites in.

### Security

- **Banned users could still sign in.** `EnsureUserIsActive` existed but nothing
  registered it, and no sign-in path checked `status`. So a banned, disabled or
  pending user signed in normally by every route, and an existing session kept
  working. Now the login seam refuses them with the reason, a `Login` listener
  refuses the paths that skip the seam (passkeys, remember-me), and the middleware
  is pushed onto the `web` group so a live session ends on its next request to any
  page. Only `banned`, `disabled` and `pending` refuse: an app's own `status` column
  can mean anything, so any other value, or no status at all, is left alone.
- **Nothing was rate-limited.** The two-factor challenge accepted unlimited guesses
  per pending login, so a 6-digit code could be brute-forced in hours. A code could
  also be reused inside its window, and password login and password confirmation
  were unthrottled. Now each is limited to five a minute: password login per email
  and IP, the challenge per pending login (codes and recovery codes together), and
  confirmation per user. Each attempt is counted before it's checked, atomically,
  so parallel bursts can't get past the count. A TOTP code works once per user,
  including one from the next time step, and including two requests racing with
  the same code.
- **An unverified account could add passkeys and two-factor, create invites, and
  hold an announce key.** Profile, security and invite routes sat behind `auth`, not
  `verified`. Each of those actions now needs a verified address, as connecting an
  OAuth provider already did. An app whose `User` doesn't implement
  `MustVerifyEmail` is unaffected. Pair this with bloodhound's matching change,
  which holds a new user's key back until verification.

### Fixed

- **Password registration never sent the verification email.** The new account was
  signed in, met the `verified` middleware, and got no mail until the user found
  "resend". `/register` and the OAuth callback now both fire `Registered` and send
  exactly one verification email: usarrs leaves it to Laravel's
  `SendEmailVerificationNotification` listener when the app registers one, and
  sends it itself otherwise. The OAuth path didn't fire `Registered` before.
- **`invite_only` made invites unusable.** `/register` returned a 404 before reading the
  invite code, and it was the only place an invite was redeemed, so under
  `invite_only` no account could be created by any route. Now a valid invite opens
  the form (`/register?invite=CODE`), and anything else still gets the 404.
- **Invites were only used up when `invites.required` was on.** With invites merely
  enabled, an invite stayed pending after someone joined with it. Any valid invite
  presented at registration is now redeemed.
- **The invite email went to the member who created the invite, not the person
  invited.** It now goes to the recipient address. It also no longer reads the dead
  `id.app_name` config key.
- **The invite link ignored `usarrs.prefix`**, so on a prefixed install it led to a
  404. Members can now email at most ten invites an hour.
- **After confirming a password, users landed on `/`** instead of the page that
  asked them to confirm.
- **Removing a passkey from the profile page skipped password confirmation**, which
  the `DELETE` endpoint requires. When adding a passkey needs a confirmed password,
  the page now sends the user to confirm it and brings them back, instead of failing
  with a script error.
- **Passkeys didn't work on current Fortify.** Fortify 1.39 suppresses `laravel/passkeys`'
  routes in order to serve its own, and usarrs suppresses Fortify's, so the WebAuthn
  endpoints existed nowhere. Registering a passkey failed and passkey sign-in didn't
  exist. usarrs now registers those endpoints itself, with `laravel/passkeys`'
  controllers, paths and names, on any Fortify version. They carry `auth.session`,
  `verified` and `password.confirm` for adding, and a named `usarrs-passkeys`
  throttle (Fortify had replaced laravel/passkeys' throttle with nothing). With
  `manage_auth` off, usarrs leaves laravel/passkeys to register its own. Also fixed: a passkey sign-in landed on Fortify's
  `/home` instead of `/`; the profile page's passkey script parsed the wrong part of
  the options response; and the passkey user model was reset by Fortify.

### Added

- **Sign in with a passkey** on the login page, when passkeys are enabled.
- **A Generate announce key button** for a user who has no key. Previously the key
  section simply didn't render, so such a user had no way to get one. It shows only
  to a verified address and isn't subject to `allow_announce_key_regen`, because
  that switch is about replacing a key, not getting a first one.
- A banned or inactive user's passkey is refused (422) through `laravel/passkeys`'
  login authorization hook, before any session exists.

## [8.1.0] — 2026-10-01

> Security: OAuth sign-in no longer signs in whoever has the email the provider reports, and password, magic-link and OAuth sign-in all go through the two-factor challenge; plus the dashboard gets its panels.

### Security

- **OAuth sign-in matched accounts by email — account takeover.** The socialite
  callback looked up the account whose email the provider reported and signed it in,
  creating one if nobody matched. Anyone able to get a configured provider to report
  an address here — the admin's included — was signed in as that account, with no
  two-factor challenge, under any registration setting, and unverified. OAuth
  identities are now stored (`usarrs_social_accounts`) and a sign-in resolves only
  through that stored connection. An unconnected identity whose email matches an
  account (ignoring case) emails *that account's own address* a signed, single-use
  link to connect them, instead of signing anyone in. The link opens a page naming the
  provider account; nothing connects until the holder confirms there, so a mail
  scanner following links connects nothing. (Spec #142, #10818)
- **An account made under someone else's address stayed theirs.** OAuth could create
  an unverified account for an address its creator didn't own; when the real owner
  later connected their own provider from that inbox, the creator kept their way in.
  Confirming a connection now verifies the address and, for an account OAuth made,
  removes everything it gained before it was proven — other provider connections,
  passkeys, two-factor, remembered sign-ins — and ends its open sessions by rotating the
  password hash under `auth.session` — Livewire actions in an already-open tab included.
  Needs your `User` model to implement `MustVerifyEmail`. Accounts from before 8.1 have
  no stored connection, so they're verified but keep their 2FA, passkeys and sessions:
  that protects existing users on upgrade, and means an account squatted *before* 8.1
  keeps what was attached (see the README).
- **Changing an account's email kept it verified.** Switching to your own inbox,
  verifying, and switching back left an account "verified" under someone else's
  address. A changed email now un-verifies the account and sends the new address a
  verification mail. Addresses must now be unique ignoring case, on the profile page and
  `/register` — PostgreSQL and SQLite let `Victim@` sit beside `victim@` (and a clash on
  the profile page was a 500).
- **An unverified account could connect another provider** — a way back in that
  outlived the owner taking the account over. Now refused until the address is verified.
- **The connection email rendered the provider's display name as markdown**, so a
  name like `[Reset your password](https://…)` became a live link in this site's own
  mail. It also identified the provider account by that name and the recipient's own
  email — nothing an attacker couldn't copy. It now shows the provider's handle and
  id, as plain text.
- **One invite could create several accounts.** Concurrent sign-ups carrying the same
  invite all redeemed it. The invite is now claimed in one conditional write, in the
  same transaction as the account (and, for OAuth, its connection), on `/register`
  and the OAuth callback alike.
- **The OAuth callback told anyone which addresses have accounts** when registration
  was closed: "we've sent a link" for a known one, "registration is closed" for an
  unknown one. Both now get the same answer.
- **Magic-link sign-in skipped two-factor.** A user with 2FA confirmed who followed a
  magic link was signed straight in. Every login usarrs performs — password, magic
  link, OAuth, straight after registering — now finishes through one place that applies
  the challenge. Passkey sign-in (`laravel/passkeys`' own endpoint, when enabled) is the
  exception: it asks for no TOTP code afterwards, a passkey being a phishing-resistant
  factor already.
- **`socialite` mode wasn't OAuth-only.** Only the form was hidden: password login,
  password registration, password reset and magic-link tokens all still worked. They
  are now refused on the server. Passkeys, if you've enabled them, still work
  alongside OAuth. (#10802)
- **The OAuth routes existed under every driver**, gated only on
  `socialite_providers` (default `['github']`). They now exist only under `socialite`.
- **A signed-in user completing OAuth could be switched into another account**, or
  have a new one created. It now connects the provider to their own account, and
  refuses one that's connected elsewhere.

### Added

- **Dashboard panels.** `/dashboard` (`dashboard.index`) shipped in 8.0.0 as an empty page
  that renders whatever trove's `DashboardPanelRegistry` holds, and went unannounced. It now
  has four panels from usarrs, each shown only when it has something true to say:
  - **Tracker Stats** and **Announce Key** — registered only when a tracker has bound
    trove's `TrackerStatsInterface`, and read through it. Regenerate goes through the
    contract with the same confirm dialog as `/profile/stats`.
  - **Account Security** — two-factor (on once *confirmed*, not merely enabled) and passkey
    count, when either feature is enabled. Not registered under `manage_auth=false`, since
    it reports Fortify's columns and a custom auth has replaced them.
  - **Invites** — allowance, pending count, and a send link, when invites are enabled and
    the user has any to send or outstanding.
- **A Dashboard navigation entry** (`usarrs-dashboard`, position 5) for signed-in users.
  Apps rendering deck's navigation gain the link with no change on their side.

- **OAuth accounts follow the registration rules.** One `RegistrationRules` answer is
  shared by `/register` and the OAuth callback: no account where registration is
  closed or a required invite is missing. Send an invite through the redirect —
  `/auth/github/redirect?invite=CODE`. New OAuth accounts are unverified and sent the
  verification email.
- The profile page now shows flashed status and error messages (where connecting a
  provider lands).

### Changed

- **usarrs' signed-in routes and the OAuth routes carry `auth.session`**, and every
  sign-in records the password hash it was made under, so a changed hash ends the
  session everywhere. `AuthenticateSession` is added to Livewire's persistent middleware,
  so it covers component actions too. A user who changes their password on the profile
  page stays signed in there and is signed out of their other sessions. Where
  `laravel/passkeys`' own routes are registered, they carry it too.
- **`InviteService::redeem()` throws `Marque\Usarrs\Exceptions\InviteAlreadyRedeemed`**
  when the invite is no longer pending and unexpired in the database — used, revoked or
  expired since it was looked up. It used to overwrite whatever was there. The
  interface signature is unchanged; call it inside the transaction that creates the
  account, and catch it.
- **Magic-link verification only works under the `magic_link` driver.** It accepted
  any password-reset token under every driver and signed the user in.
- **Password reset only exists under `password` and `invite_only`** — what
  `AuthDriver::supportsPasswordReset()` always declared and nothing enforced. Under
  `magic_link` and `socialite` the reset routes are 404s.
- `/profile/stats` renders its figures and announce key from two shared partials,
  `usarrs::partials.tracker-figures` and `usarrs::partials.announce-key`, which the dashboard
  panels use too. Output is unchanged. A previously published `profile/stats.blade.php` keeps
  working as-is.

### Upgrading

- **Run `php artisan migrate`** — adds `usarrs_social_accounts`.
- **socialite sites:** existing OAuth users have no stored connection yet. The first
  time each signs in with OAuth they're emailed a link to connect it; they confirm on
  the page it opens, and every sign-in after goes straight through. Make sure mail works
  before upgrading. The email names the provider account asking to connect — **tell
  your users to expect it, and to ignore one naming an account that isn't theirs.**
  `laravel/socialite` is still required (it was never a hard dependency).
- **If you call `InviteService::redeem()` yourself**, catch `InviteAlreadyRedeemed` (see
  Changed).
- **Add `auth.session` to your app's own signed-in routes**
  (`Route::middleware(['auth', 'auth.session'])`). usarrs ends a squatter's sessions
  through it; routes without it are left open to them.
- **Passkeys: known issue (#10883).** On recent Fortify (1.39 at least) the passkey
  endpoints aren't registered at all, so passkeys don't work — leave them off for now.
  Not new in 8.1; found while hardening this release.
- **Use a shared, persistent cache store** — pending OAuth connections live there
  between the email and the confirmation. `array`, or `file` across several servers,
  breaks every link.
- **OAuth on a non-socialite site stops working.** If you relied on the OAuth routes
  being live alongside `password`, they're gone — that combination was never
  documented and is what let the takeover reach every install.
- **`password`-driver sites whose users followed magic links**, or **`magic_link` /
  `socialite` sites using password reset**, will now get 404s on those routes.

## [8.0.0] — 2026-09-25

> Reads tracker figures and announce keys through trove's tracker stats contract instead of probing the User model, and gates the guest-only auth routes behind `guest` middleware.

### Changed

- **BREAKING: `login`, `register`, `two-factor-challenge` and both `forgot-password`
  routes now carry `guest` middleware** and redirect an authenticated user instead
  of rendering. They previously ran under plain `['web']` despite the group being
  commented "Guest routes", so a logged-in user could open the login form
  (job #10698, reported by twentyt).

  Major under [VERSIONING.md](../../VERSIONING.md) because it changes default
  behaviour on shipped routes: requests that returned 200 now return a redirect.
  Nothing is renamed or removed — every route name, path and component is
  unchanged.

  **If you worked around this**, you can now drop the workaround. twentyt
  re-registered `/login` in its own `routes/web.php` with `->middleware('guest')`
  ahead of the package's routes; that override is now redundant and should be
  removed rather than left shadowing the package route.

  If you need the old behaviour, set `usarrs.guest_middleware` to `['web']`.

### Added

- **`usarrs.guest_middleware` config key**, default `['web', 'guest']`, mirroring
  the existing `auth_middleware`. Overridable by publishing the config.

  The auth routes now use three middleware stacks rather than two, because they
  divide into three audiences. `reset-password`, the magic-link routes and the
  socialite callbacks stay on `middleware` (`['web']`) and are **not** guest-gated
  on purpose: an authenticated user can legitimately follow a reset link from
  email, click a magic link issued on another device, or complete an OAuth
  callback to link an additional provider. Gating the whole group would break all
  three, which is why this needed a route split rather than a one-line config
  change.


### Breaking

Upgrade guide: [bloodhound v6 / usarrs v8](../../docs/upgrade-guide-bloodhound-v6-usarrs-v8.md).

- **Requires `marque/trove` `^4.3`**, for `TrackerStatsInterface`.
- **Tracker stats come from the tracker, not the User model.** The profile stats page,
  the profile's "Tracker Stats" link, the admin user page and announce-key regeneration
  all ask `TrackerStatsInterface`. With no tracker installed the stats and key sections
  are **absent** — previously they rendered whenever the User model happened to have a
  `getRatio()` method or an `announce_key` attribute, including values nothing maintained.
  Regenerating a key with no tracker is a 404.
- **The views receive different data.** `profile/stats` gets `$stats` (a `TrackerStats` or
  null) and `$announceKey` instead of `$hasTrackerStats` and `$user`; `admin/show` gets
  `$stats` instead of `$hasTrackerStats`. **If you published `usarrs-views`, update your
  copies** — the guide shows the change.

### Fixed

- **An infinite ratio rendered as an empty box.** `getRatio()` returns null when nothing
  has been downloaded, and the view printed it raw. It now shows ∞.

## [7.0.0] — 2026-09-11

> Requires `marque/deck` in place of `marque/ise`, and registers its admin screen and nav entries against trove's registries.

### Changed

- **BREAKING: requires `marque/deck` `^2.0` instead of `marque/ise` `^1.0`.** The
  shell package was renamed; see the
  [upgrade guide](../../docs/upgrade-guide-ise-to-deck.md). Major because it
  changes the install set.

- **`route('admin.users.index')` and `route('admin.users.show')` are unchanged** —
  same names, same paths, same components. usarrs keeps binding its own routes; the
  registry entry only makes the screen discoverable from an admin panel.

### Added

- **Registers its user admin screen** with trove's `AdminScreenRegistry`, so
  installing [`marque/skipper`](https://github.com/letterofmarque/skipper) lists it
  in the panel automatically. The declared floor is `Role::Moderator`, matching what
  `UserIndex::mount()` already enforces. Skipped entirely when
  `usarrs.admin.enabled` is false.

- **Registers Profile and Admin nav entries** with `NavRegistry`. The Admin entry
  points at `admin.users.index` — a route usarrs owns — replacing the shell's old
  link to an `admin.index` that nothing had ever registered.

- usarrs does **not** require or suggest skipper. With no panel installed the
  registrations are simply never read.

## [6.2.0] — 2026-09-04

> Lowers the PHP floor to 8.3, matching Laravel 13's own requirement.

### Changed

- **`php` constraint widened from `^8.4` to `^8.3`.** Nothing in this package
  ever required 8.4 — no property hooks, no asymmetric visibility, none of the
  8.4 array or `mb_*` functions — and Laravel 13 itself only requires `^8.3`.
  The old floor turned away working Laravel 13 apps for no technical reason.

  Lowering a floor never breaks an existing install: if you are on 8.4 you stay
  on 8.4 and nothing changes.

- Dev-only: the test suite moved from Pest 5 to Pest 4, because Pest 5 requires
  PHP 8.4 and so made the floor untestable. The suite uses only `it`/`test`/
  `expect`/`describe`/`beforeEach`, which are identical across both. No effect
  on consumers — `require-dev` is not installed downstream.

## [6.1.2] — 2026-09-04

> Fixes the passkeys migration assuming an `App\Models\User` class that need not exist.

### Fixed

- **The passkeys migration failed on any app without an `App\Models\User` class.**
  It resolved the foreign key via `Passkeys::userModel()`, but usarrs only sets that
  static when `usarrs.passkeys.enabled` is true — and it defaults to false. With
  passkeys off, the migration still ran and still read the static, getting
  `laravel/passkeys`' own default of `App\Models\User`: a class usarrs cannot assume
  a consumer has, whatever `trove.user_model` points at.

  It now reads `trove.user_model` from config, the same source the service provider
  uses, so it no longer depends on a feature flag being on.

  This was invisible because `orchestra/testbench` 11.4.0 ships an `App\Models\User`
  stub. On 11.3.5 — within the `^11.0` range usarrs declares — every test in the
  package failed. Found by a `--prefer-lowest` CI matrix run, which is also what
  guards it: the migration runs once during `RefreshDatabase`, before any test body
  executes, so nothing in-process can reproduce the condition.

## [6.1.1] — 2026-09-03

> Widens the `marque/trove` constraint to allow trove 4.x. No functional change.

### Changed

- `marque/trove` constraint widened to `^3.0|^4.0`. Trove 4.0 changes
  `TorrentServiceInterface` signatures and removes a column, neither of which
  usarrs touches — but Composer would otherwise refuse to install usarrs
  alongside the rest of the suite. Nothing in this package behaves differently.

## [6.1.0] — 2026-09-02

> Fixes a lockout where unverified users had no route to verify, leaving `verified` middleware unsatisfiable.

### Fixed

- **Lockout:** usarrs never re-registered Fortify's email-verification or
  password-confirmation routes after v6.0.0 suppressed Fortify's own routes
  unconditionally — leaving Laravel's stock `verified` and `password.confirm`
  middleware permanently unsatisfiable for any unverified user, with no route to
  fix that. Notably, usarrs' own `admin_middleware` default
  (`['web', 'auth', 'verified']`) was itself internally inconsistent as a result.
  Found via a cold-upgrade test (job #10602). New `EmailVerificationController`
  (`verification.notice`/`verification.verify`/`verification.send`) and
  `PasswordConfirm` Livewire component (`password.confirm`) close the gap — same
  route names and behaviour a stock Fortify app would have provided. See
  [Marque 4.3](../../docs/releases/4.3.md) for the full story.

### Added

- Both new surfaces are gated by `manage_auth`, same as the rest of the auth
  surface.

## [6.0.0] — 2026-09-01

> Requires Fortify; adds off-by-default two-factor auth and passkeys, plus a `manage_auth` escape hatch.

### Added

- Two-factor authentication (TOTP), off by default
  (`config('usarrs.two_factor.enabled')`, `USARRS_2FA_ENABLED`). Uses Fortify's own
  action classes via new `TwoFactorSetup` and `TwoFactorChallenge` Livewire
  components. Requires `Laravel\Fortify\TwoFactorAuthenticatable` on your `User`
  model.
- Passkeys (WebAuthn), off by default (`config('usarrs.passkeys.enabled')`,
  `USARRS_PASSKEYS_ENABLED`). Uses `laravel/passkeys` via a new
  `PasskeyManagement` Livewire component. Requires
  `Laravel\Passkeys\PasskeyAuthenticatable` and
  `Laravel\Passkeys\Contracts\PasskeyUser` on your `User` model.
- `manage_auth` escape hatch (`config('usarrs.manage_auth')`,
  `USARRS_MANAGE_AUTH`, default `true`). When `false`, usarrs registers none of its
  own auth routes or Livewire components — documented as a one-way operational
  decision for a fully custom auth implementation. Profile, invites, admin, and
  announce-key management are unaffected either way.

### Changed

- **Breaking:** now requires `laravel/fortify` (`^1.37`) as a hard dependency
  (previously only used if the host app installed it separately).
  `Fortify::ignoreRoutes()` is now called unconditionally, closing a route
  collision where Fortify's own `/login`/`/register` could stay reachable
  underneath usarrs' `auth_driver` restrictions. See
  [Marque 4.2](../../docs/releases/4.2.md) and the
  [full upgrade guide](../../docs/upgrade-guide-usarrs-v6.md).
- New migrations: two-factor columns on the users table, and a `passkeys` table
  (sourced from Fortify's and `laravel/passkeys`' own publishable migrations).

## [5.0.0] — 2026-08-26

> Renames the profile page's `PasskeyManagement` component.

### Changed

- **Breaking:** the profile page's `PasskeyManagement` component is renamed
  to `AnnounceKeyManagement`, along with its route, Livewire tag, and the
  `show_passkey`/`allow_passkey_regen` config keys (now
  `show_announce_key`/`allow_announce_key_regen`). See
  [Marque 4.1](../../docs/releases/4.1.md) and the
  [full upgrade guide](../../docs/upgrade-guide-bloodhound-v4-usarrs-v5.md).

## [4.0.0] — 2026-08-20

> Depends on `marque/ise` instead of the renamed `marque/id`.

### Changed

- **Breaking:** now depends on `marque/ise` instead of `marque/id`. See
  [Marque 4.0](../../docs/releases/4.0.md).

## [3.0.0] — 2026-08-13

> Raises the floor to PHP 8.4 and Laravel 13.

### Changed

- **Breaking:** now requires PHP 8.4 and Laravel 13. See
  [Marque 3.0](../../docs/releases/3.0.md).
