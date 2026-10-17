<?php

namespace App\Livewire;

use App\Actions\Finance\KhumsSimulation;
use App\Support\Dates\PersianDate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('پیش‌نویس خمس')]
class KhumsWorkspace extends Component
{
    public string $asOfDate = '';

    public ?array $report = null;

    public function mount(): void
    {
        Gate::authorize('access-app');
        $this->asOfDate = PersianDate::fromGregorian(now()->toDateString());
    }

    public function simulate(KhumsSimulation $simulation): void
    {
        Gate::authorize('access-app');
        $this->report = null;
        try {
            $date = PersianDate::parseInput($this->asOfDate);
            $this->report = $simulation->asOf($date);
            $this->resetErrorBag();
        } catch (InvalidArgumentException $error) {
            $this->addError('asOfDate', 'تاریخ باید از ۲۰ مرداد ۱۴۰۵ تا امروز باشد.');
        }
    }

    public function render(): View
    {
        Gate::authorize('access-app');

        return view('livewire.khums-workspace');
    }
}
