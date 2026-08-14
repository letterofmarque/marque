<?php

declare(strict_types=1);

namespace Marque\SquidInk;

use Illuminate\Support\ServiceProvider;
use Marque\SquidInk\Contracts\Parser;
use Marque\SquidInk\Contracts\Renderer;
use Marque\SquidInk\Contracts\Shortcode;
use Marque\SquidInk\Document\Schema;
use Marque\SquidInk\Parsers\MarkdownParser;
use Marque\SquidInk\Renderers\HtmlRenderer;
use Marque\SquidInk\Renderers\PlainTextRenderer;
use Marque\SquidInk\Shortcodes\MediaInfoShortcode;
use Marque\SquidInk\Shortcodes\ShortcodeParser;
use Marque\SquidInk\Shortcodes\ShortcodeRegistry;
use Marque\SquidInk\Shortcodes\SpoilerShortcode;

class SquidInkServiceProvider extends ServiceProvider
{
    /**
     * Registered unless the consumer names their own in config. Listing them
     * here rather than in the published config keeps a stale published file
     * from silently dropping the defaults.
     */
    private const DEFAULT_PARSERS = [
        MarkdownParser::class,
    ];

    private const DEFAULT_RENDERERS = [
        HtmlRenderer::class,
        PlainTextRenderer::class,
    ];

    private const DEFAULT_SHORTCODES = [
        SpoilerShortcode::class,
        MediaInfoShortcode::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/squidink.php', 'squidink');

        $this->app->singleton(ShortcodeRegistry::class, function ($app): ShortcodeRegistry {
            $registry = new ShortcodeRegistry;

            $configured = $app['config']->get('squidink.shortcodes', []);
            $classes = $configured === [] ? self::DEFAULT_SHORTCODES : array_values($configured);

            foreach ($classes as $class) {
                $shortcode = $app->make($class);

                if ($shortcode instanceof Shortcode) {
                    $registry->register($shortcode);
                }
            }

            return $registry;
        });

        $this->app->singleton(SquidInk::class, function ($app): SquidInk {
            $config = $app['config']->get('squidink', []);

            $squidInk = new SquidInk(
                $this->schemaFrom($config['schema'] ?? []),
                $config['default_parser'] ?? 'markdown',
                new ShortcodeParser($app->make(ShortcodeRegistry::class)),
            );

            foreach ($this->resolve($config['parsers'] ?? [], self::DEFAULT_PARSERS) as $parser) {
                if ($parser instanceof Parser) {
                    $squidInk->registerParser($parser);
                }
            }

            foreach ($this->resolve($config['renderers'] ?? [], self::DEFAULT_RENDERERS) as $renderer) {
                if ($renderer instanceof Renderer) {
                    $squidInk->registerRenderer($renderer);
                }
            }

            return $squidInk;
        });

        $this->app->alias(SquidInk::class, 'squidink');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/squidink.php' => config_path('squidink.php'),
            ], 'squidink-config');
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaFrom(array $schema): Schema
    {
        return new Schema(
            array_values((array) ($schema['nodes'] ?? [])),
            array_values((array) ($schema['marks'] ?? [])),
        );
    }

    /**
     * @param  array<string, class-string>  $configured
     * @param  list<class-string>  $defaults
     * @return list<object>
     */
    private function resolve(array $configured, array $defaults): array
    {
        $classes = $configured === [] ? $defaults : array_values($configured);

        return array_map(
            fn (string $class): object => $this->app->make($class),
            $classes,
        );
    }
}
