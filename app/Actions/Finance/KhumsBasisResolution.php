<?php

namespace App\Actions\Finance;

use App\Support\Khums\RuleCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Evidence-backed cash or unchanged single-lot resolution; mixed movements remain review-only. */
final class KhumsBasisResolution
{
    public function confirm(int $closureId, int $accountId, string $ruleId, array $facts, bool $approved): int
    {
        Gate::authorize('access-app');
        if (! $approved || $accountId < 1) {
            throw new FinanceInputException('Explicit basis approval is required.');
        }
        $rule = app(RuleCatalog::class)->requireActive($ruleId);
        if ($ruleId !== 'K01' || array_keys($facts) !== ($rule['required_facts'] ?? [])) {
            throw new FinanceInputException('A validated protected-basis rule and all facts are required.');
        }
        foreach ($facts as $statement) {
            if (! is_string($statement) || mb_strlen(trim($statement)) < 10 || mb_strlen($statement) > 1000) {
                throw new FinanceInputException('Each fact needs a specific written explanation.');
            }
        }

        return DB::transaction(function () use ($closureId, $accountId, $ruleId, $facts): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $closure = DB::table('khums_period_closures')->where('id', $closureId)->where('active', true)->first();
            $carry = DB::table('khums_basis_carryovers')->where('closure_id', $closureId)
                ->where('account_id', $accountId)->first();
            $assessment = DB::table('khums_assessments')->where('closure_id', $closureId)->first();
            $account = DB::table('accounts')->where('id', $accountId)->first();
            if ($closure === null || $carry === null || $assessment === null || $assessment->status !== 'settled') {
                throw new FinanceInputException('A fully settled active assessment is required.');
            }
            if ($account === null || ! in_array($account->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash', 'holding'], true)) {
                throw new FinanceInputException('This asset needs quantity-aware protected-basis review.');
            }
            if ($carry->status === 'resolved_protected') {
                $existing = DB::table('user_decisions')->where('kind', 'khums_basis_resolved')
                    ->where('target_id', $accountId)->orderByDesc('id')->first();
                $payload = $existing === null ? null : json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['closure_id'] ?? null) === $closureId && ($payload['rule_id'] ?? null) === $ruleId
                    && ($payload['facts'] ?? null) === $facts) {
                    return (int) $existing->id;
                }
                throw new FinanceInputException('Basis is already resolved with different evidence.');
            }
            if ($carry->status !== 'settled_basis_review') {
                throw new FinanceInputException('This basis is not ready for resolution.');
            }
            $snapshot = app(KhumsPeriodClosure::class)->read($closureId);
            $endDate = $snapshot['report']['period']['end_exclusive_date'];
            $payments = DB::table('khums_settlements')->where('assessment_id', $assessment->id)->get();
            $paymentIds = $payments->pluck('payment_operation_id')->all();
            $samePaid = BigDecimal::zero();
            $paymentMethods = [];
            foreach ($payments as $payment) {
                $paymentMethods[] = $payment->method;
                if ($payment->method === 'from_current_income') {
                    throw new FinanceInputException('Current-income payment needs a separate basis ruling.');
                }
                if ($payment->method === 'from_same_asset' && (int) $payment->source_account_id === $accountId) {
                    $samePaid = $samePaid->plus((string) $payment->amount_rial);
                }
            }
            $reconciliation = DB::table('user_decisions')->where('kind', 'khums_reconciliation')
                ->where('target_type', 'assessment')->where('target_id', $assessment->id)
                ->orderByDesc('id')->first();
            if ($reconciliation !== null) {
                $payload = json_decode($reconciliation->payload, true, flags: JSON_THROW_ON_ERROR);
                foreach ($payload['allocations'] as $allocation) {
                    $paymentMethods[] = $allocation['method'];
                    $paymentIds[] = $allocation['payment_operation_id'];
                    if ($allocation['method'] === 'from_current_income') {
                        throw new FinanceInputException('Current-income payment needs a separate basis ruling.');
                    }
                    if ($allocation['method'] === 'from_same_asset' && $allocation['source_account_id'] === $accountId) {
                        $samePaid = $samePaid->plus($allocation['amount_rial']);
                    }
                }
            }
            $lotBases = [];
            if ($account->subtype === 'holding') {
                if ($paymentMethods === [] || array_diff($paymentMethods, ['from_other_protected']) !== []) {
                    throw new FinanceInputException('Holding basis needs a fully evidenced payment from other protected cash.');
                }
                $holding = collect($snapshot['report']['asset_basis_trace']['holdings'] ?? [])->firstWhere('account_id', $accountId);
                $position = collect($snapshot['report']['positions'])->firstWhere('account_id', $accountId);
                $quantityChanged = DB::table('quantity_entries')->join('operations', 'operations.id', '=', 'quantity_entries.operation_id')
                    ->where('quantity_entries.account_id', $accountId)->where('operations.effective_date', '>=', $endDate)->exists();
                if ($holding === null || $holding['status'] !== 'traced' || count($holding['lot_ids']) !== 1
                    || $holding['remaining_atoms'] === '0' || $quantityChanged || ($position['market_rial'] ?? null) === null) {
                    throw new FinanceInputException('Holding quantity or valuation changed; review the lot basis again.');
                }
                $protected = BigDecimal::of($position['market_rial']);
                $lotBases[] = ['lot_id' => (int) $holding['lot_ids'][0], 'covered_atoms' => $holding['remaining_atoms'],
                    'protected_basis_rial' => (string) $protected, 'effective_date' => $endDate];
            } else {
                $movements = DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                    ->where('journal_lines.account_id', $accountId)
                    ->where('operations.effective_date', '>=', $endDate)
                    ->get(['operations.id', 'operations.type']);
                foreach ($movements as $movement) {
                    if (! in_array($movement->id, $paymentIds, false) && $movement->type !== 'income') {
                        throw new FinanceInputException('Post-boundary movements need a traced basis allocation.');
                    }
                }
                $protected = BigDecimal::of((string) $carry->eligible_rial)->minus($samePaid);
                $balance = $account->balance_rial;
                if ($protected->isNegative() || $balance === null || $protected->isGreaterThan((string) $balance)) {
                    throw new FinanceInputException('Protected basis exceeds the remaining asset.');
                }
            }
            $payload = [
                'closure_id' => $closureId, 'account_id' => $accountId,
                'protected_basis_rial' => (string) $protected,
                'rule_id' => $ruleId, 'rule_manifest_hash' => app(RuleCatalog::class)->manifestHash(),
                'facts' => $facts, 'same_asset_paid_rial' => (string) $samePaid,
                'last_operation_id' => (int) DB::table('operations')->max('id'),
                'lot_bases' => $lotBases, 'closure_snapshot_hash' => $closure->snapshot_hash,
            ];
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'khums_basis_resolved', 'target_type' => 'account', 'target_id' => $accountId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('khums_basis_carryovers')->where('id', $carry->id)->update(['status' => 'resolved_protected']);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_basis_resolved',
                'facts_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }
}
