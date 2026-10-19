<?php

namespace App\Actions\Finance;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Explicitly carries actual prior payments to an amended assessment without reposting cash. */
final class KhumsAmendmentReconciliation
{
    public function preview(int $closureId): array
    {
        Gate::authorize('access-app');
        $closure = DB::table('khums_period_closures')->where('id', $closureId)->where('active', true)->first();
        $assessment = $closure === null ? null : DB::table('khums_assessments')->where('closure_id', $closureId)->first();
        if ($closure === null || $closure->previous_closure_id === null
            || $assessment === null || $assessment->status !== 'reconciliation_required') {
            throw new FinanceInputException('Select an amended assessment awaiting reconciliation.');
        }
        $ancestorIds = [];
        $priorId = $closure->previous_closure_id;
        while ($priorId !== null) {
            $prior = DB::table('khums_period_closures')->where('id', $priorId)->first();
            if ($prior === null || in_array($priorId, $ancestorIds, true)) {
                throw new FinanceInputException('Amendment chain is damaged.');
            }
            $ancestorIds[] = $priorId;
            $priorId = $prior->previous_closure_id;
        }
        $payments = DB::table('khums_settlements')->join('khums_assessments', 'khums_assessments.id', '=', 'khums_settlements.assessment_id')
            ->whereIn('khums_assessments.closure_id', $ancestorIds)
            ->orderBy('khums_settlements.id')
            ->get(['khums_settlements.id', 'khums_settlements.payment_operation_id', 'khums_settlements.amount_rial',
                'khums_settlements.method', 'khums_settlements.source_account_id']);
        $due = BigDecimal::of($assessment->due_exact_rial);
        $remaining = $due;
        $paid = BigDecimal::zero();
        $allocations = [];
        foreach ($payments as $payment) {
            $amount = BigDecimal::of((string) $payment->amount_rial);
            $paid = $paid->plus($amount);
            $allocation = $remaining->isGreaterThan($amount) ? $amount : $remaining;
            $whole = $allocation->toScale(0, RoundingMode::Down);
            if (! $whole->isZero()) {
                $allocations[] = [
                    'settlement_id' => (int) $payment->id,
                    'payment_operation_id' => (int) $payment->payment_operation_id,
                    'amount_rial' => (string) $whole,
                    'method' => $payment->method,
                    'source_account_id' => (int) $payment->source_account_id,
                ];
                $remaining = $remaining->minus($whole);
            }
        }
        $allocated = $due->minus($remaining);
        $preview = [
            'closure_id' => $closureId, 'assessment_id' => (int) $assessment->id,
            'closure_snapshot_hash' => $closure->snapshot_hash,
            'due_exact_rial' => (string) $due,
            'prior_paid_rial' => (string) $paid,
            'allocated_rial' => (string) $allocated,
            'remaining_exact_rial' => (string) $remaining,
            'excess_unallocated_rial' => (string) $paid->minus($allocated),
            'allocations' => $allocations,
        ];
        $preview['hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));

        return $preview;
    }

    public function approve(int $closureId, string $seenHash, string $reason, bool $approved): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if (! $approved || ! preg_match('/^[a-f0-9]{64}$/D', $seenHash)
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 240) {
            throw new FinanceInputException('Review and approve the payment reconciliation with a reason.');
        }

        return DB::transaction(function () use ($closureId, $seenHash, $reason): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $closure = DB::table('khums_period_closures')->where('id', $closureId)->first();
            $assessmentId = $closure === null ? null : DB::table('khums_assessments')->where('closure_id', $closureId)->value('id');
            if ($assessmentId !== null) {
                $existing = DB::table('user_decisions')->where('kind', 'khums_reconciliation')
                    ->where('target_type', 'assessment')->where('target_id', $assessmentId)->orderByDesc('id')->first();
                if ($existing !== null) {
                    $old = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (($old['hash'] ?? null) === $seenHash && ($old['reason'] ?? null) === $reason) {
                        return (int) $existing->id;
                    }

                    throw new FinanceInputException('This assessment already has a different reconciliation.');
                }
            }
            $preview = $this->preview($closureId);
            if (! hash_equals($preview['hash'], $seenHash)) {
                throw new FinanceInputException('Payments or assessment changed; review reconciliation again.');
            }
            $payload = $preview;
            $payload['reason'] = $reason;
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'khums_reconciliation', 'target_type' => 'assessment',
                'target_id' => $preview['assessment_id'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $status = match (true) {
                BigDecimal::of($preview['excess_unallocated_rial'])->isGreaterThan('0')
                    && BigDecimal::of($preview['remaining_exact_rial'])->isGreaterThan('0') => 'reconciliation_review',
                BigDecimal::of($preview['excess_unallocated_rial'])->isGreaterThan('0') => 'settled_excess_review',
                BigDecimal::of($preview['remaining_exact_rial'])->isZero() => 'settled',
                BigDecimal::of($preview['allocated_rial'])->isZero() => 'unpaid',
                default => 'partially_paid',
            };
            DB::table('khums_assessments')->where('id', $preview['assessment_id'])->update([
                'settled_rial' => $preview['allocated_rial'], 'status' => $status,
            ]);
            if ($status === 'settled') {
                $closure = DB::table('khums_assessments')->where('id', $preview['assessment_id'])->value('closure_id');
                DB::table('khums_basis_carryovers')->where('closure_id', $closure)
                    ->where('status', 'assessed_unsettled')->update(['status' => 'settled_basis_review']);
            }
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_payment_reconciled',
                'facts_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }
}
