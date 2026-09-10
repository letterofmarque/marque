# Package Integration

*How optional packages wire into packages that can't depend on them back. No prior doc covered this — written when parley (optional) needed to attach to Torrent (owned by trove, mandatory) without trove ever requiring parley.*

## The shape of the problem

Marque's mandatory/optional split (see `docs/why.md`, "Why 9 Packages") means a mandatory package can never `require` an optional one — `trove` has to work in an API-only deployment that never installs `parley`, `usarrs`, or anything else. But optional packages routinely want to *add behaviour to* a model or view that a mandatory or another package owns: parley wants a comment thread on `Torrent`, and the reverse direction (a frontend rendering an optional package's UI) has the same problem one level up.

Every existing case in the suite solves this the same way, ad hoc, in provider/view code, without it being written down anywhere. This doc writes it down.

## Pattern 1: detect at boot, don't require

A service provider that *wants* to use another package if present, but must still boot cleanly without it, guards registration with `class_exists()`:

```php
// guise/src/GuiseServiceProvider.php (illustrative)
public function boot(): void
{
    if (class_exists(\Marque\Parley\ParleyServiceProvider::class)) {
        $this->registerParleyIntegration();
    }
}
```

This is how `disguise` already guards Livewire itself (`class_exists(\Livewire\Livewire::class)`), and how `parley` guards Livewire registration too. Composer-wise, the optional package is a `suggest`, never a `require`, in the consuming package's `composer.json`.

**`class_exists()` answers "is it on the autoload path", not "is it wired into this app" — those are different questions when a `require-dev` install is in play.** guise's own test suite hit this directly: it added `marque/parley` as `require-dev` (so its test app can exercise the integration), which puts `CommentThread` on the autoload path and makes `class_exists()` return `true` — but the test app's `TestCase::getPackageProviders()` never lists `ParleyServiceProvider`, so the `parley-comment-thread` Livewire tag was never registered. `class_exists()` said yes, the tag resolution failed anyway, and the existing torrent-show tests broke.

**For a check that decides whether to render another package's UI (a Livewire tag, an `@include`), check the provider, not the class:**

```php
@if (app()->providerIsLoaded(\Marque\Parley\ParleyServiceProvider::class))
    <livewire:parley-comment-thread :subject="$torrent" />
@endif
```

`class_exists()` is still correct for the narrower case in the example above — deciding whether to *register* something in your own provider's `boot()`, where you control whether that registration itself depends on the other package having booted first (providers load in a defined order; if yours might run before the optional one, prefer `providerIsLoaded()` there too, or hook a later lifecycle event).

## Pattern 2: the Blade compile-time gotcha

`class_exists()` guards **do not work around Blade component tags** (`<x-vendor::tag>` or `<livewire:tag>`). Blade resolves components when the view is *compiled*, not when it runs — so a view containing a reference to an absent package's component tag fails to compile regardless of runtime guards around it.

squidink's `resources/views/components/editor.blade.php` hit this directly: it deliberately owns its markup by hand rather than referencing any `id::` component, with the reasoning recorded inline. The rule of thumb:

- A view that must render whether or not an optional package is installed **owns its markup**, styled to match by hand, not by referencing the optional package's components.
- A view that only renders when the optional package IS present (guarded by a runtime `class_exists()` check *around the whole `@include`/`<livewire:>` line, at the parent template level*, not inside the child) can safely use that package's own components, since the child view only ever compiles in an app that has it installed.

In practice: put the `@if (class_exists(...))` in the *page* that conditionally pulls in the optional feature, never inside the optional feature's own view.

## Pattern 3: attaching to a model you don't own

This is the case parley introduced and the reason this doc exists.

**Don't** make the owned model (`Torrent`) take the optional package's trait directly — that would require the *owning* package (`trove`) to depend on the optional one (`parley`) just to import the trait, which is exactly the hard dependency the mandatory/optional split exists to avoid. PHP has no conditional `use Trait;`.

**Do** keep the model completely untouched, and give the optional package's service layer an entry point that works against a bare model + its morph type, instead of requiring the convenience trait:

