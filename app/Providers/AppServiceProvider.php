<?php

namespace App\Providers;

use App\Http\Middleware\EnsureOwner;
use App\Models\User;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind concrete services when a use case requires them.
    }

    public function boot(): void
    {
        Gate::define('access-app', fn (User $user): bool => $user->isActiveOwner());
        Password::defaults(fn () => Password::min(12));

        Livewire::addPersistentMiddleware([EnsureOwner::class, AuthenticateSession::class]);

        if ($this->app->isProduction()) {
            config(['session.secure' => true, 'app.debug' => false]);
        }
    }
}
