# Changelog

All notable changes to `marque/squidink` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). Versioning
follows the suite's [VERSIONING.md](../../VERSIONING.md).

## [1.1.3] — 2026-10-05

> Narrowing a schema no longer deletes code blocks or image alt text, runs blocks
> together, or lets shortcodes past it, matching what the README promised from 1.0. Only apps with
> a narrowed schema see a difference.

### Fixed

- **A disallowed code block or image no longer vanishes with its content.** Unwrapping
  kept a disallowed node's children, but a code block's text and an image's alt text
  aren't children, so both were deleted outright. Narrowing a schema should cost
  formatting, never words. A disallowed code block now becomes a paragraph of its lines,
  code-marked and joined with hard breaks where the schema allows those. A disallowed
  image becomes its alt text, and one with no alt still disappears. A disallowed
  quote keeps its attribution (`[quote=Bob]`) as a "Bob:" paragraph ahead of its
  content, which is how the plain-text renderer already shows it (#10936).
- **Shortcodes obey the schema.** The shortcode pass ran after the parser's schema
  filter, so `{spoiler}` rendered as `<details>` even under a schema without
  `shortcode`. The schema now filters again after the pass, and a disallowed shortcode
  unwraps to its content (#10936). An unpaired shortcode has no content, so it is
  removed. If you narrowed `schema.nodes` and still want shortcodes, add
  `'shortcode'` to the list.
- **Unwrapped blocks no longer run into each other.** Under a narrowed schema, a heading
  followed by a list rendered as `Heading wordsitem oneitem two`, in HTML and in plain
  text. A disallowed hard break joined its two lines the same way. A disallowed block's
  inline content now becomes its own paragraph, and a disallowed hard break becomes a
  newline. Found by the release read-through.

## [1.1.2] — 2026-10-04

> Raises the `league/commonmark` floor past two advisories. squidink itself was not
> exposed to either.

### Security

- **`league/commonmark` constraint raised from `^2.10` to `^2.10.2`.** Versions up to
  2.10.1 carry two advisories: a DisallowedRawHtml bypass when a disallowed tag name
  ends the raw-HTML literal ([GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9),
  medium) and quadratic-time parsing in the GFM Table extension
  ([GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8), high).

  Neither reaches squidink's Markdown parser. It loads only the CommonMark core and
  Strikethrough extensions, not DisallowedRawHtml or Table. It strips raw HTML at
  parse time, and its own AST mapping drops any HTML node that remains. The floor
  moves anyway: Laravel and other packages in your app use `league/commonmark` too,
  some with the GFM extensions, and this keeps a vulnerable version from resolving.

  New tests pin the behaviour down: raw HTML in every shape the advisory describes is
  dropped or escaped, never emitted, and GFM table syntax stays a paragraph of text.

### Fixed

- **The README said rendered output is cached. It is not.** Nothing reads the
  `cache.*` keys, and every read renders afresh. The README and config now describe
  the cache as planned (#10811). Likewise, `image_resolver` and the `Image` node
  described a marque/stow resolver as if it could be installed; no such package exists
  yet, and every image reference renders as-is (#10814).
- **Four more README and config claims corrected**, found by the release read-through.
  Narrowing a schema does delete a disallowed code block or image, content included.
  Shortcodes are not schema-filtered. Image sources are scheme-filtered in the HTML
  renderer, not a mark constructor. The parser list offered `"plain"`, which does not
  exist. The README also now warns that listing parsers or shortcodes in config
  replaces the defaults. The code fixes for the first two are #10936.

  Documentation only: behaviour is unchanged.

## [1.1.1] — 2026-09-11

> Comment-only: two references to the renamed shell package.

### Changed

- Two inline comments in the editor views referred to `marque/ise`, now
  `marque/deck`. No code, markup or behaviour changed.

  squidink does not depend on the shell in either direction — its editor
  deliberately owns its markup rather than referencing shell components, because
  Blade resolves component tags at compile time and a `class_exists()` guard
  cannot save a view that names an absent package's tag.

## [1.1.0] — 2026-09-04

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

## [1.0.0] — 2026-08-15

> First release — Markdown and BBCode in, HTML and plain text out, through one shared document model.

Initial release. Markdown and BBCode in, HTML and plain text out, through one shared
document model. See the [package README](README.md) for what it does.
