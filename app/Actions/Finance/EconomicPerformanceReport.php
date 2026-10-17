<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\EconomicReturn;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Read-only personal net-worth performance. It never decides religious treatment. */
final class EconomicPerformanceReport
{
    /** @param ?array<int,int> $portfolioAccountIds null means all personal assets minus liabilities. */
    public function between(string $startDate, string $endExclusiveDate, int $seriesId, ?array $portfolioAccountIds = null): array
    {
        Gate::authorize('access-app');

        return DB::transaction(fn (): array => $this->calculate($startDate, $endExclusiveDate, $seriesId, $portfolioAccountIds));
    }

    private function calculate(string $startDate, string $endExclusiveDate, int $seriesId, ?array $portfolioAccountIds): array
    {
        EffectiveTime::dateOnly($startDate);
        EffectiveTime::dateOnly($endExclusiveDate);
        if ($startDate < OpeningSetup::DATE || $endExclusiveDate <= $startDate
            || $endExclusiveDate > now()->addDay()->toDateString()) {
            throw new InvalidArgumentException('Invalid economic report interval.');
        }
        $series = DB::table('cpi_series')->find($seriesId);
        if ($series === null || $series->provider !== 'SCI' || $series->coverage !== 'national_all_households') {
            throw new InvalidArgumentException('Choose a national all-households SCI series.');
        }
        if ($portfolioAccountIds !== null) {
            $portfolioAccountIds = array_values(array_unique($portfolioAccountIds));
            if ($portfolioAccountIds === [] || count($portfolioAccountIds) > 100
                || count(DB::table('accounts')->whereIn('id', $portfolioAccountIds)->where('class', 'asset')
                    ->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash', 'holding'])->get()) !== count($portfolioAccountIds)) {
                throw new InvalidArgumentException('Choose existing cash or holding accounts for the portfolio.');
            }
        }
        $lastDay = $this->previousDate($endExclusiveDate);
        $start = $this->valueBefore($startDate, $startDate === OpeningSetup::DATE, $portfolioAccountIds);
        $end = $this->valueBefore($endExclusiveDate, false, $portfolioAccountIds);
        $flowResult = $portfolioAccountIds === null
            ? ['flows' => $this->externalFlows($startDate, $endExclusiveDate), 'missing' => []]
            : $this->portfolioFlows($startDate, $endExclusiveDate, $portfolioAccountIds);
        $externalFlows = $flowResult['flows'];
        $missing = array_merge($start['missing'], $end['missing'], $flowResult['missing']);
        if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
            $missing[] = 'opening_not_confirmed';
        }
        $coverage = app(CoverageConfirmation::class)->latest();
        if ($coverage === null || $coverage['through_date'] < $lastDay
            || $coverage['input_hash'] !== app(CoverageConfirmation::class)->preview($coverage['through_date'])['input_hash']) {
            $missing[] = 'coverage_not_confirmed';
        }
        if (DB::table('operations')->where('type', 'opening_amendment')
            ->where('effective_date', '>=', $startDate)->where('effective_date', '<', $endExclusiveDate)->exists()) {
            $missing[] = 'opening_amendment_in_period';
        }
        $monthKeys = [$this->monthKey($startDate), $this->monthKey($lastDay)];
        foreach ($externalFlows as $flow) {
            $monthKeys[] = $this->monthKey($flow['date']);
        }
        $indexes = [];
        $revisions = [];
        foreach (array_unique($monthKeys) as $key) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $point = DB::table('cpi_points')->where('series_id', $seriesId)
                ->where('jalali_year', $year)->where('jalali_month', $month)->first();
            $revision = $point === null ? null : DB::table('cpi_revisions')->where('point_id', $point->id)
                ->orderByDesc('revision_no')->first();
            if ($revision === null) {
                $missing[] = 'cpi_missing:'.$key;
            } else {
                $indexes[$key] = (string) $revision->value_decimal;
                $revisions[$key] = (int) $revision->id;
            }
        }
        $missing = array_values(array_unique($missing));
        $nominal = null;
        if ($start['value_rial'] !== null && $end['value_rial'] !== null) {
            $nominal = MoneyRial::fromRial($end['value_rial'])->subtract(MoneyRial::fromRial($start['value_rial']));
            foreach ($externalFlows as $flow) {
                $nominal = $nominal->subtract(MoneyRial::fromRial($flow['amount_rial']));
            }
        }
        $calculated = null;
        if ($missing === [] && $nominal !== null) {
            $flowInputs = array_map(fn (array $flow): array => [
                'amount_rial' => $flow['amount_rial'], 'cpi' => $indexes[$this->monthKey($flow['date'])],
            ], $externalFlows);
            $calculated = EconomicReturn::calculate($start['value_rial'], $end['value_rial'],
                $indexes[$this->monthKey($startDate)], $indexes[$this->monthKey($lastDay)], $flowInputs);
        }

