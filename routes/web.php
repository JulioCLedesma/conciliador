<?php

use App\Http\Controllers\CatalogDownloadController;
use App\Livewire\Reconciliation\Board;
use App\Livewire\Reconciliation\ImportCatalog;
use App\Livewire\Reconciliation\Sessions;
use App\Livewire\Reconciliation\Users;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
    Route::get('/conciliaciones', Sessions::class)->name('reconciliation.sessions');
    Route::get('/conciliaciones/{sessionId}/configurar', ImportCatalog::class)->name('reconciliation.import');
    Route::get('/conciliaciones/{sessionId}', Board::class)->name('reconciliation.board');
    Route::get('/conciliaciones/{sessionId}/exportar/{kind}', [CatalogDownloadController::class, 'export'])->name('reconciliation.export');
    Route::get('/catalogos/{catalogId}/original', [CatalogDownloadController::class, 'original'])->name('reconciliation.original');
    Route::get('/usuarios', Users::class)->name('users.index');
});
