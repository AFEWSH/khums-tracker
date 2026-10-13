<?php

namespace App\Http\Controllers;

use App\Actions\Auth\LogoutOwner;
use Illuminate\Http\RedirectResponse;

class LogoutController extends Controller
{
    public function __invoke(LogoutOwner $logoutOwner): RedirectResponse
    {
        $logoutOwner->handle();

        return redirect()->route('login');
    }
}