        return [
            'scope' => $portfolioAccountIds === null ? 'personal_net_worth' : 'investment_portfolio',
            'scope_account_ids' => $portfolioAccountIds,
            'start_date' => $startDate, 'end_exclusive_date' => $endExclusiveDate,
            'series_id' => $seriesId, 'series_base_year' => (int) $series->base_year,
            'series_verification_status' => $series->verification_status,
            'status' => $calculated === null ? 'incomplete' : ($series->verification_status === 'verified' ? 'complete' : 'provisional'),
            'missing' => $missing, 'missing_labels' => array_map($this->missingLabel(...), $missing),
            'opening_value_rial' => $start['value_rial'], 'closing_value_rial' => $end['value_rial'],
            'nominal_gain_rial' => $nominal?->rial(), 'real_gain_rial' => $calculated['real_rial'] ?? null,
            'inflation_difference_rial' => $calculated['inflation_difference_rial'] ?? null,
            'flows' => $externalFlows, 'cpi_revision_ids' => $revisions,
            'financial_revision' => (int) DB::table('financial_state')->where('id', 1)->value('revision'),
        ];
    }

    /** Value before a date, except the confirmed opening is the first initial state. */
    private function valueBefore(string $date, bool $includeOpening, ?array $portfolioAccountIds): array
    {
        $included = function ($query) use ($date, $includeOpening): void {
            $query->where('effective_date', '<', $date);
            if ($includeOpening) {
                $query->orWhere(function ($opening) use ($date): void {
                    $opening->where('effective_date', $date)->where('type', 'opening');
                });
            }
        };
        $accountQuery = DB::table('accounts')->whereIn('class', ['asset', 'liability']);
        if ($portfolioAccountIds !== null) {
            $accountQuery->whereIn('id', $portfolioAccountIds);
        }
        $accounts = $accountQuery->get();
        $total = MoneyRial::fromRial('0');
        $missing = [];
        $priceDate = $includeOpening ? $date : $this->previousDate($date);
        foreach ($accounts as $account) {
            $book = MoneyRial::fromRial('0');
            foreach (DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                ->where('journal_lines.account_id', $account->id)->where($included)
                ->get(['journal_lines.side', 'journal_lines.amount_rial']) as $line) {
                $amount = MoneyRial::fromRial((string) $line->amount_rial);
                $book = $line->side === ($account->class === 'asset' ? 'debit' : 'credit')
                    ? $book->add($amount) : $book->subtract($amount);
            }
            $value = $book;
            if (in_array($account->subtype, ['holding', 'receivable_fx', 'payable_fx'], true)) {
                $instrumentId = $account->subtype === 'holding'
                    ? DB::table('asset_accounts')->where('account_id', $account->id)->value('instrument_id')
                    : DB::table('foreign_obligations')->where('account_id', $account->id)->value('instrument_id');
                if ($instrumentId === null) {
                    $missing[] = 'instrument_missing:'.$account->id;

                    continue;
                }
                $instrument = DB::table('instruments')->find($instrumentId);
                $atoms = BigInteger::zero();
                foreach (DB::table('quantity_entries')->join('operations', 'operations.id', '=', 'quantity_entries.operation_id')
                    ->where('quantity_entries.account_id', $account->id)->where($included)
                    ->pluck('quantity_entries.signed_atoms') as $signed) {
                    $atoms = $atoms->plus((string) $signed);
                }
                if (! $atoms->isZero() && ! $includeOpening) {
                    $price = DB::table('asset_prices')->where('instrument_id', $instrumentId)
                        ->where('effective_date', '<=', $priceDate)->orderByDesc('effective_date')->orderByDesc('revision')->first();
                    if ($price === null) {
                        $missing[] = 'price_missing:'.$account->id;

                        continue;
                    }
                    $quantity = BigDecimal::ofUnscaledValue($atoms, (int) $instrument->quantity_scale);
                    $value = MoneyRial::fromRial((string) $quantity->multipliedBy(BigDecimal::of($price->price_rial_per_unit))
                        ->toScale(0, RoundingMode::HalfUp)->toBigInteger());
                }
            }
            $total = $account->class === 'asset' ? $total->add($value) : $total->subtract($value);
        }

        return ['value_rial' => $missing === [] ? $total->rial() : null, 'missing' => $missing];
    }

    private function externalFlows(string $start, string $end): array
    {
        $flows = [];
        $operations = DB::table('operations')->where('effective_date', '>=', $start)
            ->where('effective_date', '<', $end)->orderBy('effective_date')->orderBy('sequence')->orderBy('id')->get();
        foreach ($operations as $operation) {
            $kind = $operation->type;
            $sign = match ($kind) {
                'income', 'accrue_income' => 1,
                'expense' => -1,
                'reversal' => $this->reversalSign($operation->reversal_of_id),
                default => 0,
            };
            if ($sign === 0) {
                continue;
            }
            $line = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.operation_id', $operation->id)
                ->whereIn('accounts.class', ['income', 'expense'])
                ->where('accounts.subtype', 'general')->first(['journal_lines.amount_rial']);
            if ($line === null) {
                continue;
            }
            $amount = MoneyRial::fromRial((string) $line->amount_rial);
            $flows[] = ['operation_id' => (int) $operation->id, 'date' => $operation->effective_date,
                'amount_rial' => $sign === 1 ? $amount->rial() : MoneyRial::fromRial('0')->subtract($amount)->rial()];
        }

        return $flows;
    }

    private function reversalSign(?int $originalId): int
    {
        $type = DB::table('operations')->where('id', $originalId)->value('type');

        return match ($type) {
            'income', 'accrue_income' => -1,
            'expense' => 1,
            default => 0,
        };
    }

    /** Capital crosses a basket boundary only when its selected asset legs have an external counterpart. */
    private function portfolioFlows(string $start, string $end, array $selected): array
    {
        $flows = [];
        $missing = [];
        $operations = DB::table('operations')->where('effective_date', '>=', $start)
            ->where('effective_date', '<', $end)->orderBy('effective_date')->orderBy('sequence')->orderBy('id')->get();
        foreach ($operations as $operation) {
            if ($operation->type === 'opening') {
                continue;
            }
            $legs = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.operation_id', $operation->id)
                ->where('accounts.class', 'asset')
                ->get(['journal_lines.account_id', 'journal_lines.side', 'journal_lines.amount_rial']);
            $inside = MoneyRial::fromRial('0');
            $outside = false;
            $insidePresent = false;
            foreach ($legs as $leg) {
                if (in_array((int) $leg->account_id, $selected, true)) {
                    $insidePresent = true;
                    $amount = MoneyRial::fromRial((string) $leg->amount_rial);
                    $inside = $leg->side === 'debit' ? $inside->add($amount) : $inside->subtract($amount);
                } else {
                    $outside = true;
                }
            }
            if (! $insidePresent) {
                continue;
            }
            if (in_array($operation->type, ['transfer', 'lend', 'collect', 'borrow', 'repay'], true)) {
                if ($inside->rial() !== '0') {
                    $flows[] = ['operation_id' => (int) $operation->id, 'date' => $operation->effective_date,
                        'amount_rial' => $inside->rial()];
                }
            } elseif (in_array($operation->type, ['asset_buy', 'asset_sell', 'asset_convert'], true)) {
                if ($outside) {
                    $missing[] = 'portfolio_boundary_ambiguous:'.$operation->id;
                }
            } elseif ($inside->rial() !== '0') {
                $missing[] = 'portfolio_boundary_ambiguous:'.$operation->id;
            }
        }

        return ['flows' => $flows, 'missing' => $missing];
    }

    private function monthKey(string $date): string
    {
        return substr(PersianDate::fromGregorian($date), 0, 7);
    }

    private function previousDate(string $date): string
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'))
            ->modify('-1 day')->format('Y-m-d');
    }

    private function missingLabel(string $code): string
    {
        if (str_starts_with($code, 'cpi_missing:')) {
            return 'شاخص ماه '.substr($code, strlen('cpi_missing:')).' ثبت نشده است';
        }
        if (str_starts_with($code, 'price_missing:')) {
            return 'قیمت دارایی/تعهد شماره '.substr($code, strlen('price_missing:')).' در تاریخ گزارش موجود نیست';
        }
        if (str_starts_with($code, 'portfolio_boundary_ambiguous:')) {
            return 'منشأ جریان عملیات '.substr($code, strlen('portfolio_boundary_ambiguous:')).' در مرز سبد روشن نیست';
        }

        return match ($code) {
            'opening_not_confirmed' => 'افتتاحیه تأیید نشده است',
            'coverage_not_confirmed' => 'پوشش و تطبیق داده تا پایان بازه تأیید نشده است',
            'opening_amendment_in_period' => 'اصلاح افتتاحیه در بازه نیازمند بررسی اثر عملکرد است',
            default => 'اطلاعات ارزش‌گذاری یا منبع کامل نیست',
        };
    }
}
