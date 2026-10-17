<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\CpiCatalog;
use App\Actions\Finance\EconomicPerformanceReport;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\CpiWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function syntheticEconomicOpening(string $toman = '100'): int
{
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک آزمون اقتصادی', $toman, '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'economic-opening-test-0001');

    return (int) DB::table('accounts')->where('name', 'بانک آزمون اقتصادی')->value('id');
}

function syntheticEconomicSeries(array $levels): int
{
    $catalog = app(CpiCatalog::class);
    $series = $catalog->createSeries('national_all_households', 1400, 'synthetic-test', 'https://example.org/synthetic-cpi');
    foreach ($levels as [$year, $month, $level]) {
        $catalog->record($series, $year, $month, $level, 'https://example.org/synthetic-cpi-point');
    }

    return $series;
}

function confirmEconomicCoverage(string $date): void
{
    $coverage = app(CoverageConfirmation::class);
    $preview = $coverage->preview($date);
    $coverage->confirm($date, $preview['revision'], 'economic-coverage-test-0001', true);
}

it('computes net-worth performance with income flow in its own Persian month', function (): void {
    $this->actingAs(User::factory()->create());
    $bank = syntheticEconomicOpening('100');
    app(CashBook::class)->post([
        'type' => 'income', 'date' => '2026-09-01', 'amount_toman' => '50',
        'target_id' => $bank, 'idempotency_key' => 'economic-income-test-0001',
    ]);
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '125'], [1405, 7, '150']]);
    confirmEconomicCoverage('2026-10-10');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-10-11', $series);
    expect($report['status'])->toBe('provisional')
        ->and($report['opening_value_rial'])->toBe('1000')
        ->and($report['closing_value_rial'])->toBe('1500')
        ->and($report['nominal_gain_rial'])->toBe('0')
        ->and($report['real_gain_rial'])->toBe('-600')
        ->and($report['flows'][0]['amount_rial'])->toBe('500')
        ->and($report['series_base_year'])->toBe(1400)
        ->and($report['cpi_revision_ids'])->toHaveCount(3);
});

it('marks real return incomplete for a missing endpoint CPI while retaining nominal result', function (): void {
    $this->actingAs(User::factory()->create());
    syntheticEconomicOpening();
    $series = syntheticEconomicSeries([[1405, 5, '100']]);
    confirmEconomicCoverage('2026-09-10');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['status'])->toBe('incomplete')
        ->and($report['nominal_gain_rial'])->toBe('0')
        ->and($report['real_gain_rial'])->toBeNull()
        ->and($report['missing'])->toContain('cpi_missing:1405-06');
});

it('does not count internal transfers, lending, borrowing or opening as external contributions', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک اول اقتصادی', '100', '0', 'unknown');
    $setup->addItem('cash', 'نقد دوم اقتصادی', '0', '0', 'unknown');
    $setup->addItem('payable', 'بدهی آزمون اقتصادی', '0', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'economic-opening-test-0002');
    $first = (int) DB::table('accounts')->where('name', 'بانک اول اقتصادی')->value('id');
    $second = (int) DB::table('accounts')->where('name', 'نقد دوم اقتصادی')->value('id');
    $payable = (int) DB::table('accounts')->where('name', 'بدهی آزمون اقتصادی')->value('id');
    app(CashBook::class)->post(['type' => 'transfer', 'date' => '2026-09-01', 'amount_toman' => '20',
        'source_id' => $first, 'target_id' => $second, 'idempotency_key' => 'economic-transfer-0001']);
    app(CashBook::class)->post(['type' => 'borrow', 'date' => '2026-09-02', 'amount_toman' => '10',
        'source_id' => $payable, 'target_id' => $second, 'idempotency_key' => 'economic-borrow-0001']);
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '100']]);
    confirmEconomicCoverage('2026-09-10');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['flows'])->toBe([])
        ->and($report['opening_value_rial'])->toBe('1000')
        ->and($report['closing_value_rial'])->toBe('1000')
        ->and($report['nominal_gain_rial'])->toBe('0')
        ->and($report['real_gain_rial'])->toBe('0');
});

