<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetDisposal;
use App\Actions\Finance\AssetOverview;
use App\Actions\Finance\AssetTrading;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\AssetWorkspace;
use App\Livewire\CashSetup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function syntheticAssetOpening(): array
{
    $catalog = app(AssetCatalog::class);
    $setup = app(OpeningSetup::class);
    $setup->saveSettings(true);
    $instrumentId = $catalog->createInstrument('GLD24', 'طلای ساختگی', 'gold', 3, 7500);
    $setup->addItem('bank', 'بانک ساختگی', '100', '0', 'unknown');
    $itemId = $catalog->addOpeningItem($instrumentId, 'طلای خانه ساختگی', '10', '200', '6', '54', 'user_declared');
    $openingId = $setup->confirm((int) $setup->session()->revision, 1, 'asset-opening-synthetic-001');
    $accountId = (int) DB::table('asset_accounts')->where('opening_item_id', $itemId)->value('account_id');
    $bankId = (int) DB::table('accounts')->where('name', 'بانک ساختگی')->value('id');

    return [$instrumentId, $itemId, $openingId, $accountId, $bankId];
}

it('posts quantity opening with distinct valuation, historical cost and protected basis', function (): void {
    $this->actingAs(User::factory()->create());
    [$instrumentId, $itemId, $openingId, $assetAccountId] = syntheticAssetOpening();
    $lines = DB::table('journal_lines')->where('operation_id', $openingId)->get();
    $debits = $lines->where('side', 'debit')->sum('amount_rial');
    $credits = $lines->where('side', 'credit')->sum('amount_rial');
    $lot = DB::table('lots')->where('opening_item_id', $itemId)->first();
    $origin = DB::table('asset_origin_events')->where('lot_id', $lot->id)->first();
    expect((string) $debits)->toBe('3000')
        ->and((string) $credits)->toBe('3000')
        ->and((string) DB::table('quantity_entries')->where('operation_id', $openingId)->value('signed_atoms'))->toBe('10000')
        ->and((string) $lot->cost_rial)->toBe('2000')
        ->and($lot->historical_cost_rial)->toBeNull()
        ->and($lot->acquired_date)->toBeNull()
        ->and($lot->cost_basis_kind)->toBe('opening_valuation'
        )->and((string) $origin->protected_min_atoms)->toBe('6000')
        ->and((string) $origin->protected_basis_rial)->toBe('540')
        ->and(DB::table('operations')->where('type', 'asset_buy')->count())->toBe(0)
        ->and(DB::table('user_decisions')->where('kind', 'opening_asset_protected')->count())->toBe(1)
        ->and($assetAccountId)->toBeGreaterThan(0)
        ->and($instrumentId)->toBeGreaterThan(0);
});

it('rejects invented protected basis and excess quantity without changing draft', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrumentId = $catalog->createInstrument('FUND1', 'صندوق ساختگی', 'fund', 4);
    foreach ([['1', '2', '20', 'user_declared'], ['1', '0.1', null, 'user_declared'], ['1', '0.1', '1', 'unknown']] as [$quantity, $protected, $basis, $evidence]) {
        expect(fn () => $catalog->addOpeningItem($instrumentId, 'صندوق', $quantity, '100', $protected, $basis, $evidence))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('asset_opening_items')->count())->toBe(0);
});

it('buys exact quantity and fee once, preserves unknown origin, and rejects insufficient cash', function (): void {
    $this->actingAs(User::factory()->create());
    [, , , $holdingId, $bankId] = syntheticAssetOpening();
    $trading = app(AssetTrading::class);
    $input = [
        'account_id' => $holdingId, 'cash_account_id' => $bankId,
        'date' => '2026-08-12', 'quantity' => '1.250', 'price_toman' => '20',
        'fee_toman' => '1', 'idempotency_key' => 'asset-buy-synthetic-001',
    ];
    $id = $trading->buy($input);
    expect($trading->buy($input))->toBe($id);
    $lot = DB::table('lots')->where('acquisition_operation_id', $id)->first();
    expect((string) DB::table('accounts')->find($holdingId)->balance_rial)->toBe('2210')
        ->and((string) DB::table('accounts')->find($bankId)->balance_rial)->toBe('790')
        ->and((string) $lot->acquired_atoms)->toBe('1250')
        ->and((string) $lot->cost_rial)->toBe('210')
        ->and((string) DB::table('asset_origin_events')->where('lot_id', $lot->id)->value('protected_min_atoms'))->toBe('0')
        ->and(DB::table('operations')->where('type', 'income')->count())->toBe(0);
    expect(fn () => $trading->buy([...$input, 'quantity' => '2']))->toThrow(RuntimeException::class);
    expect(fn () => $trading->buy([...$input, 'idempotency_key' => 'asset-buy-synthetic-002', 'price_toman' => '1000']))->toThrow(RuntimeException::class);
    expect(fn () => app(CashBook::class)->reverseLatest($id, 'برگشت آزمایشی', 'asset-buy-reverse-test'))->toThrow(InvalidArgumentException::class);
    expect(DB::table('operations')->where('type', 'asset_buy')->count())->toBe(1);
});

