<?php

use App\Actions\Finance\CpiCatalog;
use App\Actions\Finance\CpiCsvImport;
use App\Actions\Finance\FinanceInputException;
use App\Livewire\CpiWorkspace;
use App\Models\User;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('keeps CPI entry private and does not select a default series', function (): void {
    $this->get('/cpi')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/cpi')->assertOk()->assertSee('شاخص قیمت مصرف‌کننده');
    Livewire::test(CpiWorkspace::class)->assertSee('ثبت سری تأییدنشده');
    expect(DB::table('cpi_series')->count())->toBe(0);
});

it('stores exact monthly observations and preserves corrected revisions', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(CpiCatalog::class);
    $series = $catalog->createSeries('national_all_households', 1400, 'synthetic-v1', 'https://example.org/cpi-series');
    $id = $catalog->record($series, 1405, 12, '۱۲۳٫۴۵۶۷۸۹', 'https://example.org/cpi-1405-12', '2027-04-10');
    $point = DB::table('cpi_points')->first();
    expect($point->month_start_date)->toBe('2027-02-20')
        ->and($point->next_month_start_date)->toBe('2027-03-21')
        ->and(DB::table('cpi_series')->first()->verification_status)->toBe('unverified')
        ->and(DB::table('cpi_revisions')->find($id)->value_decimal)->toBe('123.456789');
    expect($catalog->record($series, 1405, 12, '123.456789', 'https://example.org/cpi-1405-12', '2027-04-10'))->toBe($id);
    expect(fn () => $catalog->record($series, 1405, 12, '124', 'https://example.org/cpi-1405-12'))
        ->toThrow(FinanceInputException::class, 'reason');
    $second = $catalog->record($series, 1405, 12, '124', 'https://example.org/cpi-revised', '2027-04-11', 'Synthetic publication correction');
    expect(DB::table('cpi_revisions')->count())->toBe(2)
        ->and(DB::table('cpi_revisions')->find($id)->value_decimal)->toBe('123.456789')
        ->and(DB::table('cpi_revisions')->find($second)->revision_no)->toBe(2)
        ->and($catalog->latestPoints($series)[0]['level'])->toBe('124');
});

it('rejects invalid series, levels and dates without creating partial observations', function (): void {
    $this->actingAs(User::factory()->create());
    $catalog = app(CpiCatalog::class);
    expect(fn () => $catalog->createSeries('national_all_households', 1400, 'x', 'http://example.org/cpi'))
        ->toThrow(InvalidArgumentException::class);
    $series = $catalog->createSeries('national_all_households', 1400, 'synthetic-v1', 'https://example.org/cpi');
    foreach (['0', '-1', '12.1234567', '=100', 'NaN'] as $level) {
        try {
            $catalog->record($series, 1405, 5, $level, 'https://example.org/point');
            test()->fail('Invalid CPI level was accepted: '.$level);
        } catch (InvalidArgumentException|MathException) {
            // Invalid input must fail before a point or revision is written.
        }
    }
    expect(fn () => $catalog->record($series, 1405, 13, '100', 'https://example.org/point'))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('cpi_points')->count())->toBe(0)
        ->and(DB::table('cpi_revisions')->count())->toBe(0);
});

it('enters a synthetic CPI point through Livewire and shows an error for correction without reason', function (): void {
    $this->actingAs(User::factory()->create());
    $component = Livewire::test(CpiWorkspace::class)
        ->set('coverage', 'national_all_households')->set('baseYear', '1400')
        ->set('methodologyVersion', 'synthetic-v1')->set('seriesSourceUrl', 'https://example.org/cpi')
        ->call('addSeries')->assertHasNoErrors();
    $component->set('year', '1405')->set('month', '5')->set('level', '100.25')
        ->set('pointSourceUrl', 'https://example.org/point')->call('addPoint')->assertHasNoErrors();
    $component->set('level', '101')->set('pointSourceUrl', 'https://example.org/point')
        ->call('addPoint')->assertHasErrors('point');
    expect(DB::table('cpi_revisions')->count())->toBe(1);
});

it('previews and atomically imports synthetic CPI CSV with idempotent repeats', function (): void {
    $this->actingAs(User::factory()->create());
    $series = app(CpiCatalog::class)->createSeries('national_all_households', 1400, 'synthetic-csv', 'https://example.org/cpi');
    $csv = "year,month,level,source_url,publication_date,reason\n"
        ."۱۴۰۵,۵,۱۰۰٫۲۵,https://example.org/cpi-5,,\n"
        ."1405,6,101.5,https://example.org/cpi-6,2026-10-01,\n";
    $import = app(CpiCsvImport::class);
    $plan = $import->preview($csv, $series);
    expect(DB::table('cpi_revisions')->count())->toBe(0)
        ->and($plan['rows'])->toHaveCount(2)
        ->and($import->commit($plan))->toBe(2)
        ->and(DB::table('cpi_revisions')->count())->toBe(2);
    $repeat = $import->preview($csv, $series);
    expect($import->commit($repeat))->toBe(2)
        ->and(DB::table('cpi_revisions')->count())->toBe(2);
});

it('rolls back CPI CSV when a later row is invalid or preview is stale', function (): void {
    $this->actingAs(User::factory()->create());
    $series = app(CpiCatalog::class)->createSeries('national_all_households', 1400, 'synthetic-csv', 'https://example.org/cpi');
    $import = app(CpiCsvImport::class);
    $csv = "year,month,level,source_url,publication_date,reason\n"
        ."1405,5,100,https://example.org/cpi-5,,\n"
        ."1405,6,101,https://example.org/cpi-6,,\n";
    $plan = $import->preview($csv, $series);
    $plan['rows'][1]['level'] = '0';
    $plan['digest'] = hash('sha256', json_encode([$plan['series_id'], $plan['rows'], $plan['revision']], JSON_THROW_ON_ERROR));
    expect(fn () => $import->commit($plan))->toThrow(RuntimeException::class, 'line 3');
    expect(DB::table('cpi_revisions')->count())->toBe(0)
        ->and(DB::table('cpi_points')->count())->toBe(0);
    $valid = $import->preview($csv, $series);
    DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1')]);
    expect(fn () => $import->commit($valid))->toThrow(RuntimeException::class, 'changed after preview');
});

it('rejects duplicate months and formula-like CPI CSV values', function (): void {
    $this->actingAs(User::factory()->create());
    $series = app(CpiCatalog::class)->createSeries('national_all_households', 1400, 'synthetic-csv', 'https://example.org/cpi');
    $import = app(CpiCsvImport::class);
    $head = "year,month,level,source_url,publication_date,reason\n";
    $row = "1405,5,100,https://example.org/cpi,,\n";
    expect(fn () => $import->preview($head.$row.$row, $series))->toThrow(InvalidArgumentException::class, 'Duplicate');
    expect(fn () => $import->preview($head."1405,5,=1+1,https://example.org/cpi,,\n", $series))
        ->toThrow(InvalidArgumentException::class, 'Unsafe');
});
