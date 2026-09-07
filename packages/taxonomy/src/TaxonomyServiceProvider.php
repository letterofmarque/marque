<?php

declare(strict_types=1);

namespace Marque\Taxonomy;

use Illuminate\Support\ServiceProvider;
use Marque\Taxonomy\Console\UpgradeCommand;
use Marque\Taxonomy\Console\ValidateCommand;
use Marque\Taxonomy\Contracts\ClassifiesTorrents;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Services\Classifier;
use Marque\Trove\Models\Torrent;

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

        $this->app->bind(ClassifiesTorrents::class, Classifier::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerTorrentRelations();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateCommand::class,
                UpgradeCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/taxonomy.php' => config_path('taxonomy.php'),
            ], 'taxonomy-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'taxonomy-migrations');
        }
    }

    /**
     * Give trove's Torrent its taxonomy relations without trove knowing this
     * package exists.
     *
     * `resolveRelationUsing` registers a relation on a model class at runtime,
     * which is what keeps the dependency one-directional: taxonomy requires
     * trove, trove requires nothing. An install that never classifies anything
     * does not install this package, and `torrents` carries no taxonomy
     * columns and no taxonomy relations.
     *
     * This is the PHP-side seam Spec #83 identified as the one that actually
     * composes — a view-layer equivalent would not, because Blade resolves
     * components at compile time and a class_exists() guard around one still
     * throws.
     */
    protected function registerTorrentRelations(): void
    {
        Torrent::resolveRelationUsing(
            'taxonomyClassifications',
            fn (Torrent $torrent) => $torrent->hasMany(Classification::class, 'torrent_id'),
        );

        Torrent::resolveRelationUsing(
            'taxonomyFacetValues',
            fn (Torrent $torrent) => $torrent->belongsToMany(
                FacetValue::class,
                'taxonomy_assignments',
                'torrent_id',
                'facet_value_id',
            )->withTimestamps(),
        );
    }
}
