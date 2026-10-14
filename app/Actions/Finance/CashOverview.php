<?php

namespace App\Actions\Finance;

use App\Support\Dates\PersianDate;
use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CashOverview
{
    public function openingConfirmed(): bool
    {
        Gate::authorize('access-app');

        return DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') !== null;
    }

    public function accounts(): array
    {
        Gate::authorize('access-app');

        return DB::table('accounts')->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash', 'receivable', 'payable'])
            ->whereNull('archived_at')->orderBy('id')->get()->map(function ($account): array {
                return [
                    'id' => (int) $account->id, 'name' => $account->name,
                    'subtype' => $account->subtype, 'balance_toman' => MoneyRial::fromRial((string) $account->balance_rial)->toman(),
                    'protected_min_toman' => MoneyRial::fromRial((string) $account->protected_min_rial)->toman(),
                    'protected_exact' => (string) $account->protected_min_rial === (string) $account->protected_max_rial,
                ];
            })->all();
    }

    public function accountOptions(string $type, string $role): array
    {
        $allowed = match ([$type, $role]) {
            ['income', 'target'], ['expense', 'source'], ['transfer', 'source'], ['transfer', 'target'],
            ['lend', 'source'], ['collect', 'target'], ['borrow', 'target'], ['repay', 'source'] => ['bank', 'cash', 'broker_cash', 'wallet_cash'],
            ['accrue_income', 'target'] => ['receivable'],
            ['lend', 'target'], ['collect', 'source'] => ['receivable'],
            ['borrow', 'source'], ['repay', 'target'] => ['payable'],
            default => [],
        };

        return array_values(array_filter($this->accounts(), fn (array $account): bool => in_array($account['subtype'], $allowed, true)));
    }

    public function categories(string $type): array
    {
        Gate::authorize('access-app');
        if (! in_array($type, ['income', 'accrue_income', 'expense'], true)) {
            return [];
        }

        return DB::table('categories')->where('kind', $type === 'accrue_income' ? 'income' : $type)->whereNull('archived_at')
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row): array => [
                'id' => (int) $row->id, 'name' => $row->name,
            ])->all();
    }

    /** @return array{income_toman:string, expense_toman:string} */
    public function incomeExpense(): array
    {
        Gate::authorize('access-app');
        $income = MoneyRial::fromRial('0');
        $expense = MoneyRial::fromRial('0');
        $rows = DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('operations.type', ['income', 'accrue_income', 'expense', 'reversal'])
            ->whereIn('accounts.class', ['income', 'expense'])
            ->select('accounts.class', 'journal_lines.side', 'journal_lines.amount_rial')->get();
        foreach ($rows as $row) {
            if ($row->class === 'income') {
                $income = $row->side === 'credit'
                    ? $income->add(MoneyRial::fromRial((string) $row->amount_rial))
                    : $income->subtract(MoneyRial::fromRial((string) $row->amount_rial));
            } else {
                $expense = $row->side === 'debit'
                    ? $expense->add(MoneyRial::fromRial((string) $row->amount_rial))
                    : $expense->subtract(MoneyRial::fromRial((string) $row->amount_rial));
            }
        }

        return ['income_toman' => $income->toman(), 'expense_toman' => $expense->toman()];
    }

    public function recentOperations(): array
    {
        Gate::authorize('access-app');

        return DB::table('operations')->where('type', '!=', 'opening')
            ->orderByDesc('effective_date')->orderByDesc('sequence')->limit(20)->get()
            ->map(fn ($row): array => [
                'type' => match ($row->type) {
                    'income' => 'درآمد', 'accrue_income' => 'شناسایی طلب درآمد', 'expense' => 'هزینه',
                    'transfer' => 'انتقال', 'lend' => 'قرض دادن', 'collect' => 'وصول طلب',
                    'borrow' => 'دریافت قرض', 'repay' => 'بازپرداخت قرض', 'reversal' => 'برگشت سند',
                    default => 'عملیات',
                },
                'date' => PersianDate::fromGregorian($row->effective_date),
                'reason' => $row->reason, 'public_id' => $row->public_id,
            ])->all();
    }
}
