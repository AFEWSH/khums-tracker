<?php

namespace App\Actions\Finance;

use App\Support\Numbers\FifoLots;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Rebuilds economic FIFO and conservative cash-origin bounds from immutable posted facts. */
final class ReplayProjection
{
    public function rebuild(int $triggerOperationId, string $fromDate): int
    {
        Gate::authorize('access-app');
        $operations = DB::table('operations')->where('type', '!=', 'replay_adjustment')
            ->orderBy('effective_date')->orderBy('sequence')->orderBy('id')->get();
        $inputHash = hash('sha256', json_encode($operations->map(fn ($op) => [$op->id, $op->effective_date, $op->sequence, $op->payload_hash])->all(), JSON_THROW_ON_ERROR));
        $revision = DB::table('projection_revisions')->insertGetId([
            'trigger_operation_id' => $triggerOperationId, 'from_effective_date' => $fromDate,
            'input_hash' => $inputHash, 'policy_version' => 'fifo-economic-v1',
            'actor_id' => auth()->id(), 'created_at' => now(),
        ]);
        $this->rebuildLots($operations, $revision, $triggerOperationId);
        $this->rebuildCash($operations, $revision);

        return $revision;
    }

    private function rebuildLots($operations, int $revision, int $triggerOperationId): void
    {
        $lotStates = [];
        $allLots = DB::table('lots')->orderBy('id')->get();
        foreach ($operations as $operation) {
            if (in_array($operation->type, ['opening', 'asset_buy', 'asset_convert'], true)) {
                foreach ($allLots->where('acquisition_operation_id', $operation->id) as $lot) {
                    $origin = DB::table('asset_origin_events')->where('lot_id', $lot->id)->orderBy('id')->first();
                    if ($origin === null) {
                        throw new FinanceInputException('Lot origin missing at operation '.$operation->public_id.'.');
                    }
                    $effectiveCost = MoneyRial::fromRial((string) $lot->cost_rial);
                    foreach (DB::table('lot_basis_adjustments')->where('lot_id', $lot->id)->pluck('delta_rial') as $basisDelta) {
                        $effectiveCost = $effectiveCost->add(MoneyRial::fromRial((string) $basisDelta));
                    }
                    if ($effectiveCost->rial()[0] === '-') {
                        throw new FinanceInputException('Corrected opening lot basis cannot be negative.');
                    }
                    $lotStates[(int) $lot->id] = [
                        'account_id' => (int) $lot->account_id,
                        'atoms' => (string) $lot->acquired_atoms,
                        'cost' => $effectiveCost,
                        'min' => BigInteger::of((string) $origin->protected_min_atoms),
                        'max' => BigInteger::of((string) $origin->protected_max_atoms),
                    ];
                }
            }
            if (! in_array($operation->type, ['asset_sell', 'asset_convert'], true)) {
                continue;
            }
            $exit = DB::table('quantity_entries')->where('operation_id', $operation->id)
                ->where('signed_atoms', 'like', '-%')->first();
            if ($exit === null) {
                throw new FinanceInputException('Asset exit quantity missing at operation '.$operation->public_id.'.');
            }
            $available = [];
            foreach ($lotStates as $lotId => $state) {
                if ($state['account_id'] === (int) $exit->account_id) {
                    $available[] = (object) ['id' => $lotId, 'remaining_atoms' => $state['atoms'], 'remaining_cost_rial' => $state['cost']->rial()];
                }
            }
            try {
                $plan = FifoLots::allocate($available, substr((string) $exit->signed_atoms, 1));
            } catch (\InvalidArgumentException) {
                throw new FinanceInputException('Operation '.$operation->public_id.' on '.$operation->effective_date.' lacks asset quantity after replay.');
            }
            foreach ($plan['allocations'] as $allocation) {
                $state = &$lotStates[$allocation['lot_id']];
                $taken = BigInteger::of($allocation['atoms']);
                $state['atoms'] = (string) BigInteger::of($state['atoms'])->minus($taken);
                $state['cost'] = $state['cost']->subtract(MoneyRial::fromRial($allocation['cost_rial']));
                $newMin = $state['min']->minus($taken);
                $state['min'] = $newMin->isNegative() ? BigInteger::zero() : $newMin;
                $remaining = BigInteger::of($state['atoms']);
                $state['max'] = $state['max']->compareTo($remaining) > 0 ? $remaining : $state['max'];
                DB::table('lot_allocation_projections')->insert([
                    'projection_revision_id' => $revision, 'exit_operation_id' => $operation->id,
                    'source_lot_id' => $allocation['lot_id'], 'atoms' => $allocation['atoms'],
                    'allocated_cost_rial' => $allocation['cost_rial'],
                ]);
                unset($state);
            }
            $originalCost = MoneyRial::fromRial('0');
            foreach (DB::table('journal_lines')->where('operation_id', $operation->id)
                ->where('account_id', $exit->account_id)->where('side', 'credit')->pluck('amount_rial') as $amount) {
                $originalCost = $originalCost->add(MoneyRial::fromRial((string) $amount));
            }
            $previous = MoneyRial::fromRial('0');
            foreach (DB::table('replay_adjustments')->where('affected_operation_id', $operation->id)->pluck('cost_delta_rial') as $amount) {
                $previous = $previous->add(MoneyRial::fromRial((string) $amount));
            }
            $delta = MoneyRial::fromRial($plan['cost_rial'])->subtract($originalCost)->subtract($previous);
            if ($delta->rial() !== '0') {
                $this->postCostAdjustment($operation, (int) $exit->account_id, $delta, $revision);
            }
        }
        foreach ($lotStates as $lotId => $state) {
            DB::table('lots')->where('id', $lotId)->update([
                'remaining_atoms' => $state['atoms'], 'remaining_cost_rial' => $state['cost']->rial(), 'updated_at' => now(),
            ]);
            DB::table('replay_lot_positions')->insert([
                'projection_revision_id' => $revision, 'lot_id' => $lotId,
                'remaining_atoms' => $state['atoms'], 'remaining_cost_rial' => $state['cost']->rial(),
                'protected_min_atoms' => (string) $state['min'], 'protected_max_atoms' => (string) $state['max'],
            ]);
            DB::table('asset_origin_events')->insert([
                'operation_id' => $triggerOperationId, 'lot_id' => $lotId, 'kind' => 'replay',
                'protected_min_atoms' => (string) $state['min'], 'protected_max_atoms' => (string) $state['max'],
                'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('asset_accounts')->pluck('account_id') as $accountId) {
            $book = MoneyRial::fromRial('0');
            foreach ($lotStates as $state) {
                if ($state['account_id'] === (int) $accountId) {
                    $book = $book->add($state['cost']);
                }
            }
            $journal = $this->journalBalance((int) $accountId, 'asset');
            if ($book->rial() !== $journal->rial()) {
                throw new FinanceInputException('Asset journal and FIFO projection differ for account '.$accountId.'.');
            }
            DB::table('accounts')->where('id', $accountId)->update(['balance_rial' => $book->rial(), 'updated_at' => now()]);
        }
    }

    private function postCostAdjustment(object $affected, int $assetAccountId, MoneyRial $delta, int $revision): void
    {
        $absolute = $delta->rial()[0] === '-' ? MoneyRial::fromRial(substr($delta->rial(), 1)) : $delta;
        DB::table('accounts')->insertOrIgnore([
            'code' => 'system-realized-gain', 'name' => 'سود تحقق‌یافته دارایی',
            'class' => 'income', 'subtype' => 'realized-gain', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gainAccount = (int) DB::table('accounts')->where('code', 'system-realized-gain')->value('id');
        $sequence = (int) DB::table('operations')->where('effective_date', $affected->effective_date)->max('sequence') + 1;
        $hash = hash('sha256', json_encode([$revision, $affected->id, $delta->rial()], JSON_THROW_ON_ERROR));
        $id = DB::table('operations')->insertGetId([
            'public_id' => (string) Str::uuid(), 'type' => 'replay_adjustment',
            'effective_date' => $affected->effective_date, 'time_precision' => 'date',
            'boundary_relation' => $affected->boundary_relation, 'sequence' => $sequence,
            'idempotency_key' => 'replay-adjust-'.$revision.'-'.$affected->id,
            'payload_hash' => $hash, 'actor_id' => auth()->id(),
            'reason' => 'اصلاح بهای FIFO پس از ثبت گذشته',
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $positive = $delta->rial()[0] !== '-';
        foreach ([[$assetAccountId, $positive ? 'credit' : 'debit'], [$gainAccount, $positive ? 'debit' : 'credit']] as $i => [$accountId, $side]) {
            DB::table('journal_lines')->insert([
                'operation_id' => $id, 'account_id' => $accountId,
                'side' => $side, 'amount_rial' => $absolute->rial(), 'line_no' => $i + 1,
            ]);
        }
        DB::table('replay_adjustments')->insert([
            'adjustment_operation_id' => $id, 'projection_revision_id' => $revision,
            'affected_operation_id' => $affected->id, 'cost_delta_rial' => $delta->rial(),
        ]);
        DB::table('audit_events')->insert([
            'operation_id' => $id, 'actor_id' => auth()->id(),
            'action' => 'fifo_replay_adjustment', 'facts_hash' => $hash, 'recorded_at' => now(),
        ]);
    }

    private function rebuildCash($operations, int $revision): void
    {
        $liquidTypes = ['bank', 'cash', 'broker_cash', 'wallet_cash', 'receivable'];
        $positions = [];
        $accounts = DB::table('accounts')->whereIn('subtype', $liquidTypes)->get();
        foreach ($accounts as $account) {
            $positions[(int) $account->id] = ['balance' => MoneyRial::fromRial('0'), 'min' => MoneyRial::fromRial('0'), 'max' => MoneyRial::fromRial('0')];
        }
        foreach ($operations as $operation) {
            $lines = DB::table('journal_lines')->where('operation_id', $operation->id)->get();
            $sourceId = null;
            $movedMin = MoneyRial::fromRial('0');
            $movedMax = MoneyRial::fromRial('0');
            if (in_array($operation->type, ['transfer', 'lend', 'collect'], true)) {
                foreach ($lines as $line) {
                    if ($line->side === 'credit' && isset($positions[(int) $line->account_id])) {
                        $sourceId = (int) $line->account_id;
                        $before = $positions[$sourceId];
                        $amount = MoneyRial::fromRial((string) $line->amount_rial);
                        $unprotected = $before['balance']->subtract($before['min']);
                        $movedMin = $amount->subtract($unprotected);
                        if ($movedMin->rial()[0] === '-') {
                            $movedMin = MoneyRial::fromRial('0');
                        }
                        $movedMax = $amount->subtract($before['max'])->rial()[0] === '-' ? $amount : $before['max'];
                        break;
                    }
                }
            }
            foreach ($lines as $line) {
                $accountId = (int) $line->account_id;
                if (! isset($positions[$accountId])) {
                    continue;
                }
                $position = $positions[$accountId];
                $amount = MoneyRial::fromRial((string) $line->amount_rial);
                if ($line->side === 'debit') {
                    $position['balance'] = $position['balance']->add($amount);
                    if ($operation->type === 'opening') {
                        $openingId = DB::table('accounts')->where('id', $accountId)->value('code');
                        $openingId = str_starts_with((string) $openingId, 'opening-') ? (int) substr((string) $openingId, 8) : 0;
                        $declared = (string) (DB::table('opening_items')->where('id', $openingId)->value('protected_rial') ?? '0');
                        $position['min'] = MoneyRial::fromRial($declared);
                        $position['max'] = $position['balance'];
                    } elseif ($sourceId !== null) {
                        $position['min'] = $position['min']->add($movedMin);
                        $position['max'] = $position['max']->add($movedMax);
                    } else {
                        $position['max'] = $position['max']->add($amount);
                    }
                } else {
                    $position['balance'] = $position['balance']->subtract($amount);
                    $position['min'] = $position['min']->subtract($amount);
                    if ($position['min']->rial()[0] === '-') {
                        $position['min'] = MoneyRial::fromRial('0');
                    }
                    if ($position['max']->subtract($position['balance'])->rial()[0] !== '-') {
                        $position['max'] = $position['balance'];
                    }
                }
                if ($position['balance']->rial()[0] === '-' || $position['max']->subtract($position['min'])->rial()[0] === '-'
                    || $position['balance']->subtract($position['max'])->rial()[0] === '-') {
                    throw new FinanceInputException('Operation '.$operation->public_id.' on '.$operation->effective_date.' makes account '.$accountId.' inconsistent.');
                }
                $positions[$accountId] = $position;
                DB::table('replay_cash_positions')->insert([
                    'projection_revision_id' => $revision, 'operation_id' => $operation->id, 'account_id' => $accountId,
                    'balance_after_rial' => $position['balance']->rial(),
                    'protected_min_after_rial' => $position['min']->rial(),
                    'protected_max_after_rial' => $position['max']->rial(),
                ]);
            }
        }
        foreach ($positions as $accountId => $position) {
            $journal = $this->journalBalance($accountId, 'asset');
            if ($journal->rial() !== $position['balance']->rial()) {
                throw new FinanceInputException('Cash journal and replay projection differ for account '.$accountId.'.');
            }
            DB::table('accounts')->where('id', $accountId)->update([
                'balance_rial' => $position['balance']->rial(),
                'protected_min_rial' => $position['min']->rial(),
                'protected_max_rial' => $position['max']->rial(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('accounts')->where('class', 'liability')->pluck('id') as $accountId) {
            $balance = $this->journalBalance((int) $accountId, 'liability');
            if ($balance->rial()[0] === '-') {
                throw new FinanceInputException('Debt '.$accountId.' would become negative after replay.');
            }
            DB::table('accounts')->where('id', $accountId)->update(['balance_rial' => $balance->rial(), 'updated_at' => now()]);
        }
    }

    private function journalBalance(int $accountId, string $class): MoneyRial
    {
        $balance = MoneyRial::fromRial('0');
        foreach (DB::table('journal_lines')->where('account_id', $accountId)->get(['side', 'amount_rial']) as $line) {
            $amount = MoneyRial::fromRial((string) $line->amount_rial);
            $balance = ($line->side === 'debit') === ($class === 'asset') ? $balance->add($amount) : $balance->subtract($amount);
        }

        return $balance;
    }
}
