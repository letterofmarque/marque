<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Marque\Guise\Http\Controllers\TorrentDownloadController;
use Marque\Guise\Livewire\Torrent\Edit as TorrentEdit;
use Marque\Guise\Livewire\Torrent\Index as TorrentIndex;
use Marque\Guise\Livewire\Torrent\Show as TorrentShow;
use Marque\Guise\Livewire\Torrent\Upload as TorrentUpload;

/*
|--------------------------------------------------------------------------
| Guise Web Routes
|--------------------------------------------------------------------------
|
| Web routes for the Marque Livewire frontend.
|
*/

Route::middleware(config('guise.middleware', ['web', 'auth', 'verified']))
    ->prefix(config('guise.prefix', ''))
    ->group(function () {
        Route::get('torrents', TorrentIndex::class)->name('torrents.index');
        Route::get('torrents/upload', TorrentUpload::class)->name('torrents.upload');
        Route::get('torrents/{torrent}', TorrentShow::class)->name('torrents.show');
        Route::get('torrents/{torrent}/edit', TorrentEdit::class)->name('torrents.edit');
        Route::get('torrents/{torrent}/download', TorrentDownloadController::class)->name('torrents.download');
    });
