<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('خانه')]
class Dashboard extends Component
{
    public function render(): View
    {
        Gate::authorize('access-app');

        return view('livewire.dashboard');
    }
}
