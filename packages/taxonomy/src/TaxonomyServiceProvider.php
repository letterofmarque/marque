<?php

declare(strict_types=1);

namespace Marque\Taxonomy;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Taxonomy\Console\UpgradeCommand;
use Marque\Taxonomy\Console\ValidateCommand;
use Marque\Taxonomy\Contracts\ClassifiesTorrents;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Livewire\ClassifierForm;
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'taxonomy');

        $this->registerTorrentRelations();

        // Guarded on a PHP class, which composes — as against a Blade
        // component guard, which does not, because Blade resolves components
        // at compile time (Spec #83). livewire/livewire is a `suggest` rather
        // than a `require`: taxonomy is an engine, and an API-only install
        // (trove + threepio + bloodhound + cennad) must be able to classify
        // without pulling in a frontend stack.
        if (class_exists(Livewire::class)) {
            Livewire::component('taxonomy-classifier-form', ClassifierForm::class);
        }

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

            // Consumers publish and restyle rather than fork: the shipped
            // views are deliberately unstyled plain Blade.
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/taxonomy'),
            ], 'taxonomy-views');
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
