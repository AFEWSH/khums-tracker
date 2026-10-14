<?php

use App\Actions\Finance\CsvCashImport;
use App\Actions\Finance\OpeningSetup;
use App\Livewire\CashCsvImport as CashCsvImportPage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function cashCsvFixture(): array
{
    $setup = app(OpeningSetup::class);
    $setup->addItem('bank', 'بانک CSV ساختگی', '100', '0', 'unknown');
    $setup->addItem('cash', 'نقد CSV ساختگی', '0', '0', 'unknown');
    $setup->confirm((int) $setup->session()->revision, 1, 'csv-opening-synthetic-001');

    return [
        (int) DB::table('accounts')->where('name', 'بانک CSV ساختگی')->value('id'),
        (int) DB::table('accounts')->where('name', 'نقد CSV ساختگی')->value('id'),
    ];
}

function cashCsvMapping(): array
{
    return array_combine(CsvCashImport::COLUMNS, CsvCashImport::COLUMNS);
}

it('previews and atomically imports Persian-date rial CSV through manual posting with stable references', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank, $cash] = cashCsvFixture();
    $csv = "reference,type,date,amount,source,target,category,reason,after_opening\n"
        ."r-1,income,۱۴۰۵/۰۵/۲۱,۲۰۰, ,بانک CSV ساختگی,,درآمد ساختگی,0\n"
        ."r-2,transfer,۱۴۰۵/۰۵/۲۲,۱۰۰,بانک CSV ساختگی,نقد CSV ساختگی,,انتقال ساختگی,0\n";
    $service = app(CsvCashImport::class);
    $plan = $service->preview($csv, cashCsvMapping(), 'rial', 'persian');
    expect($plan['rows'])->toHaveCount(2)
        ->and($plan['rows'][0]['input']['amount_toman'])->toBe('20')
        ->and(DB::table('operations')->count())->toBe(1);
    expect($service->commit($plan, false))->toBe(['posted' => 2, 'repeated' => 0]);
    expect((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('1100')
        ->and((string) DB::table('accounts')->find($cash)->balance_rial)->toBe('100');
    $repeat = $service->preview($csv, cashCsvMapping(), 'rial', 'persian');
    expect($repeat['warnings'])->toHaveCount(2)
        ->and($service->commit($repeat, true))->toBe(['posted' => 0, 'repeated' => 2])
        ->and(DB::table('operations')->count())->toBe(3);
});

it('rolls back the entire CSV batch when a middle row makes the cash balance negative', function (): void {
    $this->actingAs(User::factory()->create());
    [$bank] = cashCsvFixture();
    $csv = "reference,type,date,amount,source,target,category,reason,after_opening\n"
        ."safe,expense,2026-08-12,20,بانک CSV ساختگی,,,,0\n"
        ."too-much,expense,2026-08-13,200,بانک CSV ساختگی,,,,0\n";
    $service = app(CsvCashImport::class);
    $plan = $service->preview($csv, cashCsvMapping(), 'toman', 'gregorian');
    expect(fn () => $service->commit($plan, false))->toThrow(RuntimeException::class, 'CSV row 3');
    expect(DB::table('operations')->count())->toBe(1)
        ->and((string) DB::table('accounts')->find($bank)->balance_rial)->toBe('1000');
});

it('rejects duplicate references, changed payloads, formulas and unsupported operations', function (): void {
    $this->actingAs(User::factory()->create());
    cashCsvFixture();
    $service = app(CsvCashImport::class);
    $header = "reference,type,date,amount,source,target,category,reason,after_opening\n";
    $row = "same,expense,2026-08-12,10,بانک CSV ساختگی,,,,0\n";
    expect(fn () => $service->preview($header.$row.$row, cashCsvMapping(), 'toman', 'gregorian'))
        ->toThrow(InvalidArgumentException::class, 'Duplicate reference');
    expect(fn () => $service->preview($header."x,expense,2026-08-12,=1+1,بانک CSV ساختگی,,,,0\n", cashCsvMapping(), 'toman', 'gregorian'))
        ->toThrow(InvalidArgumentException::class, 'unsafe');
    expect(fn () => $service->preview($header."x,asset_buy,2026-08-12,10,بانک CSV ساختگی,,,,0\n", cashCsvMapping(), 'toman', 'gregorian'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported');
    $first = $service->preview($header.$row, cashCsvMapping(), 'toman', 'gregorian');
    $service->commit($first, false);
    $changed = $service->preview($header."same,expense,2026-08-12,11,بانک CSV ساختگی,,,,0\n", cashCsvMapping(), 'toman', 'gregorian');
    expect(fn () => $service->commit($changed, true))->toThrow(RuntimeException::class, 'different data');
});

it('warns about identical content under different references and requires explicit acknowledgement', function (): void {
    $this->actingAs(User::factory()->create());
    cashCsvFixture();
    $csv = "reference,type,date,amount,source,target,category,reason,after_opening\n"
        ."one,expense,2026-08-12,10,بانک CSV ساختگی,,,,0\n"
        ."two,expense,2026-08-12,10,بانک CSV ساختگی,,,,0\n";
    $service = app(CsvCashImport::class);
    $plan = $service->preview($csv, cashCsvMapping(), 'toman', 'gregorian');
    expect($plan['warnings'])->toHaveCount(1);
    expect(fn () => $service->commit($plan, false))->toThrow(RuntimeException::class, 'acknowledge');
    expect($service->commit($plan, true))->toBe(['posted' => 2, 'repeated' => 0]);
});

it('keeps the CSV page private and renders its Persian mapping form', function (): void {
    $this->get('/cash/import')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/cash/import')->assertOk()->assertSee('ورود گروهی عملیات نقدی');
    Livewire::test(CashCsvImportPage::class)->assertSee('مرجع یکتا');
});

it('previews and confirms a private CSV upload through the Livewire page', function (): void {
    $this->actingAs(User::factory()->create());
    cashCsvFixture();
    $csv = "reference,type,date,amount,source,target,category,reason,after_opening\n"
        ."upload-1,expense,2026-08-12,10,بانک CSV ساختگی,,,,0\n";
    Livewire::test(CashCsvImportPage::class)
        ->set('calendar', 'gregorian')
        ->set('csvFile', UploadedFile::fake()->createWithContent('sample.csv', $csv))
        ->call('preview')->assertHasNoErrors()->assertSee('upload-1')
        ->call('confirm')->assertHasNoErrors()->assertSee('1 عملیات ثبت شد');
    expect(DB::table('operations')->where('type', 'expense')->count())->toBe(1);
});

it('uses explicit Persian header mapping and refuses a stale preview', function (): void {
    $this->actingAs(User::factory()->create());
    cashCsvFixture();
    $mapping = cashCsvMapping();
    $mapping['reference'] = 'شناسه';
    $mapping['type'] = 'نوع';
    $mapping['date'] = 'روز';
    $mapping['amount'] = 'مبلغ';
    $csv = "شناسه,نوع,روز,مبلغ,source,target,category,reason,after_opening\n"
        ."mapped,expense,1405/05/21,10,بانک CSV ساختگی,,,,0\n";
    $service = app(CsvCashImport::class);
    $plan = $service->preview($csv, $mapping, 'toman', 'persian');
    expect($plan['rows'][0]['input']['date'])->toBe('2026-08-12');
    DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1')]);
    expect(fn () => $service->commit($plan, false))->toThrow(RuntimeException::class, 'changed after preview');
    expect(DB::table('operations')->count())->toBe(1);
});
