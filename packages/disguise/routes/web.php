<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Disguise\Http\Controllers\TorrentDownloadController;
use Marque\Disguise\Livewire\Torrent\Edit as TorrentEdit;
use Marque\Disguise\Livewire\Torrent\Index as TorrentIndex;
use Marque\Disguise\Livewire\Torrent\Show as TorrentShow;
use Marque\Disguise\Livewire\Torrent\Upload as TorrentUpload;

/*
|--------------------------------------------------------------------------
| Disguise Web Routes
|--------------------------------------------------------------------------
|
| Public-facing routes for the Marque Livewire frontend.
| Browse and view routes are guest-accessible.
| Upload and edit routes require authentication.
|
*/

// Admin routes - auth required (registered first so /upload doesn't match {torrent})
Route::middleware(config('disguise.admin_middleware', ['web', 'auth']))
    ->prefix(config('disguise.prefix', ''))
    ->group(function () {
        Route::get('torrents/upload', TorrentUpload::class)->name('torrents.upload');
        Route::get('torrents/{torrent}/edit', TorrentEdit::class)->name('torrents.edit');
    });

// Public routes - guests welcome
Route::middleware(config('disguise.public_middleware', ['web']))
    ->prefix(config('disguise.prefix', ''))
    ->group(function () {
        Route::get('torrents', TorrentIndex::class)->name('torrents.index');
        Route::get('torrents/{torrent}', TorrentShow::class)->name('torrents.show');
        Route::get('torrents/{torrent}/download', TorrentDownloadController::class)->name('torrents.download');
    });
