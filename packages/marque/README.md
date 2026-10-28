# marque/marque

**Start here.** One require and one command wire a working tracker, once you have a
mailer, Redis and built assets (see below).

```bash
composer require marque/marque
php artisan marque:install
```

`marque:install` asks what you are building, installs exactly those packages,
and wires them into your app — routes, config, migrations, the User model, and
the Tailwind sources your templates need. It then checks the result actually
responds before telling you it worked.

Build your front-end assets (`npm install && npm run build`) **before** running it,
because the final check loads real pages and they fail without a built manifest. Build
them again **after**, because it adds the packages' Tailwind sources and their styles
only appear once rebuilt. The installer doesn't run npm for you yet (#10796).

## What this package is

A front door. It brings the four packages every Marque deployment needs:

| Package | Why it is always needed |
|---|---|
| `marque/trove` | Core models, services, contracts, registries |
| `marque/threepio` | BitTorrent protocol primitives (through trove, which requires it) |
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
| Staff management (on by default for a private tracker) | `marque/bosun` |

If you let it create an admin account, that account is the tracker's **owner**: an admin
who can also grant and remove Admin, which nobody else can. It's sent a link to set its
password. On an install that already has admins but no owner, the installer says so; make
one with `php artisan usarrs:owner <email>`.

**A new private tracker starts with hit-and-run on.** bloodhound ships its hit-and-run
rule off, so upgrading an existing tracker changes nothing for its members. A tracker built
from nothing has no members yet to surprise. So on a first install, `marque:install` writes
`BLOODHOUND_HIT_AND_RUN=true` to `.env` and says so: each torrent a member downloads is owed
72 hours of seeding, or a 1:1 ratio on it. A re-run, a public tracker, or a `.env` that
already sets the key is left alone. Set it to `false` to turn the rule off. See bloodhound's
README, "Hit-and-run".

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

`marque:install` is safe to run again, and that's how you add a package (forums,
say) after the initial setup. It reads what composer already has installed:

- **The tracker type isn't asked again.** A public tracker stays public and a
  private one stays private. Pressing Enter can't add the other tracker beside it.
- **Extras already installed aren't offered**, and only what's missing is
  composer-required.
- **An app holding both tracker types is refused** before any question, because
  hound's keyless announce endpoint beside bloodhound's authenticated one is exactly
  what the installer exists to prevent. Remove one with `composer remove`, then run
  it again.

The later steps (stylesheet, User model, home page, admin) skip work that's
already done.

## Answering the questions ahead of time (preseed)

Like Debian's preseeding, a file can answer the installer's questions, so an install can
be repeated exactly, run unattended in CI, or kept beside a deployment as the record of
how it was set up:

```bash
php artisan marque:install --preseed=marque.yml
php artisan marque:install --preseed=marque.yml --no-interaction
```

A question the file answers isn't asked, and the run says what it answered. One it leaves
out is asked as normal. With `--no-interaction` there's nobody to ask, so the file must
answer every question; one that doesn't is refused before anything is changed, naming
what's missing.

The installer refuses the whole file, before it changes anything, if it has a key it
doesn't recognise or a value of the wrong kind. A misspelt key that was quietly ignored
would become a default nobody chose. Every answer, with a comment for each:

```yaml
# marque.yml: how this tracker was installed.

tracker: private          # private or public. On a re-run it must match what's installed

extras:                   # true installs it, false leaves it out
  api: false              # marque/cennad, the REST API
  forums: true            # marque/parley, forums and torrent comments
  taxonomy: false         # marque/taxonomy, the content-type engine
  admin_panel: true       # marque/skipper, the admin panel
  staff_management: true  # marque/bosun, the staff action log and Staff screens

site_name: "Ten Yard"     # APP_NAME. Empty keeps the current name

admin:                    # the owner account, sent a link to set its password
  name: Ada
  email: ada@example.com
# admin: false            # or create no account

stylesheet: true          # add the packages' template paths to resources/css/app.css
user_model: true          # add HasRoles, UserInterface and MustVerifyEmail to app/Models/User.php
home_page: dashboard      # dashboard, splash, torrents, profile-stats or leave
```

An extra that's already installed isn't asked about, whatever the file says.

## Licence

MIT. See [LICENSE](LICENSE).
