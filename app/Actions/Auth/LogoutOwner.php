<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class LogoutOwner
{
    public function handle(): void
    {
        Gate::authorize('access-app');

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
    }
}
