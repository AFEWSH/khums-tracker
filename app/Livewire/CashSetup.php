<?php

namespace App\Livewire;

use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\OpeningSetup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('راه‌اندازی نقدی')]
class CashSetup extends Component
{
    public bool $previousKhumsSettled = false;

    public string $kind = 'bank';

    public string $name = '';

    public string $amountToman = '';

    public string $protectedToman = '0';

    public string $evidence = 'unknown';

    public int $seenRevision = 0;

    public string $confirmKey = '';

    public bool $confirmNoItems = false;

    public function mount(OpeningSetup $setup): void
    {
        Gate::authorize('access-app');
        $session = $setup->session();
        $this->previousKhumsSettled = (bool) $session->previous_khums_settled;
        $this->seenRevision = (int) $session->revision;
        $this->confirmKey = (string) Str::uuid();
    }

    public function saveSettings(OpeningSetup $setup): void
    {
        $setup->saveSettings($this->previousKhumsSettled);
        $this->seenRevision = (int) $setup->session()->revision;
        session()->flash('success', 'تنظیمات راه‌اندازی ذخیره شد.');
    }

    public function addItem(OpeningSetup $setup): void
    {
        $this->validate([
            'kind' => 'required|in:bank,cash,broker_cash,wallet_cash,receivable,payable',
            'name' => 'required|string|max:120',
            'amountToman' => 'required|string|max:80',
            'protectedToman' => 'required|string|max:80',
            'evidence' => 'required|in:unknown,user_declared',
        ]);
        try {
            $setup->addItem($this->kind, $this->name, $this->amountToman, $this->protectedToman, $this->evidence);
            $this->seenRevision = (int) $setup->session()->revision;
            $this->reset('name', 'amountToman');
            $this->protectedToman = '0';
            $this->evidence = 'unknown';
            session()->flash('success', 'قلم افتتاحیه ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('amountToman', $error->getMessage());
        }
    }

    public function removeItem(int $id, OpeningSetup $setup): void
    {
        $setup->removeDraftItem($id);
        $this->seenRevision = (int) $setup->session()->revision;
    }

    public function confirm(OpeningSetup $setup): void
    {
        try {
            $setup->confirm($this->seenRevision, (int) auth()->id(), $this->confirmKey, $this->confirmNoItems);
            $this->redirectRoute('cash.workspace');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('confirm', $error->getMessage());
        }
    }

    public function render(OpeningSetup $setup): View
    {
        Gate::authorize('access-app');

        return view('livewire.cash-setup', [
            'setup' => $setup->session(),
            'items' => $setup->draftItems(),
            'summary' => $setup->draftSummary(),
        ]);
    }
}
