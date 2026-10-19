<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\CashBook;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use App\Support\Khums\KhumsClosureGate;
use App\Support\Khums\KhumsYearSchedule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('rejects an early or unauthenticated period report without writing', function (): void {
    $this->expectException(AuthorizationException::class);
    app(KhumsPeriodReport::class)->forYear(1405);
});

it('does not turn a quantity holding into a final account-level religious basis', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('SYNTH-KHUMS-LOT', 'سهم ساختگی', 'stock', 0);
    $catalog->addOpeningItem($instrument, 'سبد مقداری ساختگی', '2', '100', '1', '50', 'user_declared');
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-quantity-opening-001');
    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $catalog->recordPrice($instrument, '2027-08-10', '600', 'قیمت ساختگی آزمون');
    $report = app(KhumsPeriodReport::class)->forYear(1405);
    expect($report['status'])->toBe('incomplete')
        ->and($report['issues'])->toContain('quantity_basis_requires_review:account:'.DB::table('accounts')->where('name', 'سبد مقداری ساختگی')->value('id'));
});

it('uses the day before midnight boundary and keeps missing religious facts unresolved', function (): void {
    $this->actingAs(User::factory()->create());
    expect(fn () => app(KhumsPeriodReport::class)->forYear(1405))
        ->toThrow(InvalidArgumentException::class);

    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    $before = [DB::table('operations')->count(), DB::table('user_decisions')->count(), DB::table('audit_events')->count()];
    $report = app(KhumsPeriodReport::class)->forYear(1405);

    expect($report['purpose'])->toBe('period_report')
        ->and($report['period']['end_exclusive_at_utc'])->toBe('2027-08-10T20:30:00.000000Z')
        ->and($report['as_of_date'])->toBe('2027-08-10')
        ->and($report['status'])->toBe('incomplete')
        ->and($report['total_due_exact_rial'])->toBeNull()
        ->and($report['issues'])->not->toContain('simulation_date_only')
        ->and(KhumsClosureGate::blockers(
            KhumsYearSchedule::confirmedPeriod(1405), 'due', $report, [], true
        ))->toContain('calculation_incomplete')
        ->and([DB::table('operations')->count(), DB::table('user_decisions')->count(), DB::table('audit_events')->count()])->toBe($before);
    Livewire::test(KhumsWorkspace::class)->call('reviewPeriod', 1405)
        ->assertSee('گزارش بررسی دورهٔ 1405-05-20');
});

it('assigns a date-only operation on 20 Mordad to the new period', function (): void {
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک مرز ساختگی', '100', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-boundary-opening-001');
    $bankId = (int) DB::table('accounts')->where('name', 'بانک مرز ساختگی')->value('id');

    $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
    app(CashBook::class)->post([
        'type' => 'income', 'date' => '2027-08-11', 'amount_toman' => '50',
        'target_id' => $bankId, 'idempotency_key' => 'khums-boundary-income-001',
    ]);
    $report = app(KhumsPeriodReport::class)->forYear(1405);
    $bank = collect($report['positions'])->firstWhere('account_id', $bankId);
    expect($report['as_of_date'])->toBe('2027-08-10')
        ->and($bank['book_rial'])->toBe('1000')
        ->and($report['total_due_exact_rial'])->toBeNull();
});