it('keeps prices versioned and never uses a future price in a past report', function (): void {
    $this->actingAs(User::factory()->create());
    [$instrumentId] = syntheticAssetOpening();
    $catalog = app(AssetCatalog::class);
    $catalog->recordPrice($instrumentId, '2026-08-13', '250', 'قیمت ساختگی');
    $catalog->recordPrice($instrumentId, '2026-08-13', '260', 'اصلاح قیمت ساختگی');
    $overview = app(AssetOverview::class);
    expect($overview->holdings('2026-08-12')[0]['market_toman'])->toBeNull()
        ->and($overview->holdings('2026-08-13')[0]['market_toman'])->toBe('260')
        ->and($overview->holdings('2026-08-13')[0]['price_revision'])->toBe(2)
        ->and($overview->holdings('2026-08-13')[0]['book_toman'])->toBe('200');
});

it('rolls back opening journal if quantity persistence fails', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrumentId = $catalog->createInstrument('USDTEST', 'ارز ساختگی', 'currency', 2);
    $catalog->addOpeningItem($instrumentId, 'ارز افتتاحیه', '10', '100', '0', null, 'unknown');
    DB::statement("CREATE TRIGGER fail_synthetic_quantity BEFORE INSERT ON quantity_entries BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    $setup = app(OpeningSetup::class);
    expect(fn () => $setup->confirm((int) $setup->session()->revision, 1, 'asset-opening-synthetic-002'))->toThrow(QueryException::class);
    expect(DB::table('operations')->count())->toBe(0)
        ->and(DB::table('journal_lines')->count())->toBe(0)
        ->and(DB::table('lots')->count())->toBe(0)
        ->and(DB::table('asset_accounts')->count())->toBe(0)
        ->and(DB::table('setup_sessions')->value('status'))->toBe('draft');
});

it('guards the asset draft and displays its fields only to the owner', function (): void {
    expect(fn () => app(AssetCatalog::class)->createInstrument('X1', 'آزمایشی', 'stock', 0))->toThrow(AuthorizationException::class);
    $this->get('/setup/cash')->assertRedirect(route('login'));
    $this->get('/assets')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    Livewire::test(CashSetup::class)->assertSee('موجودی مقداری در افتتاحیه');
    Livewire::test(AssetWorkspace::class)->assertSee('دارایی‌های مقداری');
});

it('shows an input error for quantity beyond instrument precision', function (): void {
    $this->actingAs(User::factory()->create());
    $instrument = app(AssetCatalog::class)->createInstrument('UNIT-SYN', 'واحد ساختگی', 'stock', 0);
    Livewire::test(CashSetup::class)
        ->set('assetInstrumentId', (string) $instrument)->set('assetName', 'دارایی آزمایشی')
        ->set('assetQuantity', '1.5')->set('assetValueToman', '10')
        ->call('addAssetItem')->assertHasErrors('assetItem');
    expect(DB::table('asset_opening_items')->count())->toBe(0);
});

it('opens foreign debt and receivable with principal separate from rial valuation', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $currency = $catalog->createInstrument('USD-SYN', 'دلار ساختگی', 'currency', 2);
    $catalog->addForeignObligationOpening($currency, 'receivable', 'طلب ساختگی', '10.25', '100');
    $catalog->addForeignObligationOpening($currency, 'payable', 'بدهی ساختگی', '3', '40');
    $setup = app(OpeningSetup::class);
    $opening = $setup->confirm((int) $setup->session()->revision, 1, 'foreign-opening-synthetic');
    $debit = DB::table('journal_lines')->where('operation_id', $opening)->where('side', 'debit')->pluck('amount_rial')->map(strval(...))->all();
    $credit = DB::table('journal_lines')->where('operation_id', $opening)->where('side', 'credit')->pluck('amount_rial')->map(strval(...))->all();
    expect($debit)->toBe(['1000'])
        ->and($credit)->toBe(['400', '600'])
        ->and(DB::table('quantity_entries')->where('operation_id', $opening)->orderBy('line_no')->pluck('signed_atoms')->all())->toBe(['1025', '300'])
        ->and(DB::table('foreign_obligations')->count())->toBe(2)
        ->and(DB::table('lots')->count())->toBe(0)
        ->and(DB::table('operations')->where('type', 'income')->count())->toBe(0);
    expect(fn () => $catalog->addForeignObligationOpening($currency, 'payable', 'دیرهنگام', '1', '1'))->toThrow(RuntimeException::class);
});

