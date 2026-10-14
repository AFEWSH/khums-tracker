<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\FifoLots;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssetDisposal
{
    /** @param array{source_id:int,cash_id:int,date:string,quantity:string,gross_toman:string,fee_toman:string,idempotency_key:string,after_opening?:bool} $input */
    public function sell(array $input): int
    {
        return $this->post($input, 'asset_sell');
    }

    /** @param array{source_id:int,target_id:int,cash_id?:int,date:string,quantity:string,target_quantity:string,gross_toman:string,fee_toman:string,idempotency_key:string,after_opening?:bool} $input */
    public function convert(array $input): int
    {
        return $this->post($input, 'asset_convert');
    }

    private function post(array $input, string $type): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Confirm the exit is after the opening.');
        }
        $key = $input['idempotency_key'] ?? '';
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
        $sourceId = $input['source_id'] ?? 0;
        $targetId = $type === 'asset_convert' ? ($input['target_id'] ?? 0) : null;
        $cashId = $input['cash_id'] ?? null;
        if (! is_int($sourceId) || $sourceId < 1 || ($targetId !== null && (! is_int($targetId) || $targetId < 1))
            || ($cashId !== null && (! is_int($cashId) || $cashId < 1)) || $sourceId === $targetId) {
            throw new InvalidArgumentException('Invalid trade accounts.');
        }
        $source = $this->holding($sourceId);
        $target = $targetId === null ? null : $this->holding($targetId);
        if ($target !== null && (int) $source->instrument_id === (int) $target->instrument_id) {
            throw new InvalidArgumentException('Same-instrument holding transfer needs its own path.');
        }
        $quantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $source->quantity_scale);
        $targetQuantity = $target === null ? null : AssetQuantity::fromDecimal($input['target_quantity'] ?? '', (int) $target->quantity_scale);
        $gross = MoneyRial::fromToman($input['gross_toman'] ?? '');
        $fee = MoneyRial::fromToman($input['fee_toman'] ?? '');
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-'
            || ($targetQuantity !== null && ($targetQuantity->atoms() === '0' || $targetQuantity->atoms()[0] === '-'))
            || $gross->rial() === '0' || $gross->rial()[0] === '-' || $fee->rial()[0] === '-'
            || ($type === 'asset_sell' && ($cashId === null || $gross->subtract($fee)->rial()[0] === '-'))
            || ($type === 'asset_convert' && $fee->rial() !== '0' && $cashId === null)) {
            throw new InvalidArgumentException('Invalid quantity, consideration, fee or cash account.');
        }
        $payload = [$type, $sourceId, $targetId, $cashId, $date, $quantity->atoms(), $targetQuantity?->atoms(), $gross->rial(), $fee->rial(), ($input['after_opening'] ?? false) === true];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($type, $sourceId, $targetId, $cashId, $source, $target, $date, $quantity, $targetQuantity, $gross, $fee, $key, $hash): int {
            $this->lockState();
            $duplicate = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($duplicate !== null) {
                if ($duplicate->type !== $type || ! hash_equals($duplicate->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $duplicate->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            $latest = DB::table('operations')->max('effective_date');
            if ($latest !== null && $date < $latest) {
                throw new FinanceInputException('Backdated disposal needs phase-five replay.');
            }
            $lots = DB::table('lots')->join('operations', 'operations.id', '=', 'lots.acquisition_operation_id')
                ->where('lots.account_id', $sourceId)
                ->orderBy('operations.effective_date')->orderBy('operations.sequence')->orderBy('lots.id')
                ->get(['lots.*']);
            try {
                $plan = FifoLots::allocate($lots, $quantity->atoms());
            } catch (InvalidArgumentException $error) {
                throw new FinanceInputException('Insufficient asset quantity for FIFO exit.', previous: $error);
            }
            $cost = MoneyRial::fromRial($plan['cost_rial']);
            $gain = $gross->subtract($cost);
            $cash = $cashId === null ? null : DB::table('accounts')->where('id', $cashId)->whereNull('archived_at')->first();
            if ($cashId !== null && ($cash === null || $cash->class !== 'asset' || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true))) {
                throw new InvalidArgumentException('Invalid cash account.');
            }
            if ($type === 'asset_convert' && $fee->rial() !== '0' && MoneyRial::fromRial((string) $cash->balance_rial)->subtract($fee)->rial()[0] === '-') {
                throw new FinanceInputException('Insufficient cash for conversion fee.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => $type, 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lines = [];
            if ($type === 'asset_sell') {
                $lines[] = [$cashId, 'debit', $gross->subtract($fee)];
            } else {
                $lines[] = [$targetId, 'debit', $gross->add($fee)];
                if ($fee->rial() !== '0') {
                    $lines[] = [$cashId, 'credit', $fee];
                }
            }
            if ($fee->rial() !== '0' && $type === 'asset_sell') {
                $lines[] = [$this->systemAccount('sale-fee', 'expense', 'کارمزد فروش دارایی'), 'debit', $fee];
            }
            $lines[] = [$sourceId, 'credit', $cost];
            if ($gain->rial()[0] === '-') {
                $lines[] = [$this->systemAccount('realized-loss', 'expense', 'زیان فروش دارایی'), 'debit', MoneyRial::fromRial(substr($gain->rial(), 1))];
            } elseif ($gain->rial() !== '0') {
                $lines[] = [$this->systemAccount('realized-gain', 'income', 'سود تحقق‌یافته دارایی'), 'credit', $gain];
            }
            $debits = MoneyRial::fromRial('0');
            $credits = MoneyRial::fromRial('0');
            $lineNo = 1;
            foreach ($lines as [$accountId, $side, $amount]) {
                if ($amount->rial() === '0') {
                    continue;
                }
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $amount->rial(), 'line_no' => $lineNo++,
                ]);
                if ($side === 'debit') {
                    $debits = $debits->add($amount);
                } else {
                    $credits = $credits->add($amount);
                }
            }
            if ($debits->rial() !== $credits->rial()) {
                throw new FinanceInputException('Asset journal is not balanced.');
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $sourceId,
                'instrument_id' => $source->instrument_id, 'signed_atoms' => '-'.$quantity->atoms(), 'line_no' => 1,
            ]);
            if ($target !== null) {
                DB::table('quantity_entries')->insert([
                    'operation_id' => $id, 'account_id' => $targetId,
                    'instrument_id' => $target->instrument_id, 'signed_atoms' => $targetQuantity->atoms(), 'line_no' => 2,
                ]);
            }
            $this->consumeLots($plan['allocations'], $id);
            $sourceAccount = DB::table('accounts')->find($sourceId);
            $afterSource = MoneyRial::fromRial((string) $sourceAccount->balance_rial)->subtract($cost);
            if ($afterSource->rial()[0] === '-') {
                throw new FinanceInputException('Asset book balance would become negative.');
            }
            DB::table('accounts')->where('id', $sourceId)->update(['balance_rial' => $afterSource->rial(), 'updated_at' => now()]);
            if ($target !== null) {
                $targetCost = $gross->add($fee);
                DB::table('lots')->insert([
                    'acquisition_operation_id' => $id, 'account_id' => $targetId,
                    'instrument_id' => $target->instrument_id,
                    'acquired_atoms' => $targetQuantity->atoms(), 'remaining_atoms' => $targetQuantity->atoms(),
                    'cost_rial' => $targetCost->rial(), 'remaining_cost_rial' => $targetCost->rial(),
                    'historical_cost_rial' => $targetCost->rial(), 'cost_basis_kind' => 'exchange',
                    'acquired_date' => $date, 'acquisition_method' => 'conversion',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $targetLotId = (int) DB::table('lots')->where('acquisition_operation_id', $id)->value('id');
                DB::table('asset_origin_events')->insert([
                    'operation_id' => $id, 'lot_id' => $targetLotId, 'kind' => 'conversion',
                    'protected_min_atoms' => '0', 'protected_max_atoms' => $targetQuantity->atoms(),
                    'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $targetAccount = DB::table('accounts')->find($targetId);
                $afterTarget = MoneyRial::fromRial((string) $targetAccount->balance_rial)->add($targetCost);
                DB::table('accounts')->where('id', $targetId)->update(['balance_rial' => $afterTarget->rial(), 'updated_at' => now()]);
                if ($fee->rial() !== '0') {
                    $this->moveCash($cash, $fee, false, $id, 'conversion_fee');
                }
            } else {
                $this->moveCash($cash, $gross->subtract($fee), true, $id, 'asset_sale');
            }
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(), 'action' => $type,
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    /** @param array<int,array{lot_id:int,atoms:string,cost_rial:string}> $allocations */
    private function consumeLots(array $allocations, int $operationId): void
    {
        foreach ($allocations as $allocation) {
            $lot = DB::table('lots')->find($allocation['lot_id']);
            $beforeAtoms = BigInteger::of((string) $lot->remaining_atoms);
            $taken = BigInteger::of($allocation['atoms']);
            $afterAtoms = $beforeAtoms->minus($taken);
            $afterCost = MoneyRial::fromRial((string) $lot->remaining_cost_rial)->subtract(MoneyRial::fromRial($allocation['cost_rial']));
            DB::table('lot_allocations')->insert([
                'exit_operation_id' => $operationId, 'source_lot_id' => $lot->id,
                'atoms' => $allocation['atoms'], 'allocated_cost_rial' => $allocation['cost_rial'],
            ]);
            DB::table('lots')->where('id', $lot->id)->update([
                'remaining_atoms' => (string) $afterAtoms, 'remaining_cost_rial' => $afterCost->rial(), 'updated_at' => now(),
            ]);
            $origin = DB::table('asset_origin_events')->where('lot_id', $lot->id)->orderByDesc('id')->first();
            if ($origin === null) {
                throw new FinanceInputException('Lot origin is missing.');
            }
            $minimum = BigInteger::of((string) $origin->protected_min_atoms);
            $maximum = BigInteger::of((string) $origin->protected_max_atoms);
            $newMin = $minimum->minus($taken);
            if ($newMin->isNegative()) {
                $newMin = BigInteger::zero();
            }
            $newMax = $maximum->compareTo($afterAtoms) > 0 ? $afterAtoms : $maximum;
            DB::table('asset_origin_events')->insert([
                'operation_id' => $operationId, 'lot_id' => $lot->id, 'kind' => 'exit',
                'protected_min_atoms' => (string) $newMin, 'protected_max_atoms' => (string) $newMax,
                'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function moveCash(object $cash, MoneyRial $amount, bool $increase, int $operationId, string $origin): void
    {
        $before = MoneyRial::fromRial((string) $cash->balance_rial);
        $after = $increase ? $before->add($amount) : $before->subtract($amount);
        if ($after->rial()[0] === '-') {
            throw new FinanceInputException('Insufficient cash for fee.');
        }
        $min = MoneyRial::fromRial((string) $cash->protected_min_rial);
        $max = MoneyRial::fromRial((string) $cash->protected_max_rial);
        if ($increase) {
            $max = $max->add($amount);
        } else {
            $min = $min->subtract($amount);
            if ($min->rial()[0] === '-') {
                $min = MoneyRial::fromRial('0');
            }
            $max = $max->subtract($after)->rial()[0] === '-' ? $max : $after;
        }
        DB::table('accounts')->where('id', $cash->id)->update([
            'balance_rial' => $after->rial(), 'protected_min_rial' => $min->rial(),
            'protected_max_rial' => $max->rial(), 'updated_at' => now(),
        ]);
        DB::table('provenance_events')->insert([
            'operation_id' => $operationId, 'account_id' => $cash->id, 'origin_kind' => $origin,
            'balance_after_rial' => $after->rial(), 'protected_min_after_rial' => $min->rial(),
            'protected_max_after_rial' => $max->rial(), 'evidence' => 'mixed_unresolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function holding(int $id): object
    {
        return DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $id)->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first()
            ?? throw new InvalidArgumentException('Asset holding does not exist.');
    }

    private function systemAccount(string $code, string $class, string $name): int
    {
        DB::table('accounts')->insertOrIgnore([
            'code' => 'system-'.$code, 'name' => $name, 'class' => $class, 'subtype' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('accounts')->where('code', 'system-'.$code)->value('id');
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }
}
