<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Khums\ProtectedLotBasis;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read-only trace of evidenced, single-lot holdings. Economic FIFO never selects a religious lot. */
final class KhumsAssetBasisTrace
{
    public function asOf(string $date): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        $holdings = [];
        $cash = [];
        $sales = [];
        $lots = DB::table('lots')->join('operations', 'operations.id', '=', 'lots.acquisition_operation_id')
            ->where('operations.effective_date', '<=', $date)->orderBy('lots.id')
            ->get(['lots.*', 'operations.effective_date as opening_date']);
        foreach ($lots->groupBy('account_id') as $accountId => $accountLots) {
            $accountId = (int) $accountId;
            $lotIds = $accountLots->pluck('id')->all();
            $position = ['account_id' => $accountId, 'lot_ids' => $lotIds,
                'status' => 'needs_review', 'protected_basis_exact_rial' => null,
                'remaining_atoms' => null, 'issues' => []];
            if ($accountLots->count() !== 1) {
                $position['issues'][] = 'physical_lot_allocation_required';
                $holdings[$accountId] = $position;

                continue;
            }
            $lot = $accountLots->first();
            $originAmended = false;
            foreach (DB::table('amendment_cases')->where('kind', 'opening_asset_quantity_origin')
                ->where('status', 'approved')->pluck('proposal') as $proposal) {
                if ((json_decode($proposal, true, flags: JSON_THROW_ON_ERROR)['lot_id'] ?? null) === (int) $lot->id) {
                    $originAmended = true;
                    break;
                }
            }
            if ($originAmended) {
                $position['issues'][] = 'opening_basis_amendment_requires_review';
                $holdings[$accountId] = $position;

                continue;
            }
            $origin = DB::table('asset_origin_events')->where('lot_id', $lot->id)
                ->where('kind', 'opening')->orderBy('id')->first();
            if ($origin === null || $origin->evidence !== 'user_declared'
                || $origin->protected_basis_rial === null
                || ! BigInteger::of($origin->protected_min_atoms)->isEqualTo($lot->acquired_atoms)
                || ! BigInteger::of($origin->protected_max_atoms)->isEqualTo($lot->acquired_atoms)) {
                $position['issues'][] = 'whole_lot_protected_evidence_required';
                $holdings[$accountId] = $position;

                continue;
            }
            $remaining = (string) $lot->acquired_atoms;
            $basis = (string) $origin->protected_basis_rial;
            $anchors = [];
            $invalidAnchorYears = [];
            $validAnchorYears = [];
            foreach (DB::table('user_decisions')->where('kind', 'khums_basis_resolved')
                ->where('target_type', 'account')->where('target_id', $accountId)->orderBy('id')->get() as $decision) {
                $resolution = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
                foreach ($resolution['lot_bases'] ?? [] as $anchor) {
                    if ($anchor['lot_id'] !== (int) $lot->id || $anchor['effective_date'] > $date) {
                        continue;
                    }
                    $sourceClosure = DB::table('khums_period_closures')->where('id', $resolution['closure_id'])->first();
                    $settled = $sourceClosure === null ? false : DB::table('khums_assessments')
                        ->where('closure_id', $sourceClosure->id)->where('status', 'settled')->exists();
                    if ($sourceClosure === null || ! $sourceClosure->active || $sourceClosure->dependency_stale || ! $settled
                        || ($resolution['closure_snapshot_hash'] ?? null) !== $sourceClosure->snapshot_hash) {
                        $invalidAnchorYears[] = $sourceClosure === null ? 'closure:'.$resolution['closure_id'] : (string) $sourceClosure->start_year;

                        continue;
                    }
                    $validAnchorYears[] = (string) $sourceClosure->start_year;
                    $anchors[] = $anchor + ['decision_id' => (int) $decision->id];
                }
            }
            $invalidAnchor = array_diff($invalidAnchorYears, $validAnchorYears) !== [];
            usort($anchors, static fn (array $left, array $right): int => [$left['effective_date'], $left['decision_id']] <=> [$right['effective_date'], $right['decision_id']]);
            $position['basis_anchor_decision_ids'] = array_column($anchors, 'decision_id');
            $entries = DB::table('quantity_entries')->join('operations', 'operations.id', '=', 'quantity_entries.operation_id')
                ->where('quantity_entries.account_id', $accountId)->where('operations.effective_date', '<=', $date)
                ->orderBy('operations.effective_date')->orderBy('operations.sequence')->orderBy('operations.id')
                ->get(['operations.*', 'quantity_entries.signed_atoms']);
            $valid = ! $invalidAnchor;
            if ($invalidAnchor) {
                $position['issues'][] = 'settled_lot_anchor_requires_review';
            }
            foreach ($entries as $entry) {
                if ((int) $entry->id === (int) $lot->acquisition_operation_id) {
                    continue;
                }
                if (! $valid || ! $this->applyAnchors($anchors, $entry->effective_date, $remaining, $basis)) {
                    $valid = false;
                    $position['issues'][] = 'settled_lot_anchor_requires_review';
                    break;
                }
                if ($entry->type !== 'asset_sell' || ! str_starts_with($entry->signed_atoms, '-')) {
                    $position['issues'][] = 'asset_movement_basis_review';
                    $valid = false;
                    break;
                }
                $reversed = DB::table('operations')->where('reversal_of_id', $entry->id)
                    ->where('effective_date', '<=', $date)->exists();
                $targets = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                    ->where('journal_lines.operation_id', $entry->id)->where('journal_lines.side', 'debit')
                    ->whereIn('accounts.subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])
                    ->get(['accounts.id', 'journal_lines.amount_rial']);
                if ($reversed || $targets->count() !== 1 || $remaining === '0') {
                    $position['issues'][] = 'sale_basis_review';
                    $valid = false;
                    break;
                }
                $target = $targets->first();
                $split = ProtectedLotBasis::exit($remaining, $basis, substr($entry->signed_atoms, 1), (string) $target->amount_rial);
                $remaining = $split['remaining_atoms'];
                $basis = $split['remaining_basis_exact_rial'];
                $cashId = (int) $target->id;
                $cash[$cashId] ??= ['account_id' => $cashId, 'status' => 'traced',
                    'protected_basis_exact_rial' => '0', 'sale_operation_ids' => [], 'issues' => []];
                $cash[$cashId]['protected_basis_exact_rial'] = (string) BigRational::of($cash[$cashId]['protected_basis_exact_rial'])
                    ->plus($split['protected_proceeds_exact_rial']);
                $cash[$cashId]['sale_operation_ids'][] = (int) $entry->id;
                $laterOutflow = DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                    ->where('journal_lines.account_id', $cashId)->where('journal_lines.side', 'credit')
                    ->where('operations.effective_date', '<=', $date)
                    ->where(function ($query) use ($entry): void {
                        $query->where('operations.effective_date', '>', $entry->effective_date)
                            ->orWhere(function ($sameDay) use ($entry): void {
                                $sameDay->where('operations.effective_date', $entry->effective_date)
                                    ->where('operations.sequence', '>', $entry->sequence);
                            });
                    })->exists();
                if ($laterOutflow) {
                    $cash[$cashId]['status'] = 'needs_review';
                    $cash[$cashId]['issues'][] = 'protected_proceeds_spending_requires_allocation';
                }
                $economicCost = BigInteger::zero();
                foreach (DB::table('journal_lines')->where('operation_id', $entry->id)
                    ->where('account_id', $accountId)->where('side', 'credit')->pluck('amount_rial') as $cost) {
                    $economicCost = $economicCost->plus((string) $cost);
                }
                foreach (DB::table('replay_adjustments')->join('operations', 'operations.id', '=', 'replay_adjustments.adjustment_operation_id')
                    ->where('replay_adjustments.affected_operation_id', $entry->id)
                    ->where('operations.effective_date', '<=', $date)->pluck('replay_adjustments.cost_delta_rial') as $delta) {
                    $economicCost = $economicCost->plus((string) $delta);
                }
                $sales[] = array_merge($split, [
                    'operation_id' => (int) $entry->id, 'lot_id' => (int) $lot->id,
                    'account_id' => $accountId, 'cash_account_id' => $cashId,
                    'effective_date' => $entry->effective_date,
                    'exit_atoms' => substr($entry->signed_atoms, 1),
                    'net_proceeds_rial' => (string) $target->amount_rial,
                    'economic_cost_rial' => (string) $economicCost,
                    'evidence' => 'user_declared_whole_opening_lot',
                ]);
            }
            if ($valid && ! $this->applyAnchors($anchors, $date, $remaining, $basis)) {
                $valid = false;
                $position['issues'][] = 'settled_lot_anchor_requires_review';
            }
            $position['remaining_atoms'] = $remaining;
            if ($valid) {
                $position['status'] = 'traced';
                $position['protected_basis_exact_rial'] = $basis;
            } else {
                // A later ambiguous movement invalidates the account's trace;
                // its earlier sale evidence remains visible but cannot finalize a total.
                foreach ($cash as &$cashPosition) {
                    foreach ($sales as $sale) {
                        if ($sale['account_id'] === $accountId
                            && in_array($sale['operation_id'], $cashPosition['sale_operation_ids'], true)) {
                            $cashPosition['status'] = 'needs_review';
                            $cashPosition['issues'][] = 'source_lot_history_requires_review';
                        }
                    }
                }
                unset($cashPosition);
            }
            $holdings[$accountId] = $position;
        }
        $tracedSaleIds = array_column($sales, 'operation_id');
        foreach (DB::table('operations')->where('type', 'asset_sell')->where('effective_date', '<=', $date)->orderBy('id')->get(['id']) as $sale) {
            if (in_array((int) $sale->id, $tracedSaleIds, true)) {
                continue;
            }
            $targets = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
                ->where('journal_lines.operation_id', $sale->id)->where('journal_lines.side', 'debit')
                ->whereIn('accounts.subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])->pluck('accounts.id');
            foreach ($targets as $target) {
                $cashId = (int) $target;
                $cash[$cashId] ??= ['account_id' => $cashId, 'status' => 'needs_review',
                    'protected_basis_exact_rial' => '0', 'sale_operation_ids' => [], 'issues' => []];
                $cash[$cashId]['status'] = 'needs_review';
                $cash[$cashId]['issues'][] = 'source_lot_history_requires_review';
            }
        }
        $result = ['holdings' => array_values($holdings), 'cash_positions' => array_values($cash), 'sales' => $sales];
        $result['hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function applyAnchors(array &$anchors, string $throughDate, string &$remaining, string &$basis): bool
    {
        while ($anchors !== [] && $anchors[0]['effective_date'] <= $throughDate) {
            $anchor = array_shift($anchors);
            if (! BigInteger::of($remaining)->isEqualTo($anchor['covered_atoms'])) {
                return false;
            }
            $basis = $anchor['protected_basis_rial'];
        }

        return true;
    }
}