```php
// parley: ThreadServiceInterface
public function forSubject(Model $subject, Authenticatable $user): Thread;

// parley: ThreadService::forSubject() requires HasThreads (the convenience path,
// for consumers that own their model and want `use HasThreads;`)

// The bare-model path a consumer without HasThreads uses instead:
public function threadFor(Model $subject, Authenticatable $user): Thread
{
    return Thread::firstOrCreate([
        'threadable_type' => $subject->getMorphClass(),
        'threadable_id'   => $subject->getKey(),
    ], [
        'user_id' => $user->getAuthIdentifier(),
    ]);
}
```

Then the integration lives entirely on the *consuming, optional-aware* side (`guise`), never touching `trove`:

```php
// guise: wherever it mounts the comment thread for a Torrent
if (class_exists(\Marque\Parley\Contracts\ThreadServiceInterface::class)) {
    $thread = app(ThreadServiceInterface::class)->threadFor($torrent, auth()->user());
}
```

`Torrent` never imports, extends, or knows about `HasThreads`. `trove`'s `composer.json` gains no new dependency. `guise` is the only package that knows both sides exist, and it already `suggest`s parley rather than requiring it.

## Pattern 4: the second instance is a promotion trigger, not a documentation trigger

The 2026-08-20 cross-package review found the same pattern hand-copied across packages more than once, each copy drifting slightly because there was nothing to copy *from* — only sibling packages to reverse-engineer:

- `providerIsLoaded()` optional-detection existed correctly in guise→parley, but `id` (now `ise`)'s own nav component (deciding whether to render guise/disguise/usarrs nav items) still used `class_exists()` — the exact anti-pattern this doc's Pattern 1 exists to rule out. The package other packages are meant to copy from was itself the odd one out. **Fixed** as part of the `id`→`ise` rename (2026-08-20) — `Navigation::hasProvider()` now uses `providerIsLoaded()`.
- guise, disguise, usarrs, and parley all independently wrote an identical six-line `abstract class Component extends LivewireComponent` with a `<pkg>Layout()`/`<pkg>View()` pair reading `config('<pkg>.layout', 'deck::layouts.app')`. Four hand-copies of the same idea, no shared source. Still open — see below.

**The rule going forward: when a pattern gets written a second time, that is the signal to check whether it belongs one level down — as a real attachment point in the package everything already depends on (`ise` or `trove`) — not just documented more thoroughly where it sits.** Writing a better comparison of the existing copies doesn't stop a third copy from drifting; giving the third package something to `use` or `extend` does.

Concretely: the fix here is for `deck` (formerly `ise`, and `id` before that — see `packages/deck/README.md` for the renames) to ship `Marque\Deck\Livewire\HasConfiguredLayout` — the layout-and-view helper every full-page Livewire component package has been hand-writing — so guise/disguise/usarrs/parley use it instead of their own copies, and a fifth package gets it for free. Not built yet; tracked as job #10560 in Cornerstone.

This doesn't apply to *every* duplication — the Testbench/Vite test-layout-namespace fix (job #10561) is test scaffolding, not runtime code a package depends on, so it's a documentation fix, not a promotion. The test is: **does the third package need to `require`/`extend`/`use` something to get this for free, or does it just need to know the fact?** If the former, promote it into the shared package. If the latter, document it.

## Pattern 5: a registry in `trove`, when packages contribute to a shell they don't own

