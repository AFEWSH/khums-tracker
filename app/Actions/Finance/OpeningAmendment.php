<?php

namespace App\Actions\Finance;

use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OpeningAmendment
{
    public function proposeAssetValue(int $openingItemId, string $correctedToman, string $reason): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        $item = DB::table('asset_opening_items')->where('id', $openingItemId)->first();
        $lot = DB::table('lots')->where('opening_item_id', $openingItemId)->first();
        if ($item === null || $lot === null || $reason === '' || mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('Confirmed asset opening and correction reason are required.');
        }
        $corrected = MoneyRial::fromToman($correctedToman);
        $current = $this->currentAssetValue($item);
        if ($corrected->rial()[0] === '-' || $corrected->rial() === $current->rial()) {
            throw new InvalidArgumentException('Corrected asset value must be nonnegative and different.');
        }
        $proposal = [
            'asset_opening_item_id' => $openingItemId, 'lot_id' => (int) $lot->id,
            'account_id' => (int) $lot->account_id,
            'old_value_rial' => $current->rial(), 'new_value_rial' => $corrected->rial(),
        ];
        $hash = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($reason, $proposal, $hash): int {
            DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

            return DB::table('amendment_cases')->insertGetId([
                'kind' => 'opening_asset_value', 'status' => 'draft', 'reason' => $reason,
                'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR), 'proposal_hash' => $hash,
                'actor_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function approveAssetValue(int $caseId, int $seenRevision, string $idempotencyKey): int
    {
        Gate::authorize('access-app');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid correction key.');
        }

        return DB::transaction(function () use ($caseId, $seenRevision, $idempotencyKey): int {
            $case = DB::table('amendment_cases')->where('id', $caseId)->first();
            if ($case === null || $case->kind !== 'opening_asset_value') {
                throw new InvalidArgumentException('Asset opening amendment does not exist.');
            }
            if ($case->status === 'approved') {
                if (DB::table('operations')->where('id', $case->approved_operation_id)->value('idempotency_key') === $idempotencyKey) {
                    return (int) $case->approved_operation_id;
                }
                throw new FinanceInputException('Asset opening amendment was already approved.');
            }
            $state = DB::table('financial_state')->where('id', 1)->first();
            if ($case->status !== 'draft' || (int) $state->revision !== $seenRevision
                || DB::table('financial_state')->where('id', 1)->where('revision', $seenRevision)
                    ->update(['revision' => $seenRevision + 1, 'updated_at' => now()]) !== 1) {
                throw new FinanceInputException('Financial data changed; review the asset correction again.');
            }
            if (DB::table('operations')->where('effective_date', OpeningSetup::DATE)
                ->whereNotIn('type', ['opening', 'opening_amendment', 'replay_adjustment'])->exists()) {
                throw new FinanceInputException('Opening-day operations need an ordered amendment review.');
            }
            $proposal = json_decode($case->proposal, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($case->proposal_hash, hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)))) {
                throw new FinanceInputException('Asset opening amendment proposal changed.');
            }
            $item = DB::table('asset_opening_items')->where('id', $proposal['asset_opening_item_id'])->first();
            if ($item === null || $this->currentAssetValue($item)->rial() !== $proposal['old_value_rial']) {
                throw new FinanceInputException('Asset opening was amended since this proposal.');
            }
            $delta = MoneyRial::fromRial($proposal['new_value_rial'])->subtract(MoneyRial::fromRial($proposal['old_value_rial']));
            $positive = $delta->rial()[0] !== '-';
            $absolute = $positive ? $delta : MoneyRial::fromRial(substr($delta->rial(), 1));
            DB::table('accounts')->insertOrIgnore([
                'code' => 'opening-equity', 'name' => 'سرمایه افتتاحیه',
                'class' => 'equity', 'subtype' => 'opening', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $equity = (int) DB::table('accounts')->where('code', 'opening-equity')->value('id');
            $sequence = (int) DB::table('operations')->where('effective_date', OpeningSetup::DATE)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'opening_amendment',
                'effective_date' => OpeningSetup::DATE, 'time_precision' => 'date',
                'boundary_relation' => 'unknown', 'sequence' => $sequence,
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $case->proposal_hash,
                'actor_id' => auth()->id(), 'reason' => $case->reason,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$proposal['account_id'], $positive ? 'debit' : 'credit'], [$equity, $positive ? 'credit' : 'debit']] as $index => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $absolute->rial(), 'line_no' => $index + 1,
                ]);
            }
            DB::table('lot_basis_adjustments')->insert([
                'operation_id' => $id, 'lot_id' => $proposal['lot_id'], 'delta_rial' => $delta->rial(),
            ]);
            DB::table('amendment_cases')->where('id', $caseId)->update([
                'status' => 'approved', 'approved_operation_id' => $id,
                'approved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(),
                'action' => 'opening_asset_value_amended', 'facts_hash' => $case->proposal_hash,
                'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, OpeningSetup::DATE);

            return $id;
        });
    }

    public function proposeCashAmount(int $openingItemId, string $correctedToman, string $reason): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('Opening correction needs a reason.');
        }
        $item = DB::table('opening_items')->where('id', $openingItemId)->first();
        $account = DB::table('accounts')->where('code', 'opening-'.$openingItemId)->first();
        if ($item === null || $account === null || DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
            throw new InvalidArgumentException('Confirmed cash opening item does not exist.');
        }
        $corrected = MoneyRial::fromToman($correctedToman);
        $protected = MoneyRial::fromRial((string) $item->protected_rial);
        if ($corrected->rial()[0] === '-' || ($item->kind !== 'payable' && $corrected->subtract($protected)->rial()[0] === '-')) {
            throw new InvalidArgumentException('Corrected amount cannot be negative or below declared protected capital.');
        }
        $current = $this->currentAmount($item);
        if ($corrected->rial() === $current->rial()) {
            throw new InvalidArgumentException('Corrected amount equals current opening amount.');
        }
        $proposal = [
            'opening_item_id' => $openingItemId, 'account_id' => (int) $account->id,
            'kind' => $item->kind, 'old_amount_rial' => $current->rial(),
            'new_amount_rial' => $corrected->rial(), 'protected_rial' => $protected->rial(),
        ];
        $hash = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($reason, $proposal, $hash): int {
            DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

            return DB::table('amendment_cases')->insertGetId([
                'kind' => 'opening_cash_amount', 'status' => 'draft', 'reason' => $reason,
                'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR), 'proposal_hash' => $hash,
                'actor_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function approve(int $caseId, int $seenRevision, string $idempotencyKey): int
    {
        Gate::authorize('access-app');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid correction key.');
        }

        return DB::transaction(function () use ($caseId, $seenRevision, $idempotencyKey): int {
            $case = DB::table('amendment_cases')->where('id', $caseId)->first();
            if ($case === null || $case->kind !== 'opening_cash_amount') {
                throw new InvalidArgumentException('Opening amendment does not exist.');
            }
            if ($case->status === 'approved') {
                if (DB::table('operations')->where('id', $case->approved_operation_id)->value('idempotency_key') === $idempotencyKey) {
                    return (int) $case->approved_operation_id;
                }
                throw new FinanceInputException('Opening amendment was already approved.');
            }
            if ($case->status !== 'draft') {
                throw new FinanceInputException('Opening amendment is not ready.');
            }
            $state = DB::table('financial_state')->where('id', 1)->first();
            if ((int) $state->revision !== $seenRevision || DB::table('financial_state')->where('id', 1)
                ->where('revision', $seenRevision)->update(['revision' => $seenRevision + 1, 'updated_at' => now()]) !== 1) {
                throw new FinanceInputException('Financial data changed; review the correction again.');
            }
            if (DB::table('operations')->where('effective_date', OpeningSetup::DATE)
                ->whereNotIn('type', ['opening', 'opening_amendment', 'replay_adjustment'])->exists()) {
                throw new FinanceInputException('Opening-day operations need an ordered amendment review.');
            }
            $proposal = json_decode($case->proposal, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($case->proposal_hash, hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)))) {
                throw new FinanceInputException('Opening amendment proposal changed.');
            }
            $item = DB::table('opening_items')->where('id', $proposal['opening_item_id'])->first();
            if ($item === null || $this->currentAmount($item)->rial() !== $proposal['old_amount_rial']) {
                throw new FinanceInputException('Opening was amended since this proposal.');
            }
            $delta = MoneyRial::fromRial($proposal['new_amount_rial'])->subtract(MoneyRial::fromRial($proposal['old_amount_rial']));
            $positive = $delta->rial()[0] !== '-';
            $absolute = $positive ? $delta : MoneyRial::fromRial(substr($delta->rial(), 1));
            DB::table('accounts')->insertOrIgnore([
                'code' => 'opening-equity', 'name' => 'سرمایه افتتاحیه',
                'class' => 'equity', 'subtype' => 'opening', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $equity = (int) DB::table('accounts')->where('code', 'opening-equity')->value('id');
            $asset = $item->kind !== 'payable';
            $accountSide = $asset === $positive ? 'debit' : 'credit';
            $sequence = (int) DB::table('operations')->where('effective_date', OpeningSetup::DATE)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'opening_amendment',
                'effective_date' => OpeningSetup::DATE, 'time_precision' => 'date',
                'boundary_relation' => 'unknown', 'sequence' => $sequence,
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $case->proposal_hash,
                'actor_id' => auth()->id(), 'reason' => $case->reason,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$proposal['account_id'], $accountSide], [$equity, $accountSide === 'debit' ? 'credit' : 'debit']] as $index => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $absolute->rial(), 'line_no' => $index + 1,
                ]);
            }
            DB::table('amendment_cases')->where('id', $caseId)->update([
                'status' => 'approved', 'approved_operation_id' => $id,
                'approved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(),
                'action' => 'opening_cash_amended', 'facts_hash' => $case->proposal_hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, OpeningSetup::DATE);

            return $id;
        });
    }

    private function currentAmount(object $item): MoneyRial
    {
        $current = MoneyRial::fromRial((string) $item->amount_rial);
        foreach (DB::table('amendment_cases')->where('kind', 'opening_cash_amount')->where('status', 'approved')->orderBy('id')->get(['proposal']) as $case) {
            $proposal = json_decode($case->proposal, true, flags: JSON_THROW_ON_ERROR);
            if ((int) $proposal['opening_item_id'] === (int) $item->id) {
                $current = MoneyRial::fromRial($proposal['new_amount_rial']);
            }
        }

        return $current;
    }

    private function currentAssetValue(object $item): MoneyRial
    {
        $current = MoneyRial::fromRial((string) $item->opening_value_rial);
        foreach (DB::table('amendment_cases')->where('kind', 'opening_asset_value')->where('status', 'approved')->orderBy('id')->get(['proposal']) as $case) {
            $proposal = json_decode($case->proposal, true, flags: JSON_THROW_ON_ERROR);
            if ((int) $proposal['asset_opening_item_id'] === (int) $item->id) {
                $current = MoneyRial::fromRial($proposal['new_value_rial']);
            }
        }

        return $current;
    }
}
