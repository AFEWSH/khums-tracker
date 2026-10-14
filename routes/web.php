<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\CashSetup;
use App\Livewire\CashWorkspace;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

Route::get('/login', Login::class)->middleware('guest')->name('login');
Route::post('/login', LoginController::class)->middleware('guest')->name('login.store');

Route::middleware(['auth', 'auth.session', 'owner'])->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/setup/cash', CashSetup::class)->name('cash.setup');
    Route::get('/cash', CashWorkspace::class)->name('cash.workspace');
    Route::post('/logout', LogoutController::class)->name('logout');
});
