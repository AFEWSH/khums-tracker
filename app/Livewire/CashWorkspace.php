<?php

namespace App\Livewire;

use App\Actions\Finance\CashBook;
use App\Actions\Finance\CashOverview;
use App\Actions\Finance\FinanceInputException;
use App\Support\Dates\PersianDate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('حساب‌ها و گردش نقدی')]
class CashWorkspace extends Component
{
    public string $type = 'expense';

    public string $date = '';

    public string $amountToman = '';

    public ?int $sourceId = null;

    public ?int $targetId = null;

    public ?int $categoryId = null;

    public string $reason = '';

    public string $operationKey = '';

    public bool $afterOpening = false;

    public string $newAccountName = '';

    public string $newAccountSubtype = 'bank';

    public string $newCategoryName = '';

    public string $newCategoryKind = 'expense';

    public function updatedType(): void
    {
        $this->sourceId = null;
        $this->targetId = null;
        $this->categoryId = null;
    }

    public function mount(): void
    {
        Gate::authorize('access-app');
        $this->date = PersianDate::fromGregorian(now('Asia/Tehran')->format('Y-m-d'));
        $this->operationKey = (string) Str::uuid();
    }

    public function createAccount(CashBook $book): void
    {
        $this->validate([
            'newAccountName' => 'required|string|max:120',
            'newAccountSubtype' => 'required|in:bank,cash,broker_cash,wallet_cash,receivable,payable',
        ]);
        try {
            $book->createAccount($this->newAccountName, $this->newAccountSubtype);
            $this->reset('newAccountName');
            session()->flash('success', 'حساب بدون مانده اولیه ساخته شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('newAccountName', $error->getMessage());
        }
    }

    public function post(CashBook $book): void
    {
        $this->validate([
            'type' => 'required|in:income,accrue_income,expense,transfer,lend,collect,borrow,repay',
            'date' => 'required|string|max:20',
            'amountToman' => 'required|string|max:80',
            'sourceId' => 'nullable|integer|min:1',
            'targetId' => 'nullable|integer|min:1',
            'categoryId' => 'nullable|integer|min:1',
            'reason' => 'nullable|string|max:240',
        ]);
        try {
            $gregorian = PersianDate::parseInput($this->date);
            $book->post([
                'type' => $this->type, 'date' => $gregorian, 'amount_toman' => $this->amountToman,
                'source_id' => $this->sourceId, 'target_id' => $this->targetId,
                'category_id' => $this->categoryId,
                'reason' => $this->reason, 'idempotency_key' => $this->operationKey,
                'after_opening' => $this->afterOpening,
            ]);
            $this->operationKey = (string) Str::uuid();
            $this->reset('amountToman', 'reason', 'afterOpening');
            session()->flash('success', 'عملیات ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('amountToman', $error->getMessage());
        }
    }

    public function createCategory(CashBook $book): void
    {
        $this->validate([
            'newCategoryName' => 'required|string|max:80',
            'newCategoryKind' => 'required|in:income,expense',
        ]);
        try {
            $book->createCategory($this->newCategoryName, $this->newCategoryKind);
            $this->reset('newCategoryName');
            session()->flash('success', 'دسته ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('newCategoryName', $error->getMessage());
        }
    }

    public function render(CashOverview $overview): View
    {
        Gate::authorize('access-app');

        return view('livewire.cash-workspace', [
            'openingConfirmed' => $overview->openingConfirmed(),
            'accounts' => $overview->accounts(),
            'sourceOptions' => $overview->accountOptions($this->type, 'source'),
            'targetOptions' => $overview->accountOptions($this->type, 'target'),
            'categoryOptions' => $overview->categories($this->type),
            'totals' => $overview->incomeExpense(),
            'operations' => $overview->recentOperations(),
        ]);
    }
}
