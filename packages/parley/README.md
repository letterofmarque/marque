# Marque Parley

Polymorphic threaded discussion for the [Marque](https://github.com/letterofmarque/marque)
tracker platform. Torrent comments and a lightweight forum, from one data model.

```
  threads
    ├─ threadable set, no title    →  comments on a torrent, or anything else
    ├─ threadable null, has title  →  a forum thread
    └─ pinned + locked, no title   →  an announcement
```

## Why

Comments and a forum look like two features. They are one: a title-less, subject-attached
thread and a titled, categorised thread are the same row shape with different columns set.
Building that as a single polymorphic model means a comment box and a forum share every
line of posting, replying, editing and moderation logic — nothing is implemented twice, and
anything that later wants discussion (requests, uploads, user profiles) gets it by attaching
to the same `threads` table.

Parley does not implement its own text formatting. Post bodies go through
[marque/squidink](https://github.com/letterofmarque/squidink) — parley stores source text
and the name of the parser that wrote it, and renders through squidink's pipeline. A post
and a torrent description behave identically, and the site owner's choice of input syntax
applies everywhere.

## Installation

```bash
composer require marque/parley
```

Publish the config if you want to change anything:

```bash
php artisan vendor:publish --tag=parley-config
```

Requires `marque/trove` (user model, roles) and `marque/ise` (Blade components) —
both are `composer require`d automatically as dependencies.

## Attaching discussion to a model you own

Add the `HasThreads` trait to any Eloquent model:

```php
use Marque\Parley\Concerns\HasThreads;

class Torrent extends Model
{
    use HasThreads;
}
```

```php
$torrent->comments($userId);   // the comment thread, created on first use
$torrent->commentCount();
$torrent->hasComments();
$torrent->latestComment();
```

The thread is created lazily — reading it never creates a row, so a torrent nobody has
commented on yet holds no thread. Only posting does.

### Attaching to a model you don't own

If the model belongs to a package that can't take a dependency on parley — the situation
guise is in with trove's `Torrent`, since trove is Marque's one mandatory package — use
`ThreadServiceInterface::threadFor()` instead of the trait. It resolves the same thread by
morph class and key, without requiring `HasThreads` on the model at all:

```php
use Marque\Parley\Contracts\ThreadServiceInterface;

$thread = app(ThreadServiceInterface::class)->threadFor($torrent, auth()->user());
```

See [docs/integration.md](../../docs/integration.md) in the monorepo for the full reasoning
— it covers this pattern generally, for any optional package attaching to a model it
doesn't own.

## The comment thread component

One Livewire component serves both presentations — a torrent's comments and a forum
thread's replies are the same posting, replying, editing and deleting mechanics on the same
models, so there is no second implementation to keep in sync.

```blade
{{-- Comments: pass the subject, the thread resolves or is created lazily --}}
<livewire:parley-comment-thread :subject="$torrent" />

{{-- Forum: pass the thread directly, it always already exists --}}
<livewire:parley-comment-thread :thread="$thread" />
```

Handles the full post tree — arbitrary-depth nested replies, submit, reply, edit, delete,
all policy-gated — with an empty state and permission-aware controls. Soft-deleted posts
render as `[deleted]` rather than disappearing, so a reply chain survives its parent's
removal.

Indentation caps at `config('parley.nesting.indent_depth')`; nesting itself has no limit in
storage. Changing the cap is a display decision, not a migration.

## The forum

Behind a config toggle — comments and the forum share one set of tables, so switching the
forum off is a route/UI choice, not an install choice:

```php
'forum' => [
    'enabled' => env('PARLEY_FORUM', true),
],
```

When off, the forum's routes are not registered at all — a comments-only deployment 404s
the forum rather than merely lacking a link to it.

Four pages, each a full Livewire component:

| Component | Route name | Purpose |
|---|---|---|
| `CategoryIndex` | `parley.forum.categories.index` | Every category, thread count |
| `ThreadIndex` | `parley.forum.categories.show` | Threads in a category, pinned first |
| `ThreadShow` | `parley.forum.threads.show` | One thread — title, moderation, posts |
| `ThreadCreate` | `parley.forum.threads.create` | New thread: title + first post |

Categories are created directly against the `Category` model — there's no admin UI in the
package; manage them the way you manage any other reference data in your app.

## Moderation

Pin, lock and soft-delete, keyed off `marque/trove`'s roles:

```php
'moderation' => [
    'role' => 'moderator',   // the minimum trove role that can moderate
],
```

Authors can always edit and delete their own posts and threads. Pin and lock are
moderator-only regardless of ownership. Locking a thread is enforced at the service layer
(`ThreadLockedException`), not just hidden in the UI, so it holds against any caller — a
REST endpoint, a queued job, a script — not only the component that greys out its submit
button.

## Rate limiting

**Off by default. Turn this on before any public deployment.** An unlimited post/reply
surface is fine for a private, trusted-user tracker — it is not fine for anything a
stranger can reach.

```php
'rate_limiting' => [
    'enabled' => env('PARLEY_RATE_LIMITING', false),
    'max_attempts' => env('PARLEY_RATE_LIMIT_MAX', 5),
    'decay_seconds' => env('PARLEY_RATE_LIMIT_DECAY', 60),
],
```

`max_attempts` posts (or replies — they share one limit) per `decay_seconds`, per user.
The defaults (5 per minute) are a starting point, not a rule — tune to your own traffic and
moderation capacity. Enforced at the service layer (`PostService`) via Laravel's own
`RateLimiter`, so it applies to any caller: the Livewire UI, a REST endpoint, a script —
not just whichever component happens to check first.

Breaching the limit surfaces as a normal validation error on the post form ("You're posting
too quickly — try again in a moment"), the same as an empty or over-length post — never an
unhandled exception.

It ships off by default rather than on, matching every other opt-in toggle in the Marque
suite (2FA, passkeys, `usarrs.manage_auth`) — an upgrade should never silently start
rejecting a legitimate user's fast-typing session. That default is only safe because
turning it on is a one-line config change you make *before* going live, not after.

## What's out of scope

Private messages, reputation, badges, signatures, polls, rich moderation queues, and
full-text search beyond a basic `LIKE`. The moment any of these is genuinely wanted, a
dedicated forum platform (Discourse, etc.) is the better answer — parley is not trying to
compete with one.

## Testing

```bash
composer test
```

## License

MIT
