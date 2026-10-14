<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\CashFlowReport;
use App\Actions\Finance\ForeignObligationSettlement;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('settles an FX receivable in two parts while separating principal and exchange gain', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک ارز ساختگی', '0', '0', 'unknown');
    $catalog = app(AssetCatalog::class);
    $currency = $catalog->createInstrument('FX-USD-TEST', 'دلار ساختگی', 'currency', 2);
    $catalog->addForeignObligationOpening($currency, 'receivable', 'طلب دلار ساختگی', '10', '100');
    $setup->confirm((int) $setup->session()->revision, 1, 'fx-receivable-opening-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک ارز ساختگی')->value('id');
    $obligation = (int) DB::table('accounts')->where('name', 'طلب دلار ساختگی')->value('id');
    $action = app(ForeignObligationSettlement::class);
    $first = ['obligation_id' => $obligation, 'cash_id' => $bank, 'date' => '2026-08-12',
        'quantity' => '4', 'cash_toman' => '50', 'idempotency_key' => 'fx-receivable-first-001'];
    $id = $action->post($first);
    expect($action->post($first))->toBe($id)
        ->and((string) DB::table('accounts')->find($obligation)->balance_rial)->toBe('600')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('500')
        ->and((string) DB::table('journal_lines')->where('operation_id', $id)
            ->where('account_id', DB::table('accounts')->where('code', 'system-fx-gain')->value('id'))->value('amount_rial'))->toBe('100');
    $action->post(['obligation_id' => $obligation, 'cash_id' => $bank, 'date' => '2026-08-13',
        'quantity' => '6', 'cash_toman' => '70', 'idempotency_key' => 'fx-receivable-final-001']);
    expect((string) DB::table('accounts')->find($obligation)->balance_rial)->toBe('0')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('1200')
        ->and(app(CashFlowReport::class)->between('2026-08-11', '2026-08-14')['inflow_toman'])->toBe('120');
});

it('rejects a cash-short FX repayment and an out-of-order prior settlement atomically', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک بدهی ارزی', '50', '0', 'unknown');
    $catalog = app(AssetCatalog::class);
    $currency = $catalog->createInstrument('FX-EUR-TEST', 'یورو ساختگی', 'currency', 2);
    $catalog->addForeignObligationOpening($currency, 'payable', 'بدهی یورو ساختگی', '10', '100');
    $setup->confirm((int) $setup->session()->revision, 1, 'fx-payable-opening-001');
    $bank = (int) DB::table('accounts')->where('name', 'بانک بدهی ارزی')->value('id');
    $debt = (int) DB::table('accounts')->where('name', 'بدهی یورو ساختگی')->value('id');
    $action = app(ForeignObligationSettlement::class);
    $before = DB::table('operations')->count();
    expect(fn () => $action->post(['obligation_id' => $debt, 'cash_id' => $bank, 'date' => '2026-08-12',
        'quantity' => '10', 'cash_toman' => '110', 'idempotency_key' => 'fx-payable-too-large-001']))
        ->toThrow(RuntimeException::class, 'inconsistent');
    expect(DB::table('operations')->count())->toBe($before);
    $action->post(['obligation_id' => $debt, 'cash_id' => $bank, 'date' => '2026-08-14',
        'quantity' => '2', 'cash_toman' => '15', 'idempotency_key' => 'fx-payable-valid-001']);
    expect((string) DB::table('accounts')->find($debt)->balance_rial)->toBe('800')
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('350');
    expect(fn () => $action->post(['obligation_id' => $debt, 'cash_id' => $bank, 'date' => '2026-08-13',
        'quantity' => '1', 'cash_toman' => '8', 'idempotency_key' => 'fx-payable-backdated-001']))
        ->toThrow(RuntimeException::class, 'later settlement');
});
