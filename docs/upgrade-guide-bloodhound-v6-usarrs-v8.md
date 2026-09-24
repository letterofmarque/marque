# Upgrading to Bloodhound v6 / Usarrs v8

> This is the technical, step-by-step checklist. The package changelogs
> ([bloodhound](../packages/bloodhound/CHANGELOG.md), [usarrs](../packages/usarrs/CHANGELOG.md),
> [trove](../packages/trove/CHANGELOG.md)) have the full list of what changed.

This covers one change seen from three packages. **Tracker data now has an owner.**
Until now, usarrs read bloodhound's columns straight off your User model — `uploaded`,
`downloaded`, `announce_key`, `getRatio()` — by checking whether the methods happened to
exist, across a dependency usarrs never declared. `HasTrackerStats` made those columns
mass-assignable on your model so that usarrs could write them.

Now:

- **trove** declares `TrackerStatsInterface`, the questions anyone may ask a tracker.
- **bloodhound** answers them, and keeps announce keys in a table of its own
  (`announce_keys`) instead of a column on your `users` table.
- **usarrs** asks, rather than probing your model.

**If you don't run bloodhound, there's nothing to do beyond Step 1.** usarrs simply stops
showing tracker sections that had no tracker behind them.

## Before You Start

1. Back up your database.
2. Find every place your own code touches announce keys or tracker columns:

   ```bash
   grep -rn "announce_key\|regenerateAnnounceKey\|generateAnnounceKey" app resources routes
   ```

3. Note whether you have published usarrs views (`resources/views/vendor/usarrs/`).

## Step 1: Update Composer Dependencies — together

```bash
composer require marque/trove:^4.3 marque/bloodhound:^6.0 marque/usarrs:^8.0
```

**In one command.** bloodhound 6 declares a conflict with usarrs below 8, because usarrs 7
reads the old column and calls methods bloodhound 6 removes. Composer refuses that pair
rather than letting it half-work, so upgrading them one at a time will fail at the first
step.

## Step 2: Run the Migration

```bash
php artisan migrate
```

This creates `announce_keys` and copies every existing key into it in a single
`INSERT … SELECT`. **Every existing key keeps working**: users don't need to download
their .torrent files again.

`users.announce_key` is **left in place with its data**, and nothing reads or writes it
from now on. We never drop a column from a table we don't own. Once you're satisfied,
you can drop it yourself in your own migration. Leaving it costs nothing.

## Step 3: Stop Reading `$user->announce_key`

**This is the step that fails silently if missed.** After the upgrade,
`$user->announce_key` is the dead column. It holds the user's key as of the migration and
never changes again, and a new user's is null. An announce URL built from it works until
the user regenerates their key, then quietly stops.

```php
// before
route('tracker.announce', ['announce_key' => $user->announce_key]);

// after
use Marque\Trove\Contracts\TrackerStatsInterface;

route('tracker.announce', [
    'announce_key' => app(TrackerStatsInterface::class)->announceKeyFor($user),
]);
```

If the same code can run on an install without bloodhound, check first:
`app()->bound(TrackerStatsInterface::class)`.

## Step 4: Replace the Trait's Key Methods

`HasTrackerStats` no longer has `generateAnnounceKey()` or `regenerateAnnounceKey()`.
A key is the tracker's to issue:

```php
// before
$user->regenerateAnnounceKey();

// after
app(TrackerStatsInterface::class)->regenerateAnnounceKey($user);
```

New users still get a key automatically when they're created, provided your User model
uses `HasTrackerStats`. It's written to `announce_keys`.

If your User model defined its own `generateAnnounceKey()` to control the key format, it
is no longer called. bloodhound mints 32 alphanumeric characters, which is what the
default `key_pattern` accepts.

## Step 5: Tracker Figures — Ask, Don't Probe

`getRatio()`, `getRatioForHumans()` and the other formatting helpers are still on the
trait, so existing views keep working. For anything new, or anything that must also run
without bloodhound:

```php
$stats = app()->bound(TrackerStatsInterface::class)
    ? app(TrackerStatsInterface::class)->statsFor($user)
    : null;

$stats?->uploaded;            // bytes
$stats?->ratio;               // float, unrounded; NULL MEANS INFINITE
$stats?->hasInfiniteRatio();
```

⚠️ **A null ratio is infinite, not unknown.** It means nothing has been downloaded. Don't
render it as `0.00`.

## Step 6: Mass Assignment

`HasTrackerStats` no longer adds `announce_key`, `uploaded`, `downloaded` and `seedtime` to
your model's `$fillable`. That was a security problem: an ordinary
`User::create($request->all())` let a user set their own key and upload figure.

- **If your own code mass-assigned those columns**, it now silently drops them. Assign them
  directly, or better, don't: bloodhound owns them.
- **If your User model uses `$guarded = []`**, mass assignment works properly again.
  Previously the trait's additions made Laravel accept *only* those four columns, silently
  dropping `name`, `email` and the rest from `create()`.

## Step 7: Published usarrs Views

Skip this if you never ran `vendor:publish --tag=usarrs-views`.

The views receive different variables. Re-publish and re-apply your customisations, or
edit your copies:

| View | Before | After |
|---|---|---|
| `profile/stats` | `$hasTrackerStats`, `$user->uploaded`, `$user->getRatio()`, `$user->announce_key` | `$stats` (a `TrackerStats`, or null with no tracker) and `$announceKey` |
| `admin/show` | `$hasTrackerStats`, `$targetUser->uploaded`, `$targetUser->getRatio()` | `$stats` |

For the ratio, render `$stats->hasInfiniteRatio() ? '∞' : number_format($stats->ratio, 2)`.

## Verify

- Announce with an existing .torrent file: it should work unchanged.
- Regenerate a key from the profile's Tracker Stats page, confirm the page shows the new
  key, then announce with a freshly downloaded .torrent.
- Grep from "Before You Start" again. Anything still reading `announce_key` off a User
  model is reading the dead column.
