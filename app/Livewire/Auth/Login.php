<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\AuthenticateOwner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('ورود به برنامه')]
class Login extends Component
{
    public string $login = '';

    public string $password = '';

    public function authenticate(AuthenticateOwner $authenticateOwner): void
    {
        try {
            $values = $this->validate([
                'login' => ['required', 'string', 'max:64'],
                'password' => ['required', 'string', 'max:128'],
            ]);

            $authenticateOwner->handle($values['login'], $values['password'], (string) request()->ip());
        } finally {
            $this->reset('password');
        }

        // Only the application dashboard is a valid destination after login.
        $this->redirectRoute('dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
