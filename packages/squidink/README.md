# Marque SquidInk

Format-agnostic text pipeline for the [Marque](https://github.com/letterofmarque/marque)
tracker platform. Parse any supported input syntax into one document model, render it
safely to anything.

```
  Markdown ─┐                          ┌─ HTML (web)
  BBCode   ─┼─→  SquidInk document  ─→ ┼─ plain text (API, search indexing)
  your own ─┘         (AST)            └─ your own
```

## Why

Most tracker software picks one text format and makes everyone live with it. That is
the wrong call when the audiences genuinely differ: a technical tracker's users write
Markdown natively, and a scene tracker's users have been writing BBCode for twenty
years and are not going to stop.

SquidInk lets the site owner choose. Same platform, same storage, same rendering —
different input syntax. It is the text-layer version of the same thinking behind
Marque being database-agnostic and auth-agnostic.

The other half is safety. Rich text on a tracker is traditionally a pile of regular
expressions with an XSS history attached. SquidInk parses to a document model and
renders from it, and the model is a closed vocabulary — **if a parser cannot map
input to a declared node, the input cannot become output.** There is no "did we strip
every dangerous tag" question, because only declared node types exist.

## Installation

```bash
composer require marque/squidink
```

Publish the config if you want to change anything:

```bash
php artisan vendor:publish --tag=squidink-config
```

## Usage

```php
use Marque\SquidInk\SquidInk;

$squidInk = app(SquidInk::class);

// Parse and render in one call
$html = $squidInk->convert($source, 'bbcode', 'html');

// Or keep the document, render it more than once
$document = $squidInk->parse($source, 'markdown');
$html     = $squidInk->render($document, 'html');
$plain    = $squidInk->render($document, 'text');
```

Ships with two parsers (`markdown`, `bbcode`) and two renderers (`html`, `text`).

### Storing text

Store the **source text** and the **name of the parser that wrote it** — not rendered
HTML, and not a serialised AST.

```php
Schema::create('posts', function (Blueprint $table) {
    $table->text('body');
    $table->string('body_format')->default('markdown');
});
```

Source text stays greppable and diffable, and gives the author back exactly what they
typed when they edit. Recording the parser per record means content written under
different syntaxes coexists forever — a site can enable BBCode later, or import
legacy content, without rewriting a single existing row.

Rendered output is cached (see `config/squidink.php`), because text is read far more
often than it is written.

## The document model

Two kinds of thing, borrowed from ProseMirror:

- **Nodes** are structural — paragraph, heading, code block, list, image.
- **Marks** are inline annotations on text — bold, italic, link, colour, size.

Marks are a set on a text run rather than wrapper nodes, which keeps the tree shallow
and makes "bold spanning two links" representable without contortions.

```php
use Marque\SquidInk\Document\Nodes\{Document, Paragraph, Text};
use Marque\SquidInk\Document\Marks\Bold;

$document = new Document;
$document->append((new Paragraph)->append(new Text('hello', [new Bold])));
```

### The schema is the security model

A `Schema` declares which nodes and marks may exist. Narrowing it is how you restrict
what users can write:

```php
use Marque\SquidInk\Document\Schema;

// Everything the package supports
$schema = Schema::permissive();

// Text, emphasis and links only — sensible for short comment fields
$schema = Schema::minimal();

// Or name exactly what you want
$schema = new Schema(
    nodes: ['document', 'paragraph', 'text', 'block_quote'],
    marks: ['bold', 'italic', 'link'],
);
```

Content a schema disallows is **degraded, not deleted**: a disallowed node is
unwrapped and its children kept, and a disallowed mark is dropped from the text it
annotated. Narrowing a schema costs a post its colours, never its words.

## Extending

The parsers, renderers and shortcodes that ship are registered through exactly the
same API yours would be. There is no privileged built-in.

### Writing a parser

Implement `Parser`, map your syntax onto SquidInk nodes directly, and run the result
through the schema. Never convert to another syntax first — that just picks a winner
by a different route, and loses whatever the intermediate format cannot express.

```php
use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Document\Nodes\Document;
use Marque\SquidInk\Document\Schema;

final class TextileParser implements Parser
{
    public function name(): string
    {
        return 'textile';   // Stored in your body_format column — keep it stable
    }

    public function parse(string $source, Schema $schema): Document
    {
        $document = new Document;

        // ... build nodes ...

        // Build permissively, then filter once — rather than checking the
        // schema at every step.
        $schema->filter($document);

        return $document;
    }
}
```

**A parser must never throw on malformed input.** Whatever it cannot understand
becomes literal text. Register it in `config/squidink.php`, or:

```php
app(SquidInk::class)->registerParser(new TextileParser);
```

### Giving your parser a toolbar

Implement `DescribesSyntax` and the editor builds a toolbar for your syntax without
knowing anything about it:

```php
use Marque\SquidInk\Contracts\DescribesSyntax;
use Marque\SquidInk\Editor\Insertion;

final class TextileParser implements Parser, DescribesSyntax
{
    public function actions(): array
    {
        return ['bold', 'italic', 'link'];
    }

    public function insertion(string $action): ?Insertion
    {
        return match ($action) {
            'bold' => new Insertion('bold', 'B', '*', '*', 'text'),
            'italic' => new Insertion('italic', 'I', '_', '_', 'text'),
            'link' => new Insertion('link', 'Link', '"', '":https://', 'link text'),
            default => null,   // Declining is normal — the button is simply omitted
        };
    }
}
```

Actions are named by intent, so a toolbar can offer a consistent set of buttons
across syntaxes that spell them differently. This is optional: a parser without it
still works, and just gets a plain textarea.

### Writing a renderer

```php
use Marque\SquidInk\Contracts\Renderer;
use Marque\SquidInk\Document\Node;

final class MarkdownRenderer implements Renderer
{
    public function name(): string
    {
        return 'markdown';
    }

    public function render(Node $node): string
    {
        // Switch on $node->type(); recurse through $node->children()
    }
}
```

### Registering a shortcode

Shortcodes are platform-aware content — a spoiler, a MediaInfo dump, a torrent status
pill. They are written with braces, `{spoiler}...{/spoiler}`, deliberately not square
brackets so they are unambiguous alongside BBCode.

```php
use Marque\SquidInk\Contracts\Shortcode;
use Marque\SquidInk\Document\Nodes\Shortcode as ShortcodeNode;

final class TorrentShortcode implements Shortcode
{
    public function name(): string
    {
        return 'torrent';
    }

    public function isPaired(): bool
    {
        return false;
    }

    public function render(ShortcodeNode $node, string $format, callable $renderChildren): ?string
    {
        if ($format !== 'html') {
            return null;   // Decline; the renderer falls back to plain children
        }

        return view('torrent-pill', ['id' => $node->attribute('id')])->render();
    }
}
```

Add it to the `shortcodes` array in `config/squidink.php`. Unregistered shortcodes
render as literal text rather than erroring, so content written on a site with more
shortcodes installed still reads sensibly here.

Ships with `spoiler` and `mediainfo`.

## The editor

A drop-in editor whose toolbar is built from whatever the active parser says its
syntax is:

```blade
<x-squidink::editor name="body" :value="$post->body" parser="bbcode" label="Description" />
```

Publish the JavaScript that powers the toolbar:

```bash
php artisan vendor:publish --tag=squidink-assets
```

```blade
<script src="{{ asset('vendor/squidink/squidink.js') }}"></script>
```

Without that script the component is still a working `<textarea>` that posts, saves
and renders — the toolbar is progressive enhancement and stays hidden until the
script wires it up.

For live preview, use the Livewire component instead:

```blade
<livewire:squidink-editor name="body" parser="markdown" />
```

Preview renders server-side through the real `HtmlRenderer`, so what the author sees
is what the post becomes. A JavaScript preview would be a second implementation that
drifts from the first, which is the whole failure this package avoids.

Livewire is optional. So is `marque/id` — the editor owns its own markup so a text
pipeline never drags a UI package in behind it. To restyle, publish the views:

```bash
php artisan vendor:publish --tag=squidink-views
```

## Security

- **Closed vocabulary.** A parser cannot produce a node the schema does not declare,
  so unsupported or hostile input cannot become unexpected output.
- **Scheme filtering on every link and image**, in the mark constructor rather than in
  each parser — so a new parser inherits it and cannot forget it. `javascript:`,
  `data:` and `vbscript:` are refused, and the text renders unlinked rather than
  vanishing.
- **Colours and sizes are validated against fixed sets**, never passed through to a
  style attribute, so neither can be a CSS injection vector.
- **Raw HTML in source is dropped** by the Markdown parser and escaped by the BBCode
  parser. Both are inert.
- **Malformed input degrades, never throws.** Unclosed and unknown tags become the
  literal text the author typed.
- **Code blocks are byte-exact.** NFO art and MediaInfo dumps survive intact, which is
  the one place lossy handling genuinely hurts on a tracker.

The renderer does no sanitising, deliberately: only nodes the schema permitted reach
it, and hrefs and colours were validated when their marks were constructed. What
remains is escaping.

## Testing

```bash
composer test
```

## License

MIT
