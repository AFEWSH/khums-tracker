<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticateOwner
{
    public function handle(string $login, string $password, string $ip): void
    {
        abort_if(Auth::check(), 403);

        $login = Str::lower(trim($login));
        $keys = [
            'login:'.hash('sha256', $login.'|'.$ip) => 5,
            'login-ip:'.hash('sha256', $ip) => 20,
        ];

        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw ValidationException::withMessages([
                    'login' => 'تلاش‌های ورود بیش از حد مجاز است. یک دقیقه بعد دوباره امتحان کنید.',
                ]);
            }
        }

        // Count attempts before password verification so concurrent failures share a budget.
        foreach ($keys as $key => $limit) {
            RateLimiter::hit($key, 60);
        }

        if (! Auth::attempt([
            'id' => User::OWNER_ID,
            'login' => $login,
            'password' => $password,
            'disabled_at' => null,
        ], remember: false)) {
            throw ValidationException::withMessages([
                'login' => 'نام کاربری یا رمز عبور درست نیست.',
            ]);
        }

        RateLimiter::clear(array_key_first($keys));
        session()->regenerate();
    }
}
