<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AssetOverview
{
    /** Economic realized result since opening; sale proceeds are not income. */
    public function realizedResult(string $asOfDate): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($asOfDate);
        $grossGain = MoneyRial::fromRial('0');
        $grossLoss = MoneyRial::fromRial('0');
        $saleFees = MoneyRial::fromRial('0');
        foreach (DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('operations.type', ['asset_sell', 'asset_convert', 'replay_adjustment'])
            ->where('operations.effective_date', '<=', $asOfDate)
            ->whereIn('accounts.code', ['system-realized-gain', 'system-realized-loss', 'system-sale-fee'])
            ->get(['accounts.code', 'journal_lines.side', 'journal_lines.amount_rial']) as $line) {
            $amount = MoneyRial::fromRial((string) $line->amount_rial);
            match ($line->code) {
                'system-realized-gain' => $grossGain = $line->side === 'credit' ? $grossGain->add($amount) : $grossGain->subtract($amount),
                'system-realized-loss' => $grossLoss = $grossLoss->add($amount),
                'system-sale-fee' => $saleFees = $saleFees->add($amount),
            };
        }

        return [
            'gain_toman' => $grossGain->toman(),
            'loss_toman' => $grossLoss->toman(),
            'sale_fees_toman' => $saleFees->toman(),
            'net_toman' => $grossGain->subtract($grossLoss)->subtract($saleFees)->toman(),
        ];
    }

    /** Latest known price at or before the report date; null stays unknown. */
    public function holdings(string $asOfDate): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($asOfDate);
        $result = [];
        foreach (DB::table('asset_accounts')->join('accounts', 'accounts.id', '=', 'asset_accounts.account_id')
            ->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->whereNull('accounts.archived_at')->orderBy('accounts.id')
            ->get(['accounts.id as account_id', 'accounts.name as account_name', 'accounts.balance_rial',
                'instruments.id as instrument_id', 'instruments.name as instrument_name', 'instruments.type',
                'instruments.quantity_scale']) as $holding) {
            $atoms = BigInteger::zero();
            foreach (DB::table('quantity_entries')->join('operations', 'operations.id', '=', 'quantity_entries.operation_id')
                ->where('quantity_entries.account_id', $holding->account_id)
                ->where('operations.effective_date', '<=', $asOfDate)->pluck('quantity_entries.signed_atoms') as $entry) {
                $atoms = $atoms->plus((string) $entry);
            }
            $price = DB::table('asset_prices')->where('instrument_id', $holding->instrument_id)
                ->where('effective_date', '<=', $asOfDate)
                ->orderByDesc('effective_date')->orderByDesc('revision')->first();
            $market = null;
            if ($price !== null) {
                $quantity = BigDecimal::ofUnscaledValue($atoms, (int) $holding->quantity_scale);
                $market = MoneyRial::fromRial((string) $quantity->multipliedBy(BigDecimal::of($price->price_rial_per_unit))
                    ->toScale(0, RoundingMode::HalfUp)->toBigInteger())->toman();
            }
            $book = MoneyRial::fromRial('0');
            foreach (DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                ->where('journal_lines.account_id', $holding->account_id)
                ->where('operations.effective_date', '<=', $asOfDate)
                ->get(['journal_lines.side', 'journal_lines.amount_rial']) as $line) {
                $amount = MoneyRial::fromRial((string) $line->amount_rial);
                $book = $line->side === 'debit' ? $book->add($amount) : $book->subtract($amount);
            }
            $result[] = [
                'account_id' => (int) $holding->account_id, 'name' => $holding->account_name,
                'instrument' => $holding->instrument_name, 'type' => $holding->type,
                'quantity' => AssetQuantity::fromDecimal((string) BigDecimal::ofUnscaledValue($atoms, (int) $holding->quantity_scale), (int) $holding->quantity_scale)->decimal(),
                'book_toman' => $book->toman(),
                'market_toman' => $market, 'price_date' => $price?->effective_date,
                'price_revision' => $price?->revision,
            ];
        }

        return $result;
    }
}
