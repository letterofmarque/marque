# marque/marque

**Start here.** One require, one command, a working tracker.

```bash
composer require marque/marque
php artisan marque:install
```

`marque:install` asks what you are building, installs exactly those packages,
and wires them into your app — routes, config, migrations, the User model, and
the Tailwind sources your templates need. It then checks the result actually
responds before telling you it worked.

## What this package is

A front door. It requires the four packages every Marque deployment needs:

| Package | Why it is always needed |
|---|---|
| `marque/trove` | Core models, services, contracts, registries |
| `marque/threepio` | BitTorrent protocol primitives |
| `marque/deck` | App shell — layout, navigation, shared components |
| `marque/usarrs` | Auth, users, roles, invites, admin |

Everything else is a choice, and `marque:install` makes it with you:

| Choice | Installs |
|---|---|
| Private tracker | `marque/bloodhound` + `marque/guise` |
| Public tracker | `marque/hound` + `marque/disguise` |
| REST API | `marque/cennad` |
| Forums and comments | `marque/parley` + `marque/squidink` |
| Content taxonomy | `marque/taxonomy` |
| Admin panel | `marque/skipper` |

### Why it does not just require everything

A private tracker must not have `marque/hound` installed. hound registers an
open `announce` route with no announce key and no authentication, and it does so
unconditionally — merely having it in `vendor/` puts a keyless announce endpoint
next to your authenticated one, and anybody who finds it can seed and leech
without ever touching ratio accounting.

Config cannot fix that safely, because then the security of your tracker is one
typo away from gone. So the package you should not have is never installed at
all.

## If you would rather wire it up yourself

You do not have to use the installer. Every package documents its own
installation, and requiring them individually works exactly as it always has —
see the [root README](https://github.com/letterofmarque/marque) for the
package-by-package path.

The installer exists because doing it by hand has a lot of steps, and missing
one of them produces an app that looks installed and is not.

## Re-running it

`marque:install` is safe to run again. It detects what is already in place and
offers only the gaps, which is also how you add a package (forums, say) after
the initial setup.

## Licence

MIT.
