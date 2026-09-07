<?php

declare(strict_types=1);

namespace Marque\Taxonomy;

use Illuminate\Support\ServiceProvider;
use Marque\Taxonomy\Console\ValidateCommand;
use Marque\Taxonomy\Definitions\Loader;

class TaxonomyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/taxonomy.php', 'taxonomy');

        // Singleton rather than bound: the definitions are read from disk, and
        // re-reading them per resolution within one request would be pure
        // waste. Whether they should also be cached ACROSS requests is a
        // separate question — see the note in config/taxonomy.php.
        $this->app->singleton(Loader::class, function ($app): Loader {
            $config = $app['config'];

            return new Loader(
                packagePaths: $config->get('taxonomy.definitions.packages', []),
                appPath: $config->get('taxonomy.definitions.path'),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/taxonomy.php' => config_path('taxonomy.php'),
            ], 'taxonomy-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'taxonomy-migrations');
        }
    }
}
