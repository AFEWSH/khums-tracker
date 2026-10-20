<?php

namespace App\Actions\Finance;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Synchronizes an actual journal reversal with settlement projections in the same transaction. */
final class KhumsPaymentReversal
{
    public function record(int $paymentOperationId, int $reversalOperationId): void
    {
        Gate::authorize('access-app');
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payment reversal must share the journal transaction.');
        }
        $settlement = DB::table('khums_settlements')->where('payment_operation_id', $paymentOperationId)->first();
        if ($settlement === null) {
            return;
        }
        $reversal = DB::table('operations')->where('id', $reversalOperationId)
            ->where('reversal_of_id', $paymentOperationId)->first();
        if ($reversal === null) {
            throw new FinanceInputException('A matching posted payment reversal is required.');
        }
        $affected = [(int) $settlement->assessment_id => (string) $settlement->amount_rial];
        $seen = [];
        foreach (DB::table('user_decisions')->where('kind', 'khums_reconciliation')->orderByDesc('id')->get() as $decision) {
            $assessmentId = (int) $decision->target_id;
            if (isset($seen[$assessmentId])) {
                continue;
            }
            $seen[$assessmentId] = true;
            $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['allocations'] as $allocation) {
                if ($allocation['payment_operation_id'] === $paymentOperationId) {
                    $affected[$assessmentId] = $allocation['amount_rial'];
                }
            }
        }
        $staleIds = [];
        foreach ($affected as $assessmentId => $amount) {
            $assessment = DB::table('khums_assessments')->where('id', $assessmentId)->first();
            $closure = $assessment === null ? null : DB::table('khums_period_closures')->where('id', $assessment->closure_id)->first();
            if ($assessment === null || $closure === null) {
                throw new FinanceInputException('Payment assessment history is missing.');
            }
            $settled = BigDecimal::of((string) $assessment->settled_rial)->minus($amount);
            if ($settled->isNegative()) {
                throw new FinanceInputException('Payment allocation exceeds its settlement projection.');
            }
            $status = ! $closure->active ? ($settled->isZero() ? 'superseded' : 'superseded_reconciliation')
                : ((int) $closure->revision > 1 ? 'reconciliation_required' : ($settled->isZero() ? 'unpaid' : 'partially_paid'));
            DB::table('khums_assessments')->where('id', $assessmentId)->update([
                'settled_rial' => (string) $settled, 'status' => $status,
            ]);
            DB::table('khums_basis_carryovers')->where('closure_id', $closure->id)
                ->update(['status' => 'assessed_unsettled']);
            $staleIds = array_merge($staleIds, app(KhumsYearDependencies::class)->affectedClosureIds((int) $closure->id));
        }
        $staleIds = array_values(array_unique($staleIds));
        if ($staleIds !== []) {
            DB::table('khums_period_closures')->whereIn('id', $staleIds)->update(['dependency_stale' => true]);
        }
        $payload = [
            'settlement_id' => (int) $settlement->id, 'payment_operation_id' => $paymentOperationId,
            'reversal_operation_id' => $reversalOperationId, 'assessment_reductions_rial' => $affected,
            'stale_closure_ids' => $staleIds,
        ];
        DB::table('user_decisions')->insert([
            'kind' => 'khums_payment_reversed', 'target_type' => 'settlement', 'target_id' => $settlement->id,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'actor_id' => auth()->id(),
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_events')->insert([
            'operation_id' => $reversalOperationId, 'actor_id' => auth()->id(), 'action' => 'khums_payment_reversed',
            'facts_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'recorded_at' => now(),
        ]);
    }
}
