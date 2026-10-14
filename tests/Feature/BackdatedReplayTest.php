<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetDisposal;
use App\Actions\Finance\AssetFacts;
use App\Actions\Finance\AssetOverview;
use App\Actions\Finance\AssetTrading;
use App\Actions\Finance\BackdatedPosting;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\CashFlowReport;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\OpeningAmendment;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\FinanceReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function replayFixture(): array
{
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک بازسازی ساختگی', '1000', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'replay-opening-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک بازسازی ساختگی')->value('id');
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('REPLAY-STOCK', 'سهم بازسازی ساختگی', 'stock', 0);
    $holding = $catalog->createHolding($instrument, 'سبد بازسازی');
    $purchase = app(AssetTrading::class)->buy([
        'account_id' => $holding, 'cash_account_id' => $bank, 'date' => '2026-08-13',
        'quantity' => '2', 'price_toman' => '100', 'fee_toman' => '0',
        'idempotency_key' => 'replay-buy-later-001',
    ]);
    $sale = app(AssetDisposal::class)->sell([
        'source_id' => $holding, 'cash_id' => $bank, 'date' => '2026-08-15',
        'quantity' => '1', 'gross_toman' => '70', 'fee_toman' => '0',
        'idempotency_key' => 'replay-sale-synthetic-001',
    ]);

    return [$bank, $holding, $purchase, $sale];
}

it('replays a past purchase, keeps original sale immutable and posts only the FIFO cost difference', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank, $holding, , $sale] = replayFixture();
    $saleLines = DB::table('journal_lines')->where('operation_id', $sale)->orderBy('line_no')->get()->toArray();
    expect(app(AssetOverview::class)->realizedResult('2026-08-15')['net_toman'])->toBe('20');
    $input = [
        'account_id' => $holding, 'cash_account_id' => $bank, 'date' => '2026-08-12',
        'quantity' => '2', 'price_toman' => '80', 'fee_toman' => '0',
        'idempotency_key' => 'replay-buy-earlier-001',
    ];
    $earlier = app(BackdatedPosting::class)->buy($input);
    expect(app(BackdatedPosting::class)->buy($input))->toBe($earlier);
    expect(DB::table('journal_lines')->where('operation_id', $sale)->orderBy('line_no')->get()->toArray())->toEqual($saleLines)
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('8900')
        ->and((string) DB::table('accounts')->find($holding)->balance_rial)->toBe('1400')
        ->and((string) DB::table('lots')->where('acquisition_operation_id', $earlier)->value('remaining_atoms'))->toBe('1')
        ->and((string) DB::table('lots')->where('acquisition_operation_id', $earlier)->value('remaining_cost_rial'))->toBe('400')
        ->and((string) DB::table('lot_allocation_projections')->where('exit_operation_id', $sale)->value('allocated_cost_rial'))->toBe('400')
        ->and((string) DB::table('replay_adjustments')->where('affected_operation_id', $sale)->value('cost_delta_rial'))->toBe('-100')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-15')['net_toman'])->toBe('30')
        ->and(DB::table('projection_revisions')->count())->toBe(1);
});

it('rejects an earlier exit that makes a later sale impossible and rolls back all replay effects', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank, $holding] = replayFixture();
    $beforeOps = DB::table('operations')->count();
    $beforeCash = (string) DB::table('accounts')->find($bank)->balance_rial;
    $beforeLots = DB::table('lots')->get()->toArray();
    expect(fn () => app(BackdatedPosting::class)->sell([
        'source_id' => $holding, 'cash_id' => $bank, 'date' => '2026-08-14',
        'quantity' => '2', 'gross_toman' => '100', 'fee_toman' => '0',
        'idempotency_key' => 'replay-earlier-sale-001',
    ]))->toThrow(RuntimeException::class, 'lacks asset quantity');
    expect(DB::table('operations')->count())->toBe($beforeOps)
        ->and(DB::table('lots')->get()->toArray())->toEqual($beforeLots)
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe($beforeCash)
        ->and(DB::table('projection_revisions')->count())->toBe(0)
        ->and(DB::table('replay_adjustments')->count())->toBe(0);
});

