<?php

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\KhumsSimulation;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\KhumsWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('keeps the khums simulation private and read-only with no invented due', function (): void {
    $this->get('/khums')->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک آزمون خمس', '100', '50', 'user_declared');
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-synthetic-opening-0001');
    $before = [DB::table('operations')->count(), DB::table('user_decisions')->count(), DB::table('audit_events')->count()];

    $report = app(KhumsSimulation::class)->asOf(OpeningSetup::DATE);
    expect($report['purpose'])->toBe('simulation')
        ->and($report['boundary_status'])->toBe('confirmed')
        ->and($report['boundary_local_clock'])->toBe('00:00')
        ->and($report['boundary_timezone'])->toBe('Asia/Tehran')
        ->and($report['status'])->toBe('incomplete')
        ->and($report['total_due_exact_rial'])->toBeNull()
        ->and($report['payment_amount_rial'])->toBeNull()
        ->and($report['positions'][0]['book_rial'])->toBe('1000')
        ->and($report['issues'])->toContain('simulation_date_only')
        ->and([DB::table('operations')->count(), DB::table('user_decisions')->count(), DB::table('audit_events')->count()])->toBe($before);

    $this->get('/khums')->assertOk()->assertSee('پیش‌نویس و شبیه‌سازی خمس');
    $this->post('/khums/close')->assertNotFound();
    Livewire::test(KhumsWorkspace::class)->set('asOfDate', '۱۴۰۵-۰۵-۲۰')->call('simulate')
        ->assertHasNoErrors()->assertSee('مبلغ خمس قابل اعلام نیست');
});

it('rejects invalid or future simulation dates without writing financial data', function (): void {
    $this->actingAs(User::factory()->create());
    expect(fn () => app(KhumsSimulation::class)->asOf('2026-08-10'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(KhumsSimulation::class)->asOf('2099-01-01'))->toThrow(InvalidArgumentException::class);
    Livewire::test(KhumsWorkspace::class)->set('asOfDate', '۱۴۰۵-۱۳-۰۱')->call('simulate')->assertHasErrors('asOfDate');
    expect(DB::table('operations')->count())->toBe(0);
});

it('uses only a date-appropriate asset price and never treats book cost as religious basis', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(AssetCatalog::class);
    $instrument = $catalog->createInstrument('SYNTH-KHUMS', 'دارایی ساختگی خمس', 'stock', 0);
    $catalog->addOpeningItem($instrument, 'سبد آزمون خمس', '2', '100', '1', '50', 'user_declared');
    $setup = app(OpeningSetup::class);
    $setup->confirm((int) $setup->session()->revision, 1, 'khums-synthetic-opening-0002');
    $before = app(KhumsSimulation::class)->asOf('2026-09-10');
    $holding = collect($before['positions'])->firstWhere('name', 'سبد آزمون خمس');
    expect($holding['market_rial'])->toBeNull()
        ->and($before['total_due_exact_rial'])->toBeNull();

    $catalog->recordPrice($instrument, '2026-09-11', '600', 'قیمت ساختگیِ آینده');
    $future = app(KhumsSimulation::class)->asOf('2026-09-10');
    expect(collect($future['positions'])->firstWhere('name', 'سبد آزمون خمس')['market_rial'])->toBeNull();

    $catalog->recordPrice($instrument, '2026-09-10', '600', 'قیمت ساختگیِ همان روز');
    $priced = app(KhumsSimulation::class)->asOf('2026-09-10');
    $holding = collect($priced['positions'])->firstWhere('name', 'سبد آزمون خمس');
    expect($holding['market_rial'])->toBe('1200')
        ->and($holding['book_rial'])->toBe('1000')
        ->and($priced['total_due_exact_rial'])->toBeNull();
});