it('sells FIFO opening units with balanced journal and no double-counted sale proceeds as income', function (): void {
    $this->actingAs(User::factory()->create());
    [, , , $holdingId, $bankId] = syntheticAssetOpening();
    $sale = app(AssetDisposal::class)->sell([
        'source_id' => $holdingId, 'cash_id' => $bankId, 'date' => '2026-08-12',
        'quantity' => '5', 'gross_toman' => '130', 'fee_toman' => '2',
        'idempotency_key' => 'synthetic-sale-fifo-001',
    ]);
    $lot = DB::table('lots')->where('account_id', $holdingId)->first();
    $lines = DB::table('journal_lines')->where('operation_id', $sale)->get();
    expect((string) $lot->remaining_atoms)->toBe('5000')
        ->and((string) $lot->remaining_cost_rial)->toBe('1000')
        ->and((string) DB::table('lot_allocations')->where('exit_operation_id', $sale)->value('allocated_cost_rial'))->toBe('1000')
        ->and((string) DB::table('accounts')->find($bankId)->balance_rial)->toBe('2280')
        ->and((string) DB::table('accounts')->find($holdingId)->balance_rial)->toBe('1000')
        ->and((string) $lines->where('side', 'debit')->sum('amount_rial'))->toBe('1300')
        ->and((string) $lines->where('side', 'credit')->sum('amount_rial'))->toBe('1300')
        ->and(DB::table('accounts')->where('code', 'system-realized-gain')->value('id'))->not->toBeNull()
        ->and(app(AssetOverview::class)->realizedResult('2026-08-12')['net_toman'])->toBe('28')
        ->and(DB::table('operations')->where('type', 'income')->count())->toBe(0);
});

it('converts assets at an explicit rial consideration with fee once and preserves exact quantities', function (): void {
    $this->actingAs(User::factory()->create());
    [, , , $sourceId, $bankId] = syntheticAssetOpening();
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('SILVER-SYN', 'نقره ساختگی', 'silver', 3, 9250);
    $targetId = $catalog->createHolding($instrument, 'نقره جدید');
    $conversion = app(AssetDisposal::class)->convert([
        'source_id' => $sourceId, 'target_id' => $targetId, 'cash_id' => $bankId,
        'date' => '2026-08-12', 'quantity' => '10', 'target_quantity' => '20',
        'gross_toman' => '240', 'fee_toman' => '3', 'idempotency_key' => 'synthetic-convert-001',
    ]);
    $lines = DB::table('journal_lines')->where('operation_id', $conversion)->get();
    expect((string) DB::table('lots')->where('account_id', $sourceId)->value('remaining_atoms'))->toBe('0')
        ->and((string) DB::table('lots')->where('account_id', $sourceId)->value('remaining_cost_rial'))->toBe('0')
        ->and((string) DB::table('lots')->where('account_id', $targetId)->value('cost_rial'))->toBe('2430')
        ->and((string) DB::table('accounts')->find($bankId)->balance_rial)->toBe('970')
        ->and((string) $lines->where('side', 'debit')->sum('amount_rial'))->toBe('2430')
        ->and((string) $lines->where('side', 'credit')->sum('amount_rial'))->toBe('2430')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-12')['net_toman'])->toBe('40')
        ->and(DB::table('operations')->where('type', 'income')->count())->toBe(0);
});

it('records an economic loss and leaves every posted document intact on an over-sale', function (): void {
    $this->actingAs(User::factory()->create());
    [, , , $holdingId, $bankId] = syntheticAssetOpening();
    $disposal = app(AssetDisposal::class);
    $input = [
        'source_id' => $holdingId, 'cash_id' => $bankId, 'date' => '2026-08-12',
        'quantity' => '4', 'gross_toman' => '60', 'fee_toman' => '1',
        'idempotency_key' => 'synthetic-loss-sale-001',
    ];
    $sale = $disposal->sell($input);
    expect($disposal->sell($input))->toBe($sale)
        ->and(app(AssetOverview::class)->realizedResult('2026-08-12')['net_toman'])->toBe('-21')
        ->and((string) DB::table('lots')->where('account_id', $holdingId)->value('remaining_atoms'))->toBe('6000');
    $before = DB::table('operations')->count();
    expect(fn () => $disposal->sell([...$input, 'idempotency_key' => 'synthetic-over-sale-001', 'quantity' => '7']))->toThrow(RuntimeException::class);
    expect(fn () => $disposal->sell([...$input, 'gross_toman' => '61']))->toThrow(RuntimeException::class);
    expect(DB::table('operations')->count())->toBe($before)
        ->and(DB::table('lot_allocations')->count())->toBe(1)
        ->and((string) DB::table('lots')->where('account_id', $holdingId)->value('remaining_atoms'))->toBe('6000');
});
