<?php

use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlementTerms;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('serializes two independent payment-agreement writers without posting payment or rewriting the calculation', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the independent agreement writer check.');
    }
    $directory = sys_get_temp_dir().'/khums-terms-race-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $database = $directory.'/temporary.sqlite';
    touch($database);
    $original = config('database.default');
    config()->set('database.connections.terms_race', ['driver' => 'sqlite', 'database' => $database,
        'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
    config()->set('database.default', 'terms_race');
    try {
        Artisan::call('migrate', ['--database' => 'terms_race', '--force' => true]);
        $this->actingAs(User::factory()->create());
        $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
        config()->set('khums_rules.rules', [
            ['id' => 'SYNTHETIC', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['منشأ']],
            ['id' => 'SYNTHETIC-AGREEMENT', 'status' => 'active', 'review' => 'validated',
                'capabilities' => [KhumsSettlementTerms::CAPABILITY], 'required_facts' => ['پرونده']],
        ]);
        $setup = app(OpeningSetup::class);
        $setup->addItem('bank', 'بانک ساختگی رقابت مستند', '100', '0', 'unknown');
        $setup->confirm((int) $setup->session()->revision, 1, 'terms-race-opening-001');
        $account = (int) DB::table('accounts')->where('name', 'بانک ساختگی رقابت مستند')->value('id');
        $coverage = app(CoverageConfirmation::class);
        $seen = $coverage->preview('2027-08-10');
        $coverage->confirm('2027-08-10', $seen['revision'], 'terms-race-coverage-001', true);
        $reports = app(KhumsPeriodReport::class);
        $base = $reports->forYear(1405);
        app(KhumsComponentDecision::class)->confirm(1405, $account, $base['component_input_hash'], '1', 'SYNTHETIC',
            ['منشأ' => 'اظهار کاملاً ساختگی برای رقابت همزمان'], 'terms-race-component-001');
        $report = $reports->forYear(1405);
        $review = app(KhumsPeriodReview::class)->save(1405, $report['input_hash']);
        $backup = Mockery::mock(KhumsBackupVerifier::class);
        $backup->shouldReceive('verifyReview')->andReturn(str_repeat('a', 64));
        app()->instance(KhumsBackupVerifier::class, $backup);
        $closure = app(KhumsPeriodClosure::class)->close($review, $report['input_hash'], '/synthetic/race.khfb', 'synthetic-key', true);
        $assessment = (int) DB::table('khums_assessments')->where('closure_id', $closure)->value('id');
        $terms = app(KhumsSettlementTerms::class);
        $preview = $terms->preview($assessment);
        $facts = array_fill_keys($terms->factLabels('SYNTHETIC-AGREEMENT'), 'شواهد کاملاً ساختگی همین پرونده برای آزمون دو نویسنده');
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork the agreement writer check.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('terms_race');
                    Auth::loginUsingId(1);
                    touch($directory.'/ready-'.$index);
                    for ($attempt = 0; $attempt < 5000 && ! file_exists($directory.'/ready-'.(1 - $index)); $attempt++) {
                        usleep(1000);
                    }
                    if (! file_exists($directory.'/ready-'.(1 - $index))) {
                        throw new RuntimeException('The second agreement writer did not reach the barrier.');
                    }
                    $id = app(KhumsSettlementTerms::class)->confirm($assessment, $preview['hash'], '1', 'SYNTHETIC-AGREEMENT',
                        $facts, 'مرجع و پروندهٔ ساختگی برای آزمون همزمانی', true);
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
        DB::purge('terms_race');
        expect(file_get_contents($directory.'/result-0'))->toBe(file_get_contents($directory.'/result-1'))
            ->and(DB::table('user_decisions')->where('kind', 'khums_settlement_terms')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'khums_settlement_terms_confirmed')->count())->toBe(1)
            ->and(DB::table('khums_settlements')->count())->toBe(0)
            ->and(DB::table('operations')->where('type', 'expense')->count())->toBe(0)
            ->and(DB::table('khums_assessments')->where('id', $assessment)->value('due_exact_rial'))->toBe('0.2');
    } finally {
        DB::disconnect('terms_race');
        config()->set('database.default', $original);
        foreach (['ready-0', 'ready-1', 'result-0', 'result-1', 'temporary.sqlite', 'temporary.sqlite-wal', 'temporary.sqlite-shm', 'temporary.sqlite-journal'] as $file) {
            @unlink($directory.'/'.$file);
        }
        @rmdir($directory);
    }
});