it('posts an earlier sale with its FIFO cost adjustment atomically', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank, $holding] = replayFixture();
    $sale = app(BackdatedPosting::class)->sell([
        'source_id' => $holding, 'cash_id' => $bank, 'date' => '2026-08-14',
        'quantity' => '1', 'gross_toman' => '70', 'fee_toman' => '1',
        'idempotency_key' => 'replay-earlier-sale-002',
    ]);
    expect((string) DB::table('replay_adjustments')->where('affected_operation_id', $sale)->value('cost_delta_rial'))->toBe('500')
        ->and((string) DB::table('accounts')->find($holding)->balance_rial)->toBe('0')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('10390')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-15')['net_toman'])->toBe('39')
        ->and(DB::table('projection_revisions')->count())->toBe(1);
});

it('replays historical cash income and rejects a later negative balance with the conflicting operation', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک نقد گذشته', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'replay-cash-opening-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک نقد گذشته')->value('id');
    $expense = app(CashBook::class)->post([
        'type' => 'expense', 'date' => '2026-08-15', 'amount_toman' => '80',
        'source_id' => $bank, 'idempotency_key' => 'replay-cash-expense-001',
    ]);
    $historical = app(BackdatedPosting::class)->cash([
        'type' => 'income', 'date' => '2026-08-12', 'amount_toman' => '50',
        'target_id' => $bank, 'idempotency_key' => 'replay-cash-income-001',
    ]);
    expect((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('700')
        ->and((string) DB::table('replay_cash_positions')->where('operation_id', $historical)->value('balance_after_rial'))->toBe('1500')
        ->and((string) DB::table('replay_cash_positions')->where('operation_id', $expense)->value('balance_after_rial'))->toBe('700');
    $before = DB::table('operations')->count();
    expect(fn () => app(BackdatedPosting::class)->cash([
        'type' => 'expense', 'date' => '2026-08-13', 'amount_toman' => '80',
        'source_id' => $bank, 'idempotency_key' => 'replay-cash-expense-002',
    ]))->toThrow(RuntimeException::class, 'inconsistent');
    expect(DB::table('operations')->count())->toBe($before)
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('700');
});

it('reports opening, income, expense, internal transfer and borrowing as cash flows without double count', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک جریان', '100', '0', 'unknown');
    $setup->addItem('cash', 'نقد جریان', '20', '0', 'unknown');
    $setup->addItem('payable', 'بدهی جریان', '0', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'cashflow-opening-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک جریان')->value('id');
    $cash = (int) DB::table('accounts')->where('name', 'نقد جریان')->value('id');
    $debt = (int) DB::table('accounts')->where('name', 'بدهی جریان')->value('id');
    $book = app(CashBook::class);
    $book->post(['type' => 'income', 'date' => '2026-08-12', 'amount_toman' => '50', 'target_id' => $bank, 'idempotency_key' => 'cashflow-income-001']);
    $book->post(['type' => 'transfer', 'date' => '2026-08-13', 'amount_toman' => '30', 'source_id' => $bank, 'target_id' => $cash, 'idempotency_key' => 'cashflow-transfer-001']);
    $book->post(['type' => 'borrow', 'date' => '2026-08-14', 'amount_toman' => '10', 'source_id' => $debt, 'target_id' => $bank, 'idempotency_key' => 'cashflow-borrow-001']);
    $book->post(['type' => 'expense', 'date' => '2026-08-15', 'amount_toman' => '25', 'source_id' => $cash, 'idempotency_key' => 'cashflow-expense-001']);
    $report = app(CashFlowReport::class)->between('2026-08-11', '2026-08-16');
    expect($report['beginning_toman'])->toBe('120')
        ->and($report['inflow_toman'])->toBe('60')
        ->and($report['outflow_toman'])->toBe('25')
        ->and($report['ending_toman'])->toBe('155')
        ->and($report['coverage_complete'])->toBeFalse()
        ->and(count($report['rows']))->toBe(3);
});

