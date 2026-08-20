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
- guise, disguise, usarrs, and parley all independently wrote an identical six-line `abstract class Component extends LivewireComponent` with a `<pkg>Layout()`/`<pkg>View()` pair reading `config('<pkg>.layout', 'ise::layouts.app')`. Four hand-copies of the same idea, no shared source. Still open — see below.

**The rule going forward: when a pattern gets written a second time, that is the signal to check whether it belongs one level down — as a real attachment point in the package everything already depends on (`ise` or `trove`) — not just documented more thoroughly where it sits.** Writing a better comparison of the existing copies doesn't stop a third copy from drifting; giving the third package something to `use` or `extend` does.

Concretely: the fix here is for `ise` (formerly `id` — see `packages/ise/README.md` for the rename) to ship `Marque\Ise\Livewire\HasConfiguredLayout` — the layout-and-view helper every full-page Livewire component package has been hand-writing — so guise/disguise/usarrs/parley use it instead of their own copies, and a fifth package gets it for free. Not built yet; tracked as job #10560 in Cornerstone.

This doesn't apply to *every* duplication — the Testbench/Vite test-layout-namespace fix (job #10561) is test scaffolding, not runtime code a package depends on, so it's a documentation fix, not a promotion. The test is: **does the third package need to `require`/`extend`/`use` something to get this for free, or does it just need to know the fact?** If the former, promote it into the shared package. If the latter, document it.

## Checklist for the next optional→required wiring

1. Composer: optional package is `suggest`, never `require`, on the consuming side.
2. Provider: guard registration with `class_exists()` on a class from the optional package — this narrower case is fine, since you control whether your own registration depends on the other package having booted first. For a check that decides whether to *render* the other package's UI (a view, a Livewire tag), use `app()->providerIsLoaded()` instead (Pattern 1).
3. Views: guard the *inclusion* of optional-package UI at the parent template, never inside the optional package's own view. If a view must render either way, own its markup rather than referencing the optional package's components.
4. Models: never add an optional package's trait to a model owned by a mandatory package. Give the optional package a service entry point that works against a bare `Model` (morph class + key), and let the *consuming, optional-aware* package do the wiring.
5. Before shipping the wiring: is this the *second* time this shape of integration has been built? If so, stop and check whether it belongs as an attachment point in `ise`/`trove` instead of a second hand-copy (Pattern 4).