it('requires a date-appropriate market price and never substitutes a later price', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('SYNTH-ECON-STOCK', 'سهم ساختگی اقتصادی', 'stock', 0);
    $catalog->addOpeningItem($instrument, 'دارایی ساختگی اقتصادی', '2', '100', '0', null, 'unknown');
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'economic-opening-test-0003');
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '100']]);
    confirmEconomicCoverage('2026-09-10');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['opening_value_rial'])->toBe('1000')
        ->and($report['closing_value_rial'])->toBeNull()
        ->and($report['nominal_gain_rial'])->toBeNull()
        ->and($report['missing'][0])->toStartWith('price_missing:');
    $catalog->recordPrice($instrument, '2026-09-11', '600', 'قیمت ساختگیِ بعد از پایان');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['closing_value_rial'])->toBeNull();
    $catalog->recordPrice($instrument, '2026-09-10', '600', 'قیمت ساختگیِ درون بازه');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['closing_value_rial'])->toBe('1200')
        ->and($report['nominal_gain_rial'])->toBe('200')
        ->and($report['real_gain_rial'])->toBe('200');
});

it('keeps a report incomplete when coverage has become stale after a new posting', function (): void {
    $this->actingAs(User::factory()->create());
    $bank = syntheticEconomicOpening('100');
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '100']]);
    confirmEconomicCoverage('2026-09-10');
    app(CashBook::class)->post(['type' => 'income', 'date' => '2026-09-01', 'amount_toman' => '10',
        'target_id' => $bank, 'idempotency_key' => 'economic-late-income-0001']);
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series);
    expect($report['status'])->toBe('incomplete')
        ->and($report['missing'])->toContain('coverage_not_confirmed')
        ->and($report['real_gain_rial'])->toBeNull();
});

it('renders the economic report privately in Persian without promoting it to khums', function (): void {
    $this->get('/cpi')->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    syntheticEconomicOpening('100');
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '110']]);
    confirmEconomicCoverage('2026-09-10');
    Livewire::test(CpiWorkspace::class)
        ->set('reportStart', '۱۴۰۵/۰۵/۲۰')->set('reportEnd', '۱۴۰۵/۰۶/۲۰')
        ->set('reportSeriesId', (string) $series)->call('runReport')
        ->assertHasNoErrors()->assertSee('سود واقعی')->assertSee('موقت؛ منبع شاخص تأیید نشده');
});

it('treats a bank-to-broker transfer as portfolio capital while keeping it internal to net worth', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک بیرون سبد', '100', '0', 'unknown');
    $setup->addItem('broker_cash', 'نقد کارگزاری سبد', '0', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'economic-opening-test-0004');
    $bank = (int) DB::table('accounts')->where('name', 'بانک بیرون سبد')->value('id');
    $broker = (int) DB::table('accounts')->where('name', 'نقد کارگزاری سبد')->value('id');
    app(CashBook::class)->post(['type' => 'transfer', 'date' => '2026-09-01', 'amount_toman' => '20',
        'source_id' => $bank, 'target_id' => $broker, 'idempotency_key' => 'economic-basket-transfer-0001']);
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '100']]);
    confirmEconomicCoverage('2026-09-10');
    $report = app(EconomicPerformanceReport::class);
    $whole = $report->between('2026-08-11', '2026-09-11', $series);
    $basket = $report->between('2026-08-11', '2026-09-11', $series, [$broker]);
    expect($whole['flows'])->toBe([])
        ->and($whole['nominal_gain_rial'])->toBe('0')
        ->and($basket['scope'])->toBe('investment_portfolio')
        ->and($basket['opening_value_rial'])->toBe('0')
        ->and($basket['closing_value_rial'])->toBe('200')
        ->and($basket['flows'][0]['amount_rial'])->toBe('200')
        ->and($basket['nominal_gain_rial'])->toBe('0')
        ->and($basket['real_gain_rial'])->toBe('0');
});

it('marks an unclassified income into a selected basket as needing review', function (): void {
    $this->actingAs(User::factory()->create());
    $broker = syntheticEconomicOpening('100');
    app(CashBook::class)->post(['type' => 'income', 'date' => '2026-09-01', 'amount_toman' => '10',
        'target_id' => $broker, 'idempotency_key' => 'economic-basket-income-0001']);
    $series = syntheticEconomicSeries([[1405, 5, '100'], [1405, 6, '100']]);
    confirmEconomicCoverage('2026-09-10');
    $report = app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series, [$broker]);
    expect($report['status'])->toBe('incomplete')
        ->and($report['missing'][0])->toStartWith('portfolio_boundary_ambiguous:')
        ->and($report['real_gain_rial'])->toBeNull();
});

it('rejects a different coverage instead of mixing incompatible CPI series', function (): void {
    $this->actingAs(User::factory()->create());
    syntheticEconomicOpening();
    $series = app(CpiCatalog::class)->createSeries('urban_households', 1400, 'synthetic-urban', 'https://example.org/urban');
    expect(fn () => app(EconomicPerformanceReport::class)->between('2026-08-11', '2026-09-11', $series))
        ->toThrow(InvalidArgumentException::class, 'national all-households');
});
