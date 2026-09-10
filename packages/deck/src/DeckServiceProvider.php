<?php

declare(strict_types=1);

namespace Marque\Deck;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Marque\Deck\View\Components\Navigation;

class DeckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/deck.php', 'deck');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'deck');

        Blade::anonymousComponentNamespace(__DIR__.'/../resources/views/components', 'deck');

        if (class_exists(Livewire::class)) {
            Livewire::component('deck-navigation', Navigation::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/deck.php' => config_path('deck.php'),
            ], 'deck-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/deck'),
            ], 'deck-views');
        }
    }
}
