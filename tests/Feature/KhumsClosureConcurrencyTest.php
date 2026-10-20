<?php

use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use App\Support\Backup\SqliteBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('serializes two independent closure processes to one snapshot and carryover', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the independent-process SQLite check.');
    }
    $directory = sys_get_temp_dir().'/khums-close-race-'.bin2hex(random_bytes(6));
    mkdir($directory.'/app/private/backups', 0700, true);
    $database = $directory.'/database.sqlite';
    touch($database);
    $originalDatabase = config('database.default');
    $originalStorage = storage_path();
    config()->set('database.connections.khums_race', [
        'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 5000,
    ]);
    config()->set('database.default', 'khums_race');
    $this->app->useStoragePath($directory);
    try {
        Artisan::call('migrate', ['--database' => 'khums_race', '--force' => true]);
        $this->actingAs(User::factory()->create());
        config()->set('khums_rules.rules', [[
            'id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated',
            'required_facts' => ['منشأ'],
        ]]);
        $setup = app(OpeningSetup::class);
        $setup->addItem('bank', 'بانک همزمان ساختگی', '100', '0', 'unknown');
        $setup->confirm((int) $setup->session()->revision, 1, 'khums-concurrent-opening-001');
        $accountId = (int) DB::table('accounts')->where('name', 'بانک همزمان ساختگی')->value('id');
        $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
        $coverage = app(CoverageConfirmation::class);
        $coveragePreview = $coverage->preview('2027-08-10');
        $coverage->confirm('2027-08-10', $coveragePreview['revision'], 'khums-concurrent-coverage-001', true);
        $base = app(KhumsPeriodReport::class)->forYear(1405);
        app(KhumsComponentDecision::class)->confirm(1405, $accountId, $base['component_input_hash'], '1000', 'SYNTHETIC', [
            'منشأ' => 'اظهار ساختگی آزمون همزمانی',
        ], 'khums-concurrent-component-001');
        $report = app(KhumsPeriodReport::class)->forYear(1405);
        $reviewId = app(KhumsPeriodReview::class)->save(1405, $report['input_hash']);
        $bundle = $directory.'/app/private/backups/race.khfb';
        $password = 'synthetic-race-backup-password';
        app(SqliteBackup::class)->create($database, $bundle, $password);
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork the concurrency test.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('khums_race');
                    Auth::loginUsingId(1);
                    $id = app(KhumsPeriodClosure::class)->close($reviewId, $report['input_hash'], $bundle, $password, true);
                    file_put_contents($directory.'/result-'.$index, (string) $id);
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($directory.'/result-'.$index, 'error:'.$error->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        DB::purge('khums_race');
        expect(file_get_contents($directory.'/result-0'))->toBe(file_get_contents($directory.'/result-1'))
            ->and(DB::table('khums_period_closures')->count())->toBe(1)
            ->and(DB::table('khums_assessments')->count())->toBe(1)
            ->and(DB::table('khums_basis_carryovers')->count())->toBe(1);
    } finally {
        DB::disconnect('khums_race');
        config()->set('database.default', $originalDatabase);
        $this->app->useStoragePath($originalStorage);
        foreach (glob($directory.'/result-*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($directory.'/app/private/backups/race.khfb');
        @unlink($database);
        @unlink($database.'-wal');
        @unlink($database.'-shm');
        @rmdir($directory.'/app/private/backups');
        @rmdir($directory.'/app/private');
        @rmdir($directory.'/app');
        @rmdir($directory);
    }
});
