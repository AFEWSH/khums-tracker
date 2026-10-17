<?php

namespace App\Livewire;

use App\Support\Khums\RuleCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('احکام و منابع')]
class RulingLibrary extends Component
{
    public function render(RuleCatalog $catalog): View
    {
        Gate::authorize('access-app');

        return view('livewire.ruling-library', ['rules' => $catalog->all(), 'version' => $catalog->version()]);
    }
}
