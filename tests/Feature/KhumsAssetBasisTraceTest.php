<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetDisposal;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAssetBasisTrace;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('keeps a declared protected lot basis separate from economic cost after sale and blocks ambiguous spending', function (): void {
    $this->actingAs(User::factory()->create());
    config()->set('khums_rules.rules', [
        ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
        ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
    ]);
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک فروش پایه', '0', '0', 'unknown');
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('BASIS-TRACE', 'سهم پایه ساختگی', 'stock', 0);
    $catalog->addOpeningItem($instrument, 'سبد پایه ساختگی', '3', '80', '3', '100', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-sale-basis-opening-001');
    $bankId = (int) DB::table('accounts')->where('name', 'بانک فروش پایه')->value('id');
    $holdingId = (int) DB::table('accounts')->where('name', 'سبد پایه ساختگی')->value('id');
    $saleId = app(AssetDisposal::class)->sell([
        'source_id' => $holdingId, 'cash_id' => $bankId, 'date' => '2027-08-09',
        'quantity' => '3', 'gross_toman' => '130', 'fee_toman' => '0',
        'idempotency_key' => 'khums-sale-basis-dispose-001',
    ]);
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $trace = app(KhumsAssetBasisTrace::class)->asOf('2027-08-10');
    expect($trace['sales'][0]['operation_id'])->toBe($saleId)
        ->and($trace['sales'][0]['economic_cost_rial'])->toBe('800')
        ->and($trace['sales'][0]['allocated_basis_exact_rial'])->toBe('1000')
        ->and($trace['sales'][0]['increase_candidate_exact_rial'])->toBe('300')
        ->and($trace['sales'][0]['eligible_rial'])->toBeNull();
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $preview['revision'], 'khums-sale-basis-coverage-001', true);
    $report = app(KhumsPeriodReport::class)->forYear(1405);
    expect(array_column($report['positions'], 'account_id'))->toBe([$bankId]);
    $decisions = app(KhumsComponentDecision::class);
    $facts = ['منشأ' => 'شرح ساختگی وجه فروش و اصل مخمّس آن'];
    expect(fn () => $decisions->confirm(1405, $bankId, $report['component_input_hash'], '500', 'SYNTHETIC', $facts, 'khums-sale-basis-invalid-001'))
        ->toThrow(FinanceInputException::class);
    $decisions->confirm(1405, $bankId, $report['component_input_hash'], '300', 'SYNTHETIC', $facts, 'khums-sale-basis-confirm-001');
    expect(app(KhumsPeriodReport::class)->forYear(1405)['total_due_exact_rial'])->toBe('60');
    app(CashBook::class)->post([
        'type' => 'expense', 'date' => '2027-08-10', 'amount_toman' => '10',
        'source_id' => $bankId, 'idempotency_key' => 'khums-sale-basis-spend-001',
    ]);
    $preview = $coverage->preview('2027-08-10');
    $coverage->confirm('2027-08-10', $preview['revision'], 'khums-sale-basis-coverage-002', true);
    $afterSpending = app(KhumsPeriodReport::class)->forYear(1405);
    expect($afterSpending['issues'])->toContain('asset_proceeds_basis_requires_review:account:'.$bankId)
        ->and(fn () => $decisions->confirm(1405, $bankId, $afterSpending['component_input_hash'], '200', 'SYNTHETIC', $facts, 'khums-sale-basis-ambiguous-001'))
        ->toThrow(FinanceInputException::class);
});
