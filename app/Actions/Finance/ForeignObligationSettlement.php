<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Settles an explicitly measured FX debt/receivable; principal and exchange result remain separate. */
final class ForeignObligationSettlement
{
    public function post(array $input): int
    {
        Gate::authorize('access-app');
        $date = $input['date'] ?? '';
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || ($date === OpeningSetup::DATE && ($input['after_opening'] ?? false) !== true)) {
            throw new InvalidArgumentException('Settlement must be after opening.');
        }
        $obligationId = $input['obligation_id'] ?? null;
        $cashId = $input['cash_id'] ?? null;
        $key = $input['idempotency_key'] ?? '';
        if (! is_int($obligationId) || $obligationId < 1 || ! is_int($cashId) || $cashId < 1
            || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid settlement account or key.');
        }
        $obligation = DB::table('foreign_obligations')->join('accounts', 'accounts.id', '=', 'foreign_obligations.account_id')
            ->join('instruments', 'instruments.id', '=', 'foreign_obligations.instrument_id')
            ->where('foreign_obligations.account_id', $obligationId)->whereNull('accounts.archived_at')
            ->first(['accounts.class', 'accounts.subtype', 'instruments.quantity_scale', 'foreign_obligations.instrument_id']);
        $cash = DB::table('accounts')->where('id', $cashId)->whereNull('archived_at')->first();
        if ($obligation === null || ! in_array($obligation->subtype, ['receivable_fx', 'payable_fx'], true)
            || $cash === null || $cash->class !== 'asset'
            || ! in_array($cash->subtype, ['bank', 'cash', 'broker_cash', 'wallet_cash'], true)) {
            throw new InvalidArgumentException('Settlement requires an FX obligation and a liquid rial account.');
        }
        $quantity = AssetQuantity::fromDecimal($input['quantity'] ?? '', (int) $obligation->quantity_scale);
        $actual = MoneyRial::fromToman($input['cash_toman'] ?? '');
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-' || $actual->rial() === '0' || $actual->rial()[0] === '-') {
            throw new InvalidArgumentException('Settlement quantity and cash amount must be positive.');
        }
        $reason = trim($input['reason'] ?? '');
        if (mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('Settlement reason is too long.');
        }
        $hash = hash('sha256', json_encode([$obligationId, $cashId, $date, $quantity->atoms(),
            $actual->rial(), $reason, ($input['after_opening'] ?? false) === true], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($obligationId, $cashId, $date, $quantity, $actual, $reason, $key, $hash, $obligation): int {
            DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            $existing = DB::table('operations')->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->type !== 'fx_settlement' || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new FinanceInputException('Idempotency key was used with different data.');
                }

                return (int) $existing->id;
            }
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm opening before settlement.');
            }
            if (DB::table('quantity_entries')->join('operations', 'operations.id', '=', 'quantity_entries.operation_id')
                ->where('quantity_entries.account_id', $obligationId)->where('operations.effective_date', '>', $date)
                ->where('operations.type', 'fx_settlement')->exists()) {
                throw new FinanceInputException('A later settlement of this obligation must be reviewed before backdating.');
            }
            $remaining = BigInteger::zero();
            foreach (DB::table('quantity_entries')->where('account_id', $obligationId)->pluck('signed_atoms') as $atoms) {
                $remaining = $remaining->plus((string) $atoms);
            }
            $take = BigInteger::of($quantity->atoms());
            if ($remaining->isNegative() || $take->isGreaterThan($remaining)) {
                throw new FinanceInputException('Settlement exceeds outstanding FX principal.');
            }
            $book = MoneyRial::fromRial((string) DB::table('accounts')->where('id', $obligationId)->value('balance_rial'));
            $allocated = $take->isEqualTo($remaining) ? $book : MoneyRial::fromRial((string) BigInteger::of($book->rial())
                ->multipliedBy($take)->dividedBy($remaining, RoundingMode::HalfUp));
            $receivable = $obligation->class === 'asset';
            $difference = $receivable ? $actual->subtract($allocated) : $allocated->subtract($actual);
            $sequence = (int) DB::table('operations')->where('effective_date', $date)->max('sequence') + 1;
            $id = DB::table('operations')->insertGetId([
                'public_id' => (string) Str::uuid(), 'type' => 'fx_settlement', 'effective_date' => $date,
                'time_precision' => 'date', 'boundary_relation' => $date === OpeningSetup::DATE ? 'after' : 'unknown',
                'sequence' => $sequence, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'actor_id' => auth()->id(), 'reason' => $reason ?: null,
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lines = $receivable
                ? [[$cashId, 'debit', $actual], [$obligationId, 'credit', $allocated]]
                : [[$obligationId, 'debit', $allocated], [$cashId, 'credit', $actual]];
            if ($difference->rial() !== '0') {
                $gain = $difference->rial()[0] !== '-';
                $code = $gain ? 'system-fx-gain' : 'system-fx-loss';
                DB::table('accounts')->insertOrIgnore([
                    'code' => $code, 'name' => $gain ? 'سود تسعیر تعهد ارزی' : 'زیان تسعیر تعهد ارزی',
                    'class' => $gain ? 'income' : 'expense', 'subtype' => 'fx-result',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $resultId = (int) DB::table('accounts')->where('code', $code)->value('id');
                $absolute = $gain ? $difference : MoneyRial::fromRial(substr($difference->rial(), 1));
                $lines[] = [$resultId, $gain ? 'credit' : 'debit', $absolute];
            }
            $lineNo = 1;
            foreach ($lines as [$accountId, $side, $amount]) {
                if ($amount->rial() !== '0') {
                    DB::table('journal_lines')->insert([
                        'operation_id' => $id, 'account_id' => $accountId,
                        'side' => $side, 'amount_rial' => $amount->rial(), 'line_no' => $lineNo++,
                    ]);
                }
            }
            DB::table('quantity_entries')->insert([
                'operation_id' => $id, 'account_id' => $obligationId,
                'instrument_id' => $obligation->instrument_id,
                'signed_atoms' => '-'.$quantity->atoms(), 'line_no' => 1,
            ]);
            DB::table('accounts')->where('id', $obligationId)->update([
                'balance_rial' => $book->subtract($allocated)->rial(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'operation_id' => $id, 'actor_id' => auth()->id(), 'action' => 'fx_principal_settled',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);
            app(ReplayProjection::class)->rebuild($id, $date);

            return $id;
        });
    }
}