This is Pattern 4 carried out. The navigation case it names above — `deck`'s nav component
holding a hardcoded `if` per package, each naming a consumer's service provider as a string
literal — was fixed by promotion rather than by better documentation, and the same mechanism
solved the admin-panel problem alongside it (Spec #108, Build #101).

**The problem shape:** a package wants to contribute *something* to a shell — a nav entry, an
admin screen — and the shell must not know that package exists. The naive version has the
shell enumerate its consumers, which inverts the dependency, makes third-party contribution
impossible, and means adding an entry requires editing and releasing a *different* package
than the one gaining it.

**The mechanism:** a plain-PHP registry in `trove`, under `Marque\Trove\Registry\`.

```php
// The contributing package, from its own service provider's boot():
$this->app->make(AdminScreenRegistry::class)->register(new AdminScreen(
    identifier: 'client-whitelist',
    label: 'Client Whitelist',
    component: 'my-package-client-whitelist',
    path: 'admin/clients',
    minimumRole: Role::Moderator,
    group: 'Tracker',
));
```

The contributing package depends on **trove alone** — which every deployment already installs
— and never on the shell that renders the entry. Install `skipper` and the screen appears;
leave it out and the registration is never read, which costs nothing. That one-way arrow is
the whole point: it is what makes a third-party package's screen possible at all.

### Why the registries live in trove and not in the renderer

`trove` is mandatory and has no view layer. Putting the contract there costs it nothing —
no `illuminate/view`, no Livewire, no new dependency of any kind — and it is the only
package a contributor can rely on being present. Putting the contract in `skipper` or `deck`
instead would force every contributing package to depend on the renderer, i.e. exactly the
coupling the arrangement exists to avoid.

### Two registries, not one, and no shared base class

`NavRegistry` and `AdminScreenRegistry` look similar and behave differently:

- A **nav item** is evaluated per request against the current user, with an arbitrary
  visibility closure — "show Invites only if this user has any" is a query, not a rank.
- An **admin screen** must be enumerable **without** a user, because the route table is built
  from it during `booted()`.

One contract spanning both would mean a visibility callback invoked in two very different
contexts. They also share almost nothing worth extracting — hold an array, push, return
filtered — so there is no base class. Per Pattern 4's own test, a third registry is the
trigger to look for a shared parent, not the second.

### Things that bit, and are worth not rediscovering

**Livewire serialises public component properties into a `wire:snapshot` attribute in the page
source.** Two consequences: a registry entry carrying a `Closure` cannot be a public property
at all (it throws `Property type not supported`), and anything filtered only in the template
still ships to the browser. Flatten to primitives in `mount()`, and filter there too.

**Register routes in `booted()`, never in `boot()`.** A `boot()`-time walk of a registry
misses every package whose provider boots later — silently, with no error. `booted()` fires
once every provider has booted and is still early enough for `route:cache` to serialise what
it registers.

**Never generate a route over a URI another package already bound.** skipper generating
`admin/users` on top of usarrs' own route silently *replaced* `admin.users.index` — published
API vanishing with no error. Collect the bound URIs first and skip any path already claimed;
the contributing package's route is canonical and the shell links to it.

**Testbench cannot verify any of the three above.** The app is already booted before a test
body runs, so `booted()` has fired and `route:cache` re-bootstraps a fresh application
without Testbench's dynamically-injected providers. Route behaviour needs a real Laravel app;
the package suite proves the registry, not the routing.

### The contract is public API

`Marque\Trove\Registry\` follows semver on trove from 4.x onward — third-party packages are
expected to register against it, and CP #588's nav test exercises a non-Marque fixture
provider to keep that path honest. What is *not* promised is how any given shell renders the
result; skipper's views and grouping version with skipper.

Committing this early was safe precisely because of the trove/renderer split: the expensive
promise (the contract) and the volatile code (the panel) sit in different packages. Measured
before deciding — the contract changed exactly once after creation, additively, at its first
tenant, and its second tenant needed nothing (Spec #108 OQ2).

## Checklist for the next optional→required wiring

1. Composer: optional package is `suggest`, never `require`, on the consuming side.
2. Provider: guard registration with `class_exists()` on a class from the optional package — this narrower case is fine, since you control whether your own registration depends on the other package having booted first. For a check that decides whether to *render* the other package's UI (a view, a Livewire tag), use `app()->providerIsLoaded()` instead (Pattern 1).
3. Views: guard the *inclusion* of optional-package UI at the parent template, never inside the optional package's own view. If a view must render either way, own its markup rather than referencing the optional package's components.
4. Models: never add an optional package's trait to a model owned by a mandatory package. Give the optional package a service entry point that works against a bare `Model` (morph class + key), and let the *consuming, optional-aware* package do the wiring.
5. Before shipping the wiring: is this the *second* time this shape of integration has been built? If so, stop and check whether it belongs as an attachment point in `deck`/`trove` instead of a second hand-copy (Pattern 4).
6. Contributing a nav entry or an admin screen? Don't invent a mechanism — register with the trove registries (Pattern 5). Depend on trove, never on the renderer.
