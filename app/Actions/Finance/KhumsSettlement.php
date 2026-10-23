<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Posts one real expense and its assessment allocation in the same transaction. */
final class KhumsSettlement
{
    public function pay(int $assessmentId, int $sourceAccountId, string $date, string $amountRial, string $method, string $evidence, string $key, bool $explicitApproval): int
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        $amount = MoneyRial::fromRial($amountRial);
        $evidence = trim($evidence);
        if (! $explicitApproval || $assessmentId < 1 || $sourceAccountId < 1
            || $amount->rial() === '0' || str_starts_with($amount->rial(), '-')
            || ! in_array($method, ['from_same_asset', 'from_other_protected', 'from_current_income'], true)
            || mb_strlen($evidence) < 10 || mb_strlen($evidence) > 210
            || ! preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) {
            throw new InvalidArgumentException('A confirmed payment, method, evidence and unique key are required.');
        }

        return DB::transaction(function () use ($assessmentId, $sourceAccountId, $date, $amount, $method, $evidence, $key): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $existing = DB::table('khums_settlements')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ((int) $existing->assessment_id !== $assessmentId
                    || (string) $existing->amount_rial !== $amount->rial()
                    || (int) $existing->source_account_id !== $sourceAccountId
                    || $existing->payment_date !== $date
                    || $existing->method !== $method || $existing->evidence !== $evidence) {
                    throw new FinanceInputException('Payment key was used with different details.');
                }

                return (int) $existing->id;
            }
            $assessment = DB::table('khums_assessments')
                ->join('khums_period_closures', 'khums_period_closures.id', '=', 'khums_assessments.closure_id')
                ->where('khums_assessments.id', $assessmentId)
                ->first(['khums_assessments.*', 'khums_period_closures.active', 'khums_period_closures.dependency_stale']);
            if ($assessment === null || ! $assessment->active || $assessment->dependency_stale) {
                throw new FinanceInputException('The assessment is not active.');
            }
            $closureSnapshot = app(KhumsPeriodClosure::class)->read((int) $assessment->closure_id);
            if ($date < $closureSnapshot['report']['period']['end_exclusive_date']
                || $date > now('Asia/Tehran')->toDateString()) {
                throw new FinanceInputException('Payment date must be after the due boundary and no later than today.');
            }
            if (in_array($assessment->status, ['reconciliation_required', 'reconciliation_review', 'settled_excess_review'], true)) {
                throw new FinanceInputException('Amended assessment needs reconciliation before another payment.');
            }
            $terms = app(KhumsSettlementTerms::class)->forAssessment($assessmentId);
            $payable = $terms['payable_due_exact_rial'];
            $remaining = BigDecimal::of($payable)->minus((string) $assessment->settled_rial);
            if (BigDecimal::of($amount->rial())->isGreaterThan($remaining)) {
                throw new FinanceInputException('Payment exceeds the remaining assessment.');
            }
            $account = DB::table('accounts')->where('id', $sourceAccountId)->first();
            if ($account === null || $account->class !== 'asset'
                || ! in_array($account->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
                throw new FinanceInputException('Payment requires a cash or bank source account.');
            }
            $originProof = null;
            if ($method === 'from_other_protected'
                && BigDecimal::of((string) $account->protected_min_rial)->isLessThan($amount->rial())) {
                $originProof = app(KhumsOriginPlan::class)->paymentSource($date, $sourceAccountId);
                if ($originProof === null || BigRational::of($originProof['protected_basis_exact_rial'])->isLessThan($amount->rial())) {
                    throw new FinanceInputException('The source has no confirmed protected amount for this payment.');
                }
            }
            if ($method === 'from_same_asset' && ! DB::table('khums_basis_carryovers')
                ->where('closure_id', $assessment->closure_id)->where('account_id', $sourceAccountId)->exists()) {
                throw new FinanceInputException('The payer is not an assessed asset in this closure.');
            }
            $operationId = app(CashBook::class)->post([
                'type' => 'expense', 'date' => $date, 'amount_toman' => $amount->toman(),
                'source_id' => $sourceAccountId, 'idempotency_key' => 'khums-pay-'.$key,
                'reason' => 'پرداخت خمس: '.$evidence,
            ]);
            $id = DB::table('khums_settlements')->insertGetId([
                'assessment_id' => $assessmentId, 'payment_operation_id' => $operationId,
                'source_account_id' => $sourceAccountId, 'payment_date' => $date,
                'amount_rial' => $amount->rial(), 'method' => $method, 'evidence' => $evidence,
                'idempotency_key' => $key, 'confirmed_by' => auth()->id(), 'confirmed_at' => now(),
            ]);
            if ($originProof !== null) {
                DB::table('user_decisions')->insert([
                    'kind' => 'khums_payment_origin', 'target_type' => 'settlement', 'target_id' => $id,
                    'payload' => json_encode(['payment_operation_id' => $operationId, 'origin_plan' => $originProof], JSON_THROW_ON_ERROR),
                    'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($terms['decision_id'] !== null) {
                DB::table('user_decisions')->insert([
                    'kind' => 'khums_payment_terms', 'target_type' => 'settlement', 'target_id' => $id,
                    'payload' => json_encode(['terms_decision_id' => $terms['decision_id'], 'payment_operation_id' => $operationId,
                        'payable_due_exact_rial' => $payable], JSON_THROW_ON_ERROR),
                    'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $settled = BigDecimal::of((string) $assessment->settled_rial)->plus($amount->rial());
            DB::table('khums_assessments')->where('id', $assessmentId)->update([
                'settled_rial' => (string) $settled,
                'status' => $settled->isEqualTo($payable) ? 'settled' : 'partially_paid',
            ]);
            // The method is recorded, but a payment does not by itself establish
            // which remaining asset acquired a protected religious basis.
            if ($settled->isEqualTo($payable)) {
                DB::table('khums_basis_carryovers')->where('closure_id', $assessment->closure_id)
                    ->where('status', 'assessed_unsettled')->update(['status' => 'settled_basis_review']);
            }
            DB::table('audit_events')->insert([
                'operation_id' => $operationId, 'actor_id' => auth()->id(),
                'action' => 'khums_payment_allocated',
                'facts_hash' => hash('sha256', json_encode([$assessmentId, $operationId, $amount->rial(), $method, $evidence], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }
}