it('records explicit data coverage against the seen revision without treating silence as approval', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'coverage-empty-opening', true);
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview('2026-08-31');
    expect($coverage->latest())->toBeNull();
    expect(fn () => $coverage->confirm('2026-08-31', $preview['revision'], 'coverage-confirm-001', false))->toThrow(InvalidArgumentException::class);
    $id = $coverage->confirm('2026-08-31', $preview['revision'], 'coverage-confirm-001', true);
    expect($coverage->confirm('2026-08-31', $preview['revision'], 'coverage-confirm-001', true))->toBe($id)
        ->and($coverage->latest()['through_date'])->toBe('2026-08-31');
    expect(fn () => $coverage->confirm('2026-08-30', $preview['revision'], 'coverage-confirm-002', true))->toThrow(RuntimeException::class);
});

it('records a pre-opening acquisition fact without fabricating a purchase or rewriting the opening lot', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('FACT-GOLD', 'طلای واقعیت ساختگی', 'gold', 2, 7500);
    $catalog->addOpeningItem($instrument, 'طلای واقعیت', '2', '100', '0', null, 'unknown');
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'fact-opening-001');
    $lot = DB::table('lots')->first();
    $facts = app(AssetFacts::class);
    $first = $facts->record((int) $lot->id, 'acquisition_date', '2025-01-01', '2025-01-01', 'اظهار ساختگی');
    $second = $facts->record((int) $lot->id, 'acquisition_date', '2025-02-01', '2025-02-01', 'تصحیح ساختگی', $first);
    expect((int) DB::table('asset_fact_versions')->find($second)->supersedes_id)->toBe($first)
        ->and(DB::table('lots')->find($lot->id)->acquired_date)->toBeNull()
        ->and(DB::table('operations')->where('type', 'asset_buy')->count())->toBe(0);
    expect(fn () => $facts->record((int) $lot->id, 'acquisition_date', '2026-08-11', null, 'نامعتبر'))->toThrow(InvalidArgumentException::class);
});

it('replays an earlier conversion with explicit consideration and one fee', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank, $holding] = replayFixture();
    $catalog = app(AssetCatalog::class);
    $currency = $catalog->createInstrument('FX-REPLAY', 'ارز تبدیل ساختگی', 'currency', 2);
    $target = $catalog->createHolding($currency, 'کیف تبدیل');
    $conversion = app(BackdatedPosting::class)->convert([
        'source_id' => $holding, 'target_id' => $target, 'cash_id' => $bank,
        'date' => '2026-08-14', 'quantity' => '1', 'target_quantity' => '5',
        'gross_toman' => '80', 'fee_toman' => '2', 'idempotency_key' => 'replay-convert-early-001',
    ]);
    expect((string) DB::table('lots')->where('acquisition_operation_id', $conversion)->value('cost_rial'))->toBe('820')
        ->and((string) DB::table('accounts')->find($target)->balance_rial)->toBe('820')
        ->and((string) DB::table('accounts')->find($holding)->balance_rial)->toBe('0')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('9680')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-15')['net_toman'])->toBe('50')
        ->and(DB::table('projection_revisions')->count())->toBe(1);
});

it('amends a confirmed cash opening with a linked document and replay while preserving the original', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $item = $setup->addItem('bank', 'بانک افتتاحیه اصلاح', '100', '40', 'user_declared');
    $opening = $setup->confirm((int) $setup->session()->revision, 1, 'opening-amend-original-001');
    $originalLines = DB::table('journal_lines')->where('operation_id', $opening)->orderBy('line_no')->get()->toArray();
    $amend = app(OpeningAmendment::class);
    $case = $amend->proposeCashAmount($item, '120', 'اصلاح مانده ساختگی');
    $seen = (int) DB::table('financial_state')->where('id', 1)->value('revision');
    $correction = $amend->approve($case, $seen, 'opening-amend-approval-001');
    expect($amend->approve($case, $seen, 'opening-amend-approval-001'))->toBe($correction)
        ->and(DB::table('journal_lines')->where('operation_id', $opening)->orderBy('line_no')->get()->toArray())->toEqual($originalLines)
        ->and((string) DB::table('opening_items')->find($item)->amount_rial)->toBe('1000')
        ->and((string) DB::table('accounts')->where('name', 'بانک افتتاحیه اصلاح')->value('balance_rial'))->toBe('1200')
        ->and((string) DB::table('accounts')->where('name', 'بانک افتتاحیه اصلاح')->value('protected_min_rial'))->toBe('400')
        ->and(DB::table('projection_revisions')->count())->toBe(1);
});

