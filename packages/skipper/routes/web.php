<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Skipper\Livewire\Panel;

/*
|--------------------------------------------------------------------------
| Skipper Routes
|--------------------------------------------------------------------------
|
| The panel index. Resolution of the screens packages register — the catch-all
| and its generated aliases — is added in CP6; this file is the front door.
|
*/

Route::middleware(config('skipper.middleware', ['web', 'auth', 'verified']))
    ->prefix(config('skipper.prefix', 'admin'))
    ->group(function () {
        Route::get('/', Panel::class)->name('admin.index');
    });
