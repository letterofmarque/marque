<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Usarrs\Livewire\Admin\UserIndex;
use Marque\Usarrs\Livewire\Admin\UserShow;
use Marque\Usarrs\Livewire\Dashboard\Index as DashboardIndex;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Livewire\Invite\InviteIndex;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;
use Marque\Usarrs\Livewire\Profile\Edit as ProfileEdit;
use Marque\Usarrs\Livewire\Profile\Show as ProfileShow;

/*
|--------------------------------------------------------------------------
| Usarrs Web Routes
|--------------------------------------------------------------------------
*/

// Profile routes (authenticated)
Route::middleware(config('usarrs.auth_middleware', ['web', 'auth']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        // Registered unconditionally, on every install shape. Gating this on
        // whether any panel exists would make the route table install-dependent
        // — route('dashboard.index') safe on one install and fatal on another,
        // so every consumer linking to it would need a Route::has() guard
        // (Spec #118).
        Route::get('dashboard', DashboardIndex::class)->name('dashboard.index');

        Route::get('profile', ProfileShow::class)->name('profile.show');
        Route::get('profile/edit', ProfileEdit::class)->name('profile.edit');
        Route::get('profile/stats', AnnounceKeyManagement::class)->name('profile.stats');

        Route::get('invites', InviteIndex::class)->name('invites.index');
        Route::get('invites/create', InviteCreate::class)->name('invites.create');
    });

// Admin routes
Route::middleware(config('usarrs.admin_middleware', ['web', 'auth', 'verified']))
    ->prefix(config('usarrs.prefix', ''))
    ->group(function () {
        Route::get('admin/users', UserIndex::class)->name('admin.users.index');
        Route::get('admin/users/{user}', UserShow::class)->name('admin.users.show');
    });
