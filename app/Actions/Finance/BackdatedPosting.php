<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BackdatedPosting
{
    /** Historical direct conversion; a provisional balanced journal is completed by FIFO replay before commit. */
    public function convert(array $input): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Historical conversion must be after opening.');
        }
        $latest = DB::table('operations')->where('type', '!=', 'replay_adjustment')->max('effective_date');
        if ($latest === null || $date >= $latest) {
            return app(AssetDisposal::class)->convert($input);
        }
        $sourceId = $input['source_id'] ?? 0;
        $targetId = $input['target_id'] ?? 0;
        $cashId = $input['cash_id'] ?? null;
        $key = $input['idempotency_key'] ?? '';
        if (! is_int($sourceId) || $sourceId < 1 || ! is_int($targetId) || $targetId < 1
            || ($cashId !== null && (! is_int($cashId) || $cashId < 1))
            || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid historical conversion identifiers.');
        }
        $source = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $sourceId)
            ->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first();
        $target = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $targetId)
            ->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first();
        if ($source === null || $target === null || (int) $source->instrument_id === (int) $target->instrument_id) {
            throw new InvalidArgumentException('Conversion requires distinct active holdings.');
        }
        $sourceQuantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $source->quantity_scale);
        $targetQuantity = AssetQuantity::fromDecimal($input['target_quantity'] ?? '', (int) $target->quantity_scale);
        $gross = MoneyRial::fromToman($input['gross_toman'] ?? '');
        $fee = MoneyRial::fromToman($input['fee_toman'] ?? '');
        if ($sourceQuantity->atoms() === '0' || $sourceQuantity->atoms()[0] === '-'
            || $targetQuantity->atoms() === '0' || $targetQuantity->atoms()[0] === '-'
            || $gross->rial() === '0' || $gross->rial()[0] === '-' || $fee->rial()[0] === '-'
            || ($fee->rial() !== '0' && $cashId === null)) {
            throw new InvalidArgumentException('Invalid historical conversion quantity or amount.');
        }
        if ($cashId !== null) {
            $cash = DB::table('accounts')->where('id', $cashId)->whereNull('archived_at')->first();
            if ($cash === null || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
                throw new InvalidArgumentException('Invalid fee cash account.');
            }
        }
        $payload = ['asset_convert', $sourceId, $targetId, $cashId, $date, $sourceQuantity->atoms(),
            $targetQuantity->atoms(), $gross->rial(), $fee->rial(), ($input['after_opening'] ?? false) === true];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($date, $sourceId, $targetId, $cashId, $source, $target,
            $sourceQuantity, $targetQuantity, $gross, $fee, $key, $hash): int {
            $this->lockState();
            $existing = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->type !== 'asset_convert' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $existing->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            if (DB::table('operations')->where('effective_date', '>=', $date)->where('type', 'reversal')->exists()) {
                throw new FinanceInputException('Historical posting across an existing reversal needs a separate amendment review.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'asset_convert', 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'reason' => 'ثبت تبدیل گذشته با بازسازی',
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('accounts')->insertOrIgnore([
                'code' => 'system-realized-gain', 'name' => 'سود تحقق‌یافته دارایی',
                'class' => 'income', 'subtype' => 'realized-gain', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $gainAccount = (int) DB::table('accounts')->where('code', 'system-realized-gain')->value('id');
            $lines = [[$targetId, 'debit', $gross->add($fee)]];
            if ($fee->rial() !== '0') {
                $lines[] = [$cashId, 'credit', $fee];
            }
            $lines[] = [$gainAccount, 'credit', $gross];
            foreach ($lines as $index => [$accountId, $side, $amount]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $amount->rial(), 'line_no' => $index + 1,
                ]);
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $sourceId,
                'instrument_id' => $source->instrument_id,
                'signed_atoms' => '-'.$sourceQuantity->atoms(), 'line_no' => 1,
            ]);
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $targetId,
                'instrument_id' => $target->instrument_id,
                'signed_atoms' => $targetQuantity->atoms(), 'line_no' => 2,
            ]);
            $targetCost = $gross->add($fee);
            $lotId = DB::table('lots')->insertGetId([
                'acquisition_operation_id' => $id, 'account_id' => $targetId,
                'instrument_id' => $target->instrument_id,
                'acquired_atoms' => $targetQuantity->atoms(), 'remaining_atoms' => $targetQuantity->atoms(),
                'cost_rial' => $targetCost->rial(), 'remaining_cost_rial' => $targetCost->rial(),
                'historical_cost_rial' => $targetCost->rial(), 'cost_basis_kind' => 'exchange',
                'acquired_date' => $date, 'acquisition_method' => 'conversion',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('asset_origin_events')->insert([
                'operation_id' => $id, 'lot_id' => $lotId, 'kind' => 'conversion',
                'protected_min_atoms' => '0', 'protected_max_atoms' => $targetQuantity->atoms(),
                'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(),
                'action' => 'backdated_asset_convert', 'facts_hash' => $hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, $date);

            return $id;
        });
    }

    /** Same contract as CashBook::post, with a historical replay when date precedes existing postings. */
    public function cash(array $input): int
    {
        Gate::authorize('access-app');
        $type = $input['type'] ?? '';
        if (! in_array($type, ['income', 'accrue_income', 'expense', 'transfer', 'lend', 'collect', 'borrow', 'repay'], true)) {
            throw new InvalidArgumentException('Unsupported historical cash operation.');
        }
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Historical operation must be after opening.');
        }
        $latest = DB::table('operations')->where('type', '!=', 'replay_adjustment')->max('effective_date');
        if ($latest === null || $date >= $latest) {
            return app(CashBook::class)->post($input);
        }
        $amount = MoneyRial::fromToman($input['amount_toman'] ?? '');
        $sourceId = $input['source_id'] ?? null;
        $targetId = $input['target_id'] ?? null;
        $categoryId = $input['category_id'] ?? null;
        $reason = trim($input['reason'] ?? '');
        $key = $input['idempotency_key'] ?? '';
        if ($amount->rial() === '0' || $amount->rial()[0] === '-'
            || ($sourceId !== null && (! is_int($sourceId) || $sourceId < 1))
            || ($targetId !== null && (! is_int($targetId) || $targetId < 1))
            || ($categoryId !== null && (! is_int($categoryId) || $categoryId < 1))
            || mb_strlen($reason) > 240 || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid historical cash input.');
        }
        $source = $sourceId === null ? null : DB::table('accounts')->where('id', $sourceId)->whereNull('archived_at')->first();
        $target = $targetId === null ? null : DB::table('accounts')->where('id', $targetId)->whereNull('archived_at')->first();
        $cash = fn (?object $account): bool => $account !== null && $account->class === 'asset'
            && in_array($account->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true);
        $receivable = fn (?object $account): bool => $account !== null && $account->class === 'asset' && $account->subtype === 'receivable';
        $payable = fn (?object $account): bool => $account !== null && $account->class === 'liability' && $account->subtype === 'payable';
        $valid = match ($type) {
            'income' => $source === null && $cash($target),
            'accrue_income' => $source === null && $receivable($target),
            'expense' => $cash($source) && $target === null,
            'transfer' => $cash($source) && $cash($target) && $source->id !== $target->id,
            'lend' => $cash($source) && $receivable($target),
            'collect' => $receivable($source) && $cash($target),
            'borrow' => $payable($source) && $cash($target),
            'repay' => $cash($source) && $payable($target),
        };
        if (! $valid) {
            throw new InvalidArgumentException('Accounts do not match historical operation.');
        }
        if ($categoryId !== null) {
            $category = DB::table('categories')->where('id', $categoryId)->whereNull('archived_at')->first();
            if ($category === null || $category->kind !== ($type === 'accrue_income' ? 'income' : $type)) {
                throw new InvalidArgumentException('Category does not match operation type.');
            }
        }
        $payload = [
            'type' => $type, 'date' => $date, 'amount_rial' => $amount->rial(),
            'source_id' => $sourceId, 'target_id' => $targetId, 'category_id' => $categoryId,
            'reason' => $reason, 'after_opening' => ($input['after_opening'] ?? false) === true,
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($type, $date, $amount, $sourceId, $targetId, $categoryId, $reason, $key, $hash): int {
            $this->lockState();
            $existing = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->type !== $type || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $existing->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            if (DB::table('operations')->where('effective_date', '>=', $date)->where('type', 'reversal')->exists()) {
                throw new FinanceInputException('Historical posting across an existing reversal needs a separate amendment review.');
            }
            $systemId = null;
            if (in_array($type, ['income', 'accrue_income', 'expense'], true)) {
                $class = $type === 'expense' ? 'expense' : 'income';
                DB::table('accounts')->insertOrIgnore([
                    'code' => 'system-'.$class, 'name' => $class === 'income' ? 'درآمد' : 'هزینه',
                    'class' => $class, 'subtype' => 'general', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $systemId = (int) DB::table('accounts')->where('code', 'system-'.$class)->value('id');
            }
            $lines = match ($type) {
                'income', 'accrue_income' => [[$targetId, 'debit'], [$systemId, 'credit']],
                'expense' => [[$systemId, 'debit'], [$sourceId, 'credit']],
                default => [[$targetId, 'debit'], [$sourceId, 'credit']],
            };
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => $type, 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'category_id' => $categoryId,
                'reason' => $reason ?: null, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $index => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $amount->rial(), 'line_no' => $index + 1,
                ]);
            }
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(),
                'action' => 'backdated_cash_posted', 'facts_hash' => $hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, $date);

            return $id;
        });
    }

    /** Insert a historical sale and settle its FIFO cost through the same atomic replay. */
    public function sell(array $input): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Historical sale must be after opening.');
        }
        $latest = DB::table('operations')->where('type', '!=', 'replay_adjustment')->max('effective_date');
        if ($latest === null || $date >= $latest) {
            return app(AssetDisposal::class)->sell($input);
        }
        $sourceId = $input['source_id'] ?? 0;
        $cashId = $input['cash_id'] ?? 0;
        $key = $input['idempotency_key'] ?? '';
        if (! is_int($sourceId) || $sourceId < 1 || ! is_int($cashId) || $cashId < 1
            || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid historical sale identifiers.');
        }
        $source = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $sourceId)->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first();
        $cash = DB::table('accounts')->where('id', $cashId)->whereNull('archived_at')->first();
        if ($source === null || $cash === null || $cash->class !== 'asset'
            || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
            throw new InvalidArgumentException('Invalid historical sale accounts.');
        }
        $quantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $source->quantity_scale);
        $gross = MoneyRial::fromToman($input['gross_toman'] ?? '');
        $fee = MoneyRial::fromToman($input['fee_toman'] ?? '');
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-'
            || $gross->rial() === '0' || $gross->rial()[0] === '-' || $fee->rial()[0] === '-'
            || $gross->subtract($fee)->rial()[0] === '-') {
            throw new InvalidArgumentException('Invalid historical sale quantity or amount.');
        }
        $payload = ['asset_sell', $sourceId, null, $cashId, $date, $quantity->atoms(), null,
            $gross->rial(), $fee->rial(), ($input['after_opening'] ?? false) === true];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($date, $sourceId, $cashId, $source, $quantity, $gross, $fee, $key, $hash): int {
            $this->lockState();
            $existing = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->type !== 'asset_sell' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $existing->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            if (DB::table('operations')->where('effective_date', '>=', $date)->where('type', 'reversal')->exists()) {
                throw new FinanceInputException('Historical posting across an existing reversal needs a separate amendment review.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'asset_sell', 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'reason' => 'ثبت فروش گذشته با بازسازی',
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('accounts')->insertOrIgnore([
                'code' => 'system-realized-gain', 'name' => 'سود تحقق‌یافته دارایی',
                'class' => 'income', 'subtype' => 'realized-gain', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $gainAccount = (int) DB::table('accounts')->where('code', 'system-realized-gain')->value('id');
            $lines = [[$cashId, 'debit', $gross->subtract($fee)]];
            if ($fee->rial() !== '0') {
                DB::table('accounts')->insertOrIgnore([
                    'code' => 'system-sale-fee', 'name' => 'کارمزد فروش دارایی',
                    'class' => 'expense', 'subtype' => 'sale-fee', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $feeAccount = (int) DB::table('accounts')->where('code', 'system-sale-fee')->value('id');
                $lines[] = [$feeAccount, 'debit', $fee];
            }
            $lines[] = [$gainAccount, 'credit', $gross];
            foreach ($lines as $index => [$accountId, $side, $amount]) {
                if ($amount->rial() !== '0') {
                    DB::table('journal_lines')->insert([
                        'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                        'amount_rial' => $amount->rial(), 'line_no' => $index + 1,
                    ]);
                }
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $sourceId,
                'instrument_id' => $source->instrument_id,
                'signed_atoms' => '-'.$quantity->atoms(), 'line_no' => 1,
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(),
                'action' => 'backdated_asset_sale', 'facts_hash' => $hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, $date);

            return $id;
        });
    }

    /** Same input contract as AssetTrading::buy; earlier date triggers full validated replay. */
    public function buy(array $input): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Historical purchase must be after opening.');
        }
        $latest = DB::table('operations')->whereNotIn('type', ['replay_adjustment'])->max('effective_date');
        if ($latest === null || $date >= $latest) {
            return app(AssetTrading::class)->buy($input);
        }
        $assetId = $input['account_id'] ?? 0;
        $cashId = $input['cash_account_id'] ?? 0;
        $key = $input['idempotency_key'] ?? '';
        if (! is_int($assetId) || $assetId < 1 || ! is_int($cashId) || $cashId < 1 || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid historical purchase identifiers.');
        }
        $asset = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->where('asset_accounts.account_id', $assetId)->whereNull('accounts.archived_at')
            ->select('instruments.id as instrument_id', 'instruments.quantity_scale')->first();
        $cash = DB::table('accounts')->where('id', $cashId)->whereNull('archived_at')->first();
        if ($asset === null || $cash === null || $cash->class !== 'asset'
            || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
            throw new InvalidArgumentException('Invalid historical purchase accounts.');
        }
        $quantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $asset->quantity_scale);
        $price = MoneyRial::fromToman($input['price_toman'] ?? '');
        $fee = MoneyRial::fromToman($input['fee_toman'] ?? '');
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-'
            || $price->rial() === '0' || $price->rial()[0] === '-' || $fee->rial()[0] === '-') {
            throw new InvalidArgumentException('Invalid historical purchase quantity or amount.');
        }
        $total = $price->add($fee);
        $payload = [$assetId, $cashId, $date, $quantity->atoms(), $price->rial(), $fee->rial(), ($input['after_opening'] ?? false) === true];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($date, $assetId, $cashId, $asset, $quantity, $total, $key, $hash): int {
            $this->lockState();
            $existing = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->type !== 'asset_buy' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $existing->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            if (DB::table('operations')->where('effective_date', '>=', $date)->where('type', 'reversal')->exists()) {
                throw new FinanceInputException('Historical posting across an existing reversal needs a separate amendment review.');
            }
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'asset_buy', 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'reason' => 'ثبت معامله گذشته با بازسازی',
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$assetId, 'debit'], [$cashId, 'credit']] as $index => [$accountId, $side]) {
                DB::table('journal_lines')->insert([
                    'operation_id' => $id, 'account_id' => $accountId, 'side' => $side,
                    'amount_rial' => $total->rial(), 'line_no' => $index + 1,
                ]);
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $assetId,
                'instrument_id' => $asset->instrument_id, 'signed_atoms' => $quantity->atoms(), 'line_no' => 1,
            ]);
            $lotId = DB::table('lots')->insertGetId([
                'acquisition_operation_id' => $id, 'account_id' => $assetId,
                'instrument_id' => $asset->instrument_id,
                'acquired_atoms' => $quantity->atoms(), 'remaining_atoms' => $quantity->atoms(),
                'cost_rial' => $total->rial(), 'remaining_cost_rial' => $total->rial(),
                'historical_cost_rial' => $total->rial(), 'cost_basis_kind' => 'purchase',
                'acquired_date' => $date, 'acquisition_method' => 'purchase',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('asset_origin_events')->insert([
                'operation_id' => $id, 'lot_id' => $lotId, 'kind' => 'purchase',
                'protected_min_atoms' => '0', 'protected_max_atoms' => $quantity->atoms(),
                'evidence' => 'mixed_unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(), 'action' => 'backdated_asset_buy',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, $date);

            return $id;
        });
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }
}
