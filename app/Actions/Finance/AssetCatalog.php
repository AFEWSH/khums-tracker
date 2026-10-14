<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Numbers\AssetQuantity;
use App\Support\Numbers\DecimalInput;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssetCatalog
{
    public function instruments(): array
    {
        Gate::authorize('access-app');

        return DB::table('instruments')->where('active', true)->orderBy('name')->get()->all();
    }

    public function openingItems(): array
    {
        Gate::authorize('access-app');

        return DB::table('asset_opening_items')->join('instruments', 'instruments.id', '=', 'asset_opening_items.instrument_id')
            ->where('setup_session_id', 1)->orderBy('asset_opening_items.id')
            ->get(['asset_opening_items.*', 'instruments.name as instrument_name', 'instruments.quantity_scale'])
            ->map(fn ($item): array => [
                'id' => (int) $item->id, 'name' => $item->name, 'instrument' => $item->instrument_name,
                'quantity' => (string) BigDecimal::ofUnscaledValue($item->quantity_atoms, (int) $item->quantity_scale)->strippedOfTrailingZeros(),
                'protected_quantity' => (string) BigDecimal::ofUnscaledValue($item->protected_atoms, (int) $item->quantity_scale)->strippedOfTrailingZeros(),
                'value_toman' => MoneyRial::fromRial((string) $item->opening_value_rial)->toman(),
                'basis_toman' => $item->protected_basis_rial === null ? null : MoneyRial::fromRial((string) $item->protected_basis_rial)->toman(),
            ])->all();
    }

    public function foreignObligationItems(): array
    {
        Gate::authorize('access-app');

        return DB::table('foreign_obligation_opening_items')
            ->join('instruments', 'instruments.id', '=', 'foreign_obligation_opening_items.instrument_id')
            ->where('setup_session_id', 1)->orderBy('foreign_obligation_opening_items.id')
            ->get(['foreign_obligation_opening_items.*', 'instruments.name as instrument_name', 'instruments.quantity_scale'])
            ->map(fn ($item): array => [
                'id' => (int) $item->id, 'kind' => $item->kind, 'name' => $item->name,
                'instrument' => $item->instrument_name,
                'principal' => (string) BigDecimal::ofUnscaledValue($item->principal_atoms, (int) $item->quantity_scale)->strippedOfTrailingZeros(),
                'value_toman' => MoneyRial::fromRial((string) $item->opening_value_rial)->toman(),
            ])->all();
    }

    public function addForeignObligationOpening(int $instrumentId, string $kind, string $name, string $principal, string $openingValueToman): int
    {
        Gate::authorize('access-app');
        $name = trim($name);
        $instrument = DB::table('instruments')->where('id', $instrumentId)->where('type', 'currency')->where('active', true)->first();
        if ($instrument === null || ! in_array($kind, ['receivable', 'payable'], true) || $name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Invalid foreign obligation.');
        }
        $quantity = AssetQuantity::fromDecimal($principal, (int) $instrument->quantity_scale);
        $value = MoneyRial::fromToman($openingValueToman);
        if ($quantity->atoms() === '0' || $quantity->atoms()[0] === '-' || $value->rial()[0] === '-') {
            throw new InvalidArgumentException('Foreign principal must be positive and value nonnegative.');
        }

        return DB::transaction(function () use ($instrumentId, $kind, $name, $quantity, $value): int {
            $this->lockState();
            $session = app(OpeningSetup::class)->session();
            if ($session->status !== 'draft') {
                throw new FinanceInputException('Opening was already confirmed.');
            }
            $id = DB::table('foreign_obligation_opening_items')->insertGetId([
                'setup_session_id' => 1, 'instrument_id' => $instrumentId, 'kind' => $kind,
                'name' => $name, 'principal_atoms' => $quantity->atoms(),
                'opening_value_rial' => $value->rial(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('setup_sessions')->where('id', 1)->update([
                'revision' => (int) $session->revision + 1, 'step' => 'obligations', 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function removeForeignObligationOpening(int $id): void
    {
        Gate::authorize('access-app');
        DB::transaction(function () use ($id): void {
            $this->lockState();
            $session = app(OpeningSetup::class)->session();
            if ($session->status !== 'draft') {
                throw new FinanceInputException('Opening was already confirmed.');
            }
            DB::table('foreign_obligation_opening_items')->where('id', $id)->where('setup_session_id', 1)->delete();
            DB::table('setup_sessions')->where('id', 1)->update([
                'revision' => (int) $session->revision + 1, 'updated_at' => now(),
            ]);
        });
    }

    public function createHolding(int $instrumentId, string $name): int
    {
        Gate::authorize('access-app');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ! DB::table('instruments')->where('id', $instrumentId)->where('active', true)->exists()) {
            throw new InvalidArgumentException('Invalid holding.');
        }

        return DB::transaction(function () use ($instrumentId, $name): int {
            $this->lockState();
            if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
                throw new FinanceInputException('Confirm the opening first.');
            }
            $id = DB::table('accounts')->insertGetId([
                'code' => 'holding-'.Str::uuid(), 'name' => $name,
                'class' => 'asset', 'subtype' => 'holding', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('asset_accounts')->insert(['account_id' => $id, 'instrument_id' => $instrumentId]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'holding_created',
                'facts_hash' => hash('sha256', json_encode([$id, $instrumentId, $name], JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function createInstrument(string $code, string $name, string $type, int $scale, ?int $fineness = null): int
    {
        Gate::authorize('access-app');
        $code = trim($code);
        $name = trim($name);
        if (! preg_match('/^[A-Za-z0-9._-]{2,64}$/D', $code) || $name === '' || mb_strlen($name) > 120
            || ! in_array($type, ['stock', 'fund', 'gold', 'silver', 'currency', 'crypto', 'other'], true)
            || $scale < 0 || $scale > 18 || ($fineness !== null && ($fineness < 1 || $fineness > 10000))
            || ($fineness !== null && ! in_array($type, ['gold', 'silver'], true))) {
            throw new InvalidArgumentException('Invalid instrument details.');
        }
        $unit = match ($type) {
            'gold', 'silver' => 'gram', 'currency' => 'currency', 'crypto' => 'coin', default => 'unit',
        };

        return DB::transaction(function () use ($code, $name, $type, $scale, $fineness, $unit): int {
            $this->lockState();
            if (DB::table('instruments')->where('code', $code)->exists()) {
                throw new FinanceInputException('Instrument code already exists.');
            }
            $id = DB::table('instruments')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => $type, 'unit' => $unit,
                'quantity_scale' => $scale, 'fineness_parts_per_10000' => $fineness,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'instrument_created',
                'facts_hash' => hash('sha256', json_encode([$id, $code, $name, $type, $scale, $fineness], JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public function addOpeningItem(int $instrumentId, string $name, string $quantity, string $openingValueToman, string $protectedQuantity, ?string $protectedBasisToman, string $evidence): int
    {
        Gate::authorize('access-app');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ! in_array($evidence, ['unknown', 'user_declared'], true)) {
            throw new InvalidArgumentException('Invalid opening asset details.');
        }
        $instrument = DB::table('instruments')->where('id', $instrumentId)->where('active', true)->first()
            ?? throw new InvalidArgumentException('Instrument does not exist.');
        $total = AssetQuantity::fromDecimal($quantity, (int) $instrument->quantity_scale);
        $protected = AssetQuantity::fromDecimal($protectedQuantity, (int) $instrument->quantity_scale);
        $value = MoneyRial::fromToman($openingValueToman);
        $basis = $protectedBasisToman === null || trim($protectedBasisToman) === '' ? null : MoneyRial::fromToman($protectedBasisToman);
        if ($total->atoms()[0] === '-' || $total->atoms() === '0' || $protected->atoms()[0] === '-'
            || self::atomsGreater($protected->atoms(), $total->atoms()) || $value->rial()[0] === '-'
            || ($basis !== null && $basis->rial()[0] === '-')
            || ($protected->atoms() !== '0' && ($evidence !== 'user_declared' || $basis === null))
            || ($protected->atoms() === '0' && $basis !== null)) {
            throw new InvalidArgumentException('Invalid quantity, value, or protected-basis declaration.');
        }

        return DB::transaction(function () use ($instrumentId, $name, $total, $protected, $value, $basis, $evidence): int {
            $this->lockState();
            $session = app(OpeningSetup::class)->session();
            if ($session === null || $session->status !== 'draft') {
                throw new FinanceInputException('Opening draft is unavailable or already confirmed.');
            }
            $id = DB::table('asset_opening_items')->insertGetId([
                'setup_session_id' => 1, 'instrument_id' => $instrumentId, 'name' => $name,
                'quantity_atoms' => $total->atoms(), 'protected_atoms' => $protected->atoms(),
                'opening_value_rial' => $value->rial(), 'protected_basis_rial' => $basis?->rial(),
                'evidence' => $evidence, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('setup_sessions')->where('id', 1)->update([
                'revision' => (int) $session->revision + 1, 'step' => 'assets', 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function removeOpeningItem(int $id): void
    {
        Gate::authorize('access-app');
        DB::transaction(function () use ($id): void {
            $this->lockState();
            $session = DB::table('setup_sessions')->where('id', 1)->first();
            if ($session === null || $session->status !== 'draft') {
                throw new FinanceInputException('Opening is already confirmed.');
            }
            DB::table('asset_opening_items')->where('id', $id)->where('setup_session_id', 1)->delete();
            DB::table('setup_sessions')->where('id', 1)->update(['revision' => (int) $session->revision + 1, 'updated_at' => now()]);
        });
    }

    public function recordPrice(int $instrumentId, string $date, string $rialPerUnit, string $sourceNote): int
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        $sourceNote = trim($sourceNote);
        if ($sourceNote === '' || mb_strlen($sourceNote) > 240 || ! DB::table('instruments')->where('id', $instrumentId)->exists()) {
            throw new InvalidArgumentException('Price needs a source and instrument.');
        }
        $price = BigDecimal::of(DecimalInput::normalize($rialPerUnit));
        if ($price->isNegative() || $price->getScale() > 10 || strlen(str_replace('.', '', (string) $price)) > 30) {
            throw new InvalidArgumentException('Invalid price precision or sign.');
        }

        return DB::transaction(function () use ($instrumentId, $date, $price, $sourceNote): int {
            $this->lockState();
            $revision = (int) DB::table('asset_prices')->where('instrument_id', $instrumentId)->where('effective_date', $date)->max('revision') + 1;
            $id = DB::table('asset_prices')->insertGetId([
                'instrument_id' => $instrumentId, 'effective_date' => $date,
                'revision' => $revision, 'price_rial_per_unit' => (string) $price,
                'source_note' => $sourceNote, 'actor_id' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'asset_price_recorded',
                'facts_hash' => hash('sha256', json_encode([$id, $instrumentId, $date, (string) $price, $sourceNote], JSON_THROW_ON_ERROR)),
                'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    public static function atomsGreater(string $left, string $right): bool
    {
        return BigInteger::of($left)->compareTo($right) > 0;
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }
}
