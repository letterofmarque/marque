<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Parley\Livewire\Forum\CategoryIndex;
use Marque\Parley\Livewire\Forum\ThreadCreate;
use Marque\Parley\Livewire\Forum\ThreadIndex;
use Marque\Parley\Livewire\Forum\ThreadShow;

/*
|--------------------------------------------------------------------------
| Parley Forum Routes
|--------------------------------------------------------------------------
|
| Only ever loaded when config('parley.forum.enabled') is true — see
| ParleyServiceProvider::registerRoutes(). A comments-only deployment does
| not have these routes registered at all, so they 404 rather than merely
| lacking a link to them (the checkpoint's own "done when").
|
*/

Route::middleware(config('parley.forum.middleware', ['web']))
    ->prefix(config('parley.forum.prefix', 'forum'))
    ->name('parley.forum.')
    ->group(function () {
        Route::get('/', CategoryIndex::class)->name('categories.index');
        Route::get('categories/{category}', ThreadIndex::class)->name('categories.show');
        Route::get('categories/{category}/new', ThreadCreate::class)->name('threads.create');
        Route::get('threads/{thread}', ThreadShow::class)->name('threads.show');
    });
