<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Explicit allocation of evidenced sale proceeds after spending; never infers a mixed-money ruling. */
final class KhumsProceedsAllocation
{
    public function confirm(string $date, int $accountId, string $seenHash, string $protectedRial, array $facts, bool $approved): int
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        $protected = MoneyRial::fromRial($protectedRial)->rial();
        $catalog = app(RuleCatalog::class);
        $rule = $catalog->requireActive('K12');
        $catalog->requireActive('K01');
        if (! $approved || $date > now('Asia/Tehran')->toDateString() || $accountId < 1 || str_starts_with($protected, '-')
            || ! preg_match('/^[a-f0-9]{64}$/D', $seenHash)
            || ($rule['required_facts'] ?? []) === [] || array_keys($facts) !== $rule['required_facts']) {
            throw new FinanceInputException('A reviewed mixed-proceeds allocation and every required fact are necessary.');
        }
        foreach ($facts as $fact) {
            if (! is_string($fact) || mb_strlen(trim($fact)) < 10 || mb_strlen($fact) > 1000) {
                throw new FinanceInputException('Each allocation fact needs a specific written explanation.');
            }
        }

        return DB::transaction(function () use ($date, $accountId, $seenHash, $protected, $facts, $catalog): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $trace = app(KhumsAssetBasisTrace::class)->asOf($date);
            if (! hash_equals($trace['raw_hash'], $seenHash)) {
                throw new FinanceInputException('The sale history changed; review the allocation again.');
            }
            $position = collect($trace['cash_positions'])->firstWhere('account_id', $accountId);
            $issues = $position['reviewed_issues'] ?? $position['issues'] ?? [];
            if ($position === null || $issues === []
                || array_diff($issues, ['protected_proceeds_spending_requires_allocation']) !== []) {
                throw new FinanceInputException('This account needs a different source-history review.');
            }
            $original = BigRational::zero();
            $firstDate = null;
            foreach ($trace['sales'] as $sale) {
                if ($sale['cash_account_id'] === $accountId) {
                    $original = $original->plus($sale['protected_proceeds_exact_rial']);
                    $firstDate = $firstDate === null || $sale['effective_date'] < $firstDate ? $sale['effective_date'] : $firstDate;
                }
            }
            $balance = BigRational::zero();
            $outflows = BigRational::zero();
            $lines = DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                ->where('journal_lines.account_id', $accountId)->where('operations.effective_date', '<=', $date)
                ->get(['journal_lines.side', 'journal_lines.amount_rial', 'operations.effective_date']);
            foreach ($lines as $line) {
                $balance = $line->side === 'debit' ? $balance->plus((string) $line->amount_rial) : $balance->minus((string) $line->amount_rial);
                if ($line->side === 'credit' && $firstDate !== null && $line->effective_date >= $firstDate) {
                    $outflows = $outflows->plus((string) $line->amount_rial);
                }
            }
            $minimum = $original->minus($outflows);
            if ($minimum->isNegative()) {
                $minimum = BigRational::zero();
            }
            $amount = BigRational::of($protected);
            if ($amount->isGreaterThan($original) || $amount->isGreaterThan($balance) || $amount->isLessThan($minimum)) {
                throw new FinanceInputException('Allocation is outside the evidenced proceeds and remaining-balance bounds.');
            }
            $payload = ['date' => $date, 'account_id' => $accountId, 'raw_hash' => $seenHash,
                'protected_basis_rial' => $protected, 'rule_id' => 'K12',
                'rule_manifest_hash' => $catalog->manifestHash(), 'facts' => $facts];
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach (DB::table('user_decisions')->where('kind', 'khums_proceeds_allocation')->where('target_id', $accountId)->orderByDesc('id')->get() as $existing) {
                if ($existing->payload === $encoded) {
                    return (int) $existing->id;
                }
                $old = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($old['date'] ?? null) === $date && ($old['raw_hash'] ?? null) === $seenHash) {
                    throw new FinanceInputException('This input already has a different confirmed allocation.');
                }
            }
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'khums_proceeds_allocation', 'target_type' => 'account', 'target_id' => $accountId,
                'payload' => $encoded, 'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'action' => 'khums_proceeds_allocated',
                'facts_hash' => hash('sha256', $encoded), 'recorded_at' => now()]);

            return $id;
        });
    }
}
