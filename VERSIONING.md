# Versioning Policy

Marque packages follow [Semantic Versioning](https://semver.org). Each package in
`packages/` is versioned and released independently — `marque/guise` being at 3.4.0
while `marque/threepio` sits at 3.0.1 is normal and expected.

This document is the contract. If you pin a constraint based on what is written here
and we break it, that is a bug in our release, not in your constraint.

## Choosing a constraint

| You want | Write | You get |
|----------|-------|---------|
| Conservative — patches only | `~3.0.0` | 3.0.1, 3.0.2 … never 3.1.0 |
| **Recommended** — features, no breakage | `^3.0` | 3.1.0, 3.4.2 … never 4.0.0 |
| Pre-release features, opt-in | `^3.1@beta` | 3.1.0-beta1 and later |
| Bleeding edge, no guarantees | `dev-main` | whatever is on main right now |

**`^3.0` is the right choice for almost everyone**, and it is what `composer require`
gives you by default. You receive new features and bug fixes automatically, and never
a breaking change without an explicit major bump on your side.

We cut minor releases **frequently** — a feature that is merged and green is usually
tagged the same day. You should not have to track `dev-main` to get a finished feature.

`dev-main` is for people developing against Marque itself. It is not a support channel,
it can break at any time, and no upgrade notes are written for it.

## What each bump means

### PATCH — 3.0.0 → 3.0.1

Backward compatible bug fixes. Safe to take without reading anything.

- Bug fixes that do not change documented behaviour
- Performance work with no API change
- Internal refactoring
- Dependency constraint widening (e.g. also allowing a new Laravel minor)
- Documentation and test-only changes

### MINOR — 3.0.0 → 3.1.0

New functionality, backward compatible. Safe to take, but read the changelog — there may
be new config keys or opt-in behaviour worth knowing about.

- New features, services, Livewire components, or Blade components
- New optional parameters (existing calls keep working)
- New config keys **with defaults that preserve current behaviour**
- New database columns that are nullable or defaulted
- New routes
- Deprecating something (announcing it — removal happens in the next major)

### MAJOR — 3.0.0 → 4.0.0

Anything that can break a working installation. Never taken automatically; requires
changing your constraint, and ships with upgrade notes.

- Removing or renaming any public class, method, service, contract, route, config key,
  event, or published view
- Changing a method signature in a way that breaks existing calls
- Changing default behaviour (a config default flipping, different response shape)
- Raising the PHP or Laravel floor
- Database migrations that drop or rename columns, or require data transformation
- Changing the required set of `marque/*` packages
- Removing a published Blade component or changing its rendered markup in a way that
  breaks consumers who have not published their own copy

## The grey areas

These are the calls that are genuinely ambiguous. Written down so they are decided in
advance rather than under release pressure.

**Blade markup changes in frontend packages.** The published views (`--tag=*-views`) are
explicitly an override point — consumers are expected to publish and customise them. But
consumers who *have not* published them get whatever we ship. Rule: changing the internal
markup or Tailwind classes of a component is **minor**. Removing a component, renaming it,
or changing its props is **major**. Swapping the entire UI foundation is **major** —
it changes what a consumer must install.

**Adding a required dependency.** If a package starts requiring another `marque/*` package
it did not before, that is **major** — it changes the install set.

**Widening a framework constraint** (e.g. `^13.0` → `^13.0|^14.0`) is **minor**, because
nothing breaks for existing users. **Raising the floor** (dropping `^13.0`) is **major**.

**New database columns.** Nullable or defaulted is **minor**. Anything requiring
backfill or making an existing column stricter is **major**.

**Bug fix that changes behaviour people may rely on.** If the documented behaviour was
wrong, fixing it is **patch**. If the documented behaviour changes, it is **major**,
regardless of how small the fix looks.

## Pre-releases

When a feature is finished but wants real-world soak time before it is blessed, we tag a
beta rather than sitting on it:

```
3.1.0-beta1  →  3.1.0-beta2  →  3.1.0
```

Opt in with `"marque/guise": "^3.1@beta"`. Betas may change before the stable tag. They
are still tagged releases, so your lock file records exactly what you took — which is why
we prefer them to telling people to track a branch.

## Support

The current major receives patches and features. When a new major ships, the previous
major receives **security and data-loss fixes only, for six months**.

There is no long-term support release. This is a solo-maintained project and pretending
otherwise would be dishonest.

## Upgrade notes

Every package keeps its own `CHANGELOG.md` — every release, patch included, gets an
entry there. That's the record of what changed in *that package*.

Each entry opens with a one-sentence summary as a blockquote, directly under the version
heading:

```markdown
## [4.1.0] — 2026-09-02

> Adds an opt-in announce log for investigating cheating reports and disputed ratios.

### Added
...
```

Those summaries are collected into the root [CHANGELOG.md](CHANGELOG.md) — one flat,
dated feed of every release across every package, newest first. It is generated by
`bin/build-changelog`, which `bin/release` runs on every release, so it cannot drift.
Releasing without a summary line is refused rather than left blank.

Majors that are big enough to need explaining — a renamed package, a floor raise, a
column rename — also get a plain-language write-up in [RELEASES.md](RELEASES.md), which
groups whatever package versions moved together for one real reason and says who's
affected and what to do, without assuming you know the codebase. Not every major package
bump gets its own suite release entry — only ones where the "why" and "what do I do"
aren't obvious from the changelog alone.
