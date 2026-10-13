<?php

namespace App\Http\Controllers;

use App\Actions\Auth\AuthenticateOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __invoke(Request $request, AuthenticateOwner $authenticateOwner): RedirectResponse
    {
        $values = $request->validate([
            'login' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $authenticateOwner->handle($values['login'], $values['password'], (string) $request->ip());

        return redirect()->route('dashboard');
    }
}