it('rejects an opening correction that would make a later cash operation inconsistent', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $item = $setup->addItem('bank', 'بانک افتتاحیه کمبود', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'opening-short-original-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک افتتاحیه کمبود')->value('id');
    app(CashBook::class)->post([
        'type' => 'expense', 'date' => '2026-08-12', 'amount_toman' => '90',
        'source_id' => $bank, 'idempotency_key' => 'opening-short-expense-001',
    ]);
    $amend = app(OpeningAmendment::class);
    $case = $amend->proposeCashAmount($item, '80', 'بررسی کاهش ساختگی');
    $seen = (int) DB::table('financial_state')->where('id', 1)->value('revision');
    $before = DB::table('operations')->count();
    expect(fn () => $amend->approve($case, $seen, 'opening-short-approval-001'))->toThrow(RuntimeException::class, 'inconsistent');
    expect(DB::table('operations')->count())->toBe($before)
        ->and(DB::table('amendment_cases')->find($case)->status)->toBe('draft')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('100');
});

it('keeps phase-five review private and renders its Persian controls', function (): void {
    $this->get('/review')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create());
    $this->get('/review')->assertOk()->assertSee('پوشش داده و گزارش جریان نقدی');
    Livewire::test(FinanceReview::class)->assertSee('تأیید پوشش داده');
});

it('amends an opening asset valuation and replays a later sale without changing its original journal', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('AMEND-STOCK', 'سهم افتتاحیه اصلاح', 'stock', 0);
    $openingItem = $catalog->addOpeningItem($instrument, 'سبد افتتاحیه اصلاح', '2', '100', '0', null, 'unknown');
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'asset-amend-opening-001');
    $holding = (int) DB::table('asset_accounts')->where('opening_item_id', $openingItem)->value('account_id');
    $cash = app(CashBook::class)->createAccount('نقد فروش اصلاح', 'bank');
    $sale = app(AssetDisposal::class)->sell([
        'source_id' => $holding, 'cash_id' => $cash, 'date' => '2026-08-12',
        'quantity' => '1', 'gross_toman' => '70', 'fee_toman' => '0',
        'idempotency_key' => 'asset-amend-sale-001',
    ]);
    $originalLines = DB::table('journal_lines')->where('operation_id', $sale)->orderBy('line_no')->get()->toArray();
    $amend = app(OpeningAmendment::class);
    $case = $amend->proposeAssetValue($openingItem, '80', 'تصحیح ارزش ساختگی');
    $seen = (int) DB::table('financial_state')->where('id', 1)->value('revision');
    $amend->approveAssetValue($case, $seen, 'asset-amend-approval-001');
    expect(DB::table('journal_lines')->where('operation_id', $sale)->orderBy('line_no')->get()->toArray())->toEqual($originalLines)
        ->and((string) DB::table('asset_opening_items')->find($openingItem)->opening_value_rial)->toBe('1000')
        ->and((string) DB::table('lots')->where('opening_item_id', $openingItem)->value('remaining_cost_rial'))->toBe('400')
        ->and((string) DB::table('accounts')->find($holding)->balance_rial)->toBe('400')
        ->and((string) DB::table('replay_adjustments')->where('affected_operation_id', $sale)->value('cost_delta_rial'))->toBe('-100')
        ->and(app(AssetOverview::class)->realizedResult('2026-08-12')['net_toman'])->toBe('30');
});
