<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\MoneyRial;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CashFlowReport
{
    /** Half-open date range; opening is a starting balance, never a flow. */
    public function between(string $startDate, string $endExclusiveDate): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($startDate);
        EffectiveTime::dateOnly($endExclusiveDate);
        if ($endExclusiveDate <= $startDate) {
            throw new InvalidArgumentException('Cash-flow end must follow start.');
        }
        $cashIds = DB::table('accounts')->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $beginning = MoneyRial::fromRial('0');
        $inflow = MoneyRial::fromRial('0');
        $outflow = MoneyRial::fromRial('0');
        $rows = [];
        foreach (DB::table('operations')->where('effective_date', '<', $endExclusiveDate)
            ->orderBy('effective_date')->orderBy('sequence')->orderBy('id')->get() as $operation) {
            $delta = MoneyRial::fromRial('0');
            foreach (DB::table('journal_lines')->where('operation_id', $operation->id)->whereIn('account_id', $cashIds)
                ->get(['side', 'amount_rial']) as $line) {
                $amount = MoneyRial::fromRial((string) $line->amount_rial);
                $delta = $line->side === 'debit' ? $delta->add($amount) : $delta->subtract($amount);
            }
            if ($operation->type === 'opening' || $operation->effective_date < $startDate) {
                $beginning = $beginning->add($delta);

                continue;
            }
            if ($delta->rial() === '0') {
                continue;
            }
            if ($delta->rial()[0] === '-') {
                $outflow = $outflow->add(MoneyRial::fromRial(substr($delta->rial(), 1)));
            } else {
                $inflow = $inflow->add($delta);
            }
            $rows[] = [
                'operation_id' => (int) $operation->id, 'date' => $operation->effective_date,
                'type' => $operation->type, 'net_toman' => $delta->toman(),
            ];
        }
        $ending = $beginning->add($inflow)->subtract($outflow);
        $coverage = app(CoverageConfirmation::class)->latest();
        $lastIncluded = DateTimeImmutable::createFromFormat('!Y-m-d', $endExclusiveDate, new DateTimeZone('UTC'))
            ->modify('-1 day')->format('Y-m-d');

        return [
            'start_date' => $startDate, 'end_exclusive_date' => $endExclusiveDate,
            'beginning_toman' => $beginning->toman(), 'inflow_toman' => $inflow->toman(),
            'outflow_toman' => $outflow->toman(), 'ending_toman' => $ending->toman(),
            'coverage_through_date' => $coverage['through_date'] ?? null,
            'coverage_complete' => $coverage !== null && $coverage['through_date'] >= $lastIncluded,
            'rows' => $rows,
        ];
    }
}
