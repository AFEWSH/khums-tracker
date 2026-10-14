<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Livewire\AssetWorkspace;
use App\Livewire\Auth\Login;
use App\Livewire\CashCsvImport;
use App\Livewire\CashSetup;
use App\Livewire\CashWorkspace;
use App\Livewire\CpiWorkspace;
use App\Livewire\Dashboard;
use App\Livewire\FinanceReview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

Route::get('/login', Login::class)->middleware('guest')->name('login');
Route::post('/login', LoginController::class)->middleware('guest')->name('login.store');

Route::middleware(['auth', 'auth.session', 'owner'])->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/setup/cash', CashSetup::class)->name('cash.setup');
    Route::get('/cash', CashWorkspace::class)->name('cash.workspace');
    Route::get('/cash/import', CashCsvImport::class)->name('cash.import');
    Route::get('/assets', AssetWorkspace::class)->name('assets.workspace');
    Route::get('/review', FinanceReview::class)->name('finance.review');
    Route::get('/cpi', CpiWorkspace::class)->name('cpi.workspace');
    Route::post('/logout', LogoutController::class)->name('logout');
});
