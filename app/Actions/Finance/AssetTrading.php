<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssetTrading
{
    /** @param array{account_id:int,cash_account_id:int,date:string,quantity:string,price_toman:string,fee_toman:string,idempotency_key:string,after_opening?:bool} $input */
    public function buy(array $input): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Confirm the purchase is after the opening.');
        }
        $key = $input['idempotency_key'] ?? '';
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
        $assetAccountId = $input['account_id'] ?? 0;
        $cashAccountId = $input['cash_account_id'] ?? 0;
        if (! is_int($assetAccountId) || ! is_int($cashAccountId) || $assetAccountId < 1 || $cashAccountId < 1) {
            throw new InvalidArgumentException('Invalid accounts.');
        }
        $asset = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $assetAccountId)->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first()
            ?? throw new InvalidArgumentException('Asset holding does not exist.');
        $quantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $asset->quantity_scale);
        $price = MoneyRial::fromToman($input['price_toman'] ?? '');
        $fee = MoneyRial::fromToman($input['fee_toman'] ?? '');
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-'
            || $price->rial() === '0' || $price->rial()[0] === '-' || $fee->rial()[0] === '-') {
            throw new InvalidArgumentException('Purchase quantity and price must be positive.');
        }
        $total = $price->add($fee);
        $payload = [$assetAccountId, $cashAccountId, $date, $quantity->atoms(), $price->rial(), $fee->rial(), ($input['after_opening'] ?? false) === true];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($assetAccountId, $cashAccountId, $asset, $date, $quantity, $total, $price, $fee, $key, $hash): int {
            $this->lockState();
            $duplicate = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($duplicate !== null) {
                if ($duplicate->type !== 'asset_buy' || ! hash_equals($duplicate->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $duplicate->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            $latest = DB::table('operations')->max('effective_date');
            if ($latest !== null && $date < $latest) {
                throw new FinanceInputException('Backdated purchase needs phase-five replay.');
            }
            $cash = DB::table('accounts')->where('id', $cashAccountId)->whereNull('archived_at')->first();
            if ($cash === null || $cash->class !== 'asset' || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
                throw new InvalidArgumentException('Purchase needs a cash account.');
            }
            $cashAfter = MoneyRial::fromRial((string) $cash->balance_rial)->subtract($total);
            if ($cashAfter->rial()[0] === '-') {
                throw new FinanceInputException('Insufficient cash for purchase and fee.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $operationId = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'asset_buy', 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$assetAccountId, 'debit'], [$cashAccountId, 'credit']] as $i => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $operationId, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $total->rial(), 'line_no' => $i + 1,
                ]);
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $operationId, 'account_id' => $assetAccountId,
                'instrument_id' => $asset->instrument_id, 'signed_atoms' => $quantity->atoms(), 'line_no' => 1,
            ]);
            DB::table('lots')->insert([
                'acquisition_operation_id' => $operationId, 'account_id' => $assetAccountId,
                'instrument_id' => $asset->instrument_id, 'acquired_atoms' => $quantity->atoms(),
                'remaining_atoms' => $quantity->atoms(), 'cost_rial' => $total->rial(),
                'remaining_cost_rial' => $total->rial(), 'historical_cost_rial' => $total->rial(),
                'cost_basis_kind' => 'purchase', 'acquired_date' => $date, 'acquisition_method' => 'purchase',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $assetAccount = DB::table('accounts')->where('id', $assetAccountId)->first();
            $assetAfter = MoneyRial::fromRial((string) $assetAccount->balance_rial)->add($total);
            DB::table('accounts')->where('id', $assetAccountId)->update(['balance_rial' => $assetAfter->rial(), 'updated_at' => now()]);
            $min = MoneyRial::fromRial((string) $cash->protected_min_rial)->subtract($total);
            $max = MoneyRial::fromRial((string) $cash->protected_max_rial);
            $min = $min->rial()[0] === '-' ? MoneyRial::fromRial('0') : $min;
            $max = $max->subtract($cashAfter)->rial()[0] === '-' ? $max : $cashAfter;
            DB::table('accounts')->where('id', $cashAccountId)->update([
                'balance_rial' => $cashAfter->rial(), 'protected_min_rial' => $min->rial(),
                'protected_max_rial' => $max->rial(), 'updated_at' => now(),
            ]);
            DB::table('provenance_events')->insert([
                'operation_id' => $operationId, 'account_id' => $cashAccountId, 'origin_kind' => 'asset_buy',
                'balance_after_rial' => $cashAfter->rial(), 'protected_min_after_rial' => $min->rial(),
                'protected_max_after_rial' => $max->rial(), 'evidence' => 'mixed_unresolved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $lotId = (int) DB::table('lots')->where('acquisition_operation_id', $operationId)->value('id');
            DB::table('asset_origin_events')->insert([
                'operation_id' => $operationId, 'lot_id' => $lotId, 'kind' => 'purchase',
                'protected_min_atoms' => '0', 'protected_max_atoms' => $quantity->atoms(),
                'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $operationId, 'actor_id' => auth()->id(), 'action' => 'asset_bought',
                'facts_hash' => hash('sha256', json_encode([$hash, $price->rial(), $fee->rial()], JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $operationId;
        });
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }
}
