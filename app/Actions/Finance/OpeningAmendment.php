<?php

namespace App\Actions\Finance;

use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OpeningAmendment
{
    public function proposeAssetQuantityOrigin(int $openingItemId, string $quantity, string $protectedQuantity, ?string $protectedBasisToman, string $evidence, string $reason): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        $item = DB::table('asset_opening_items')->join('instruments', 'instruments.id', '=', 'asset_opening_items.instrument_id')
            ->where('asset_opening_items.id', $openingItemId)
            ->first(['asset_opening_items.*', 'instruments.quantity_scale']);
        $lot = DB::table('lots')->where('opening_item_id', $openingItemId)->first();
        if ($item === null || $lot === null || $reason === '' || mb_strlen($reason) > 240
            || ! in_array($evidence, ['unknown', 'user_declared'], true)) {
            throw new InvalidArgumentException('Confirmed asset opening and correction reason are required.');
        }
        $total = AssetQuantity::fromDecimal($quantity, (int) $item->quantity_scale);
        $protected = AssetQuantity::fromDecimal($protectedQuantity, (int) $item->quantity_scale);
        $basis = $protectedBasisToman === null || trim($protectedBasisToman) === '' ? null : MoneyRial::fromToman($protectedBasisToman);
        if ($total->atoms()[0] === '-' || $total->atoms() === '0' || $protected->atoms()[0] === '-'
            || BigInteger::of($protected->atoms())->isGreaterThan($total->atoms())
            || ($protected->atoms() !== '0' && ($evidence !== 'user_declared' || $basis === null))
            || ($protected->atoms() === '0' && $basis !== null)
            || ($basis !== null && $basis->rial()[0] === '-')) {
            throw new InvalidArgumentException('Corrected quantity or protected declaration is inconsistent.');
        }
        $current = $this->currentAssetQuantityOrigin($item);
        if ($current['atoms'] === $total->atoms() && $current['protected_atoms'] === $protected->atoms()
            && $current['basis_rial'] === $basis?->rial() && $current['evidence'] === $evidence) {
            throw new InvalidArgumentException('Corrected asset declaration equals current opening declaration.');
        }
        $proposal = [
            'asset_opening_item_id' => $openingItemId, 'lot_id' => (int) $lot->id,
            'account_id' => (int) $lot->account_id, 'instrument_id' => (int) $lot->instrument_id,
            'old_atoms' => $current['atoms'], 'old_protected_atoms' => $current['protected_atoms'],
            'old_basis_rial' => $current['basis_rial'], 'old_evidence' => $current['evidence'],
            'new_atoms' => $total->atoms(), 'new_protected_atoms' => $protected->atoms(),
            'new_basis_rial' => $basis?->rial(), 'new_evidence' => $evidence,
        ];
        $hash = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($reason, $proposal, $hash): int {
            DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

            return DB::table('amendment_cases')->insertGetId([
                'kind' => 'opening_asset_quantity_origin', 'status' => 'draft', 'reason' => $reason,
                'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR), 'proposal_hash' => $hash,
                'actor_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function approveAssetQuantityOrigin(int $caseId, int $seenRevision, string $idempotencyKey): int
    {
        Gate::authorize('access-app');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid correction key.');
        }

        return DB::transaction(function () use ($caseId, $seenRevision, $idempotencyKey): int {
            $case = DB::table('amendment_cases')->where('id', $caseId)->first();
            if ($case === null || $case->kind !== 'opening_asset_quantity_origin') {
                throw new InvalidArgumentException('Asset quantity correction does not exist.');
            }
            if ($case->status === 'approved') {
                if (DB::table('operations')->where('id', $case->approved_operation_id)->value('idempotency_key') === $idempotencyKey) {
                    return (int) $case->approved_operation_id;
                }
                throw new FinanceInputException('Asset quantity correction was already approved.');
            }
            $state = DB::table('financial_state')->where('id', 1)->first();
            if ($case->status !== 'draft' || (int) $state->revision !== $seenRevision
                || DB::table('financial_state')->where('id', 1)->where('revision', $seenRevision)
                    ->update(['revision' => $seenRevision + 1, 'updated_at' => now()]) !== 1) {
                throw new FinanceInputException('Financial data changed; review this correction again.');
            }
            if (DB::table('operations')->where('effective_date', OpeningSetup::DATE)
                ->whereNotIn('type', ['opening', 'opening_amendment', 'replay_adjustment'])->exists()) {
                throw new FinanceInputException('Opening-day operations need an ordered amendment review.');
            }
            $proposal = json_decode($case->proposal, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($case->proposal_hash, hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)))) {
                throw new FinanceInputException('Asset quantity correction proposal changed.');
            }
            $item = DB::table('asset_opening_items')->where('id', $proposal['asset_opening_item_id'])->first();
            if ($item === null || $this->currentAssetQuantityOrigin($item) !== [
                'atoms' => $proposal['old_atoms'], 'protected_atoms' => $proposal['old_protected_atoms'],
                'basis_rial' => $proposal['old_basis_rial'], 'evidence' => $proposal['old_evidence'],
            ]) {
                throw new FinanceInputException('Asset opening declaration changed since this proposal.');
            }
            $delta = BigInteger::of($proposal['new_atoms'])->minus($proposal['old_atoms']);
            $sequence = (int) DB::table('operations')->where('effective_date', OpeningSetup::DATE)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'opening_amendment',
                'effective_date' => OpeningSetup::DATE, 'time_precision' => 'date',
                'boundary_relation' => 'unknown', 'sequence' => $sequence,
                'idempotency_key' => $idempotencyKey, 'payload_hash' => $case->proposal_hash,
                'actor_id' => auth()->id(), 'reason' => $case->reason,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $delta->isZero()) {
                DB::table('quantity_entries')->insert([
                    'operation_id' => $id, 'account_id' => $proposal['account_id'],
                    'instrument_id' => $proposal['instrument_id'], 'signed_atoms' => (string) $delta, 'line_no' => 1,
                ]);
            }
            DB::table('amendment_cases')->where('id', $caseId)->update([
                'status' => 'approved', 'approved_operation_id' => $id, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('user_decisions')->insert([
                'kind' => 'opening_asset_protected_amendment', 'target_type' => 'asset_opening_item',
                'target_id' => $proposal['asset_opening_item_id'],
                'payload' => json_encode(['protected_atoms' => $proposal['new_protected_atoms'],
                    'basis_rial' => $proposal['new_basis_rial'], 'evidence' => $proposal['new_evidence']], JSON_THROW_ON_ERROR),
                'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(), 'action' => 'opening_asset_quantity_origin_amended',
                'facts_hash' => $case->proposal_hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, OpeningSetup::DATE);

            return $id;
        });
    }

    private function currentAssetQuantityOrigin(object $item): array
    {
        $current = ['atoms' => (string) $item->quantity_atoms, 'protected_atoms' => (string) $item->protected_atoms,
            'basis_rial' => $item->protected_basis_rial === null ? null : (string) $item->protected_basis_rial,
            'evidence' => (string) $item->evidence];
        foreach (DB::table('amendment_cases')->where('kind', 'opening_asset_quantity_origin')
            ->where('status', 'approved')->orderBy('id')->pluck('proposal') as $rawProposal) {
            $proposal = json_decode($rawProposal, true, flags: JSON_THROW_ON_ERROR);
            if ((int) $proposal['asset_opening_item_id'] === (int) $item->id) {
                $current = ['atoms' => $proposal['new_atoms'], 'protected_atoms' => $proposal['new_protected_atoms'],
                    'basis_rial' => $proposal['new_basis_rial'], 'evidence' => $proposal['new_evidence']];
            }
        }

        return $current;
    }

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
