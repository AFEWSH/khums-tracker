<?php

use App\Actions\Finance\KhumsOriginPlan;
use App\Actions\Finance\OpeningSetup;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

it('confirms one origin plan under two independently connected SQLite writers', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the independent SQLite writer check.');
    }
    $directory = sys_get_temp_dir().'/khums-origin-race-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $database = $directory.'/temporary.sqlite';
    touch($database);
    $original = config('database.default');
    config()->set('database.connections.origin_race', ['driver' => 'sqlite', 'database' => $database,
        'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
    config()->set('database.default', 'origin_race');
    try {
        Artisan::call('migrate', ['--database' => 'origin_race', '--force' => true]);
        $this->actingAs(User::factory()->create());
        $this->travelTo(new DateTimeImmutable('2027-08-10T20:30:00+00:00'));
        config()->set('khums_rules.rules', [
            ['id' => 'K01', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['پایه']],
            ['id' => 'K12', 'status' => 'active', 'review' => 'validated', 'required_facts' => ['اختلاط']],
        ]);
        $setup = app(OpeningSetup::class);
        $setup->addItem('bank', 'بانک ساختگی رقابت منشأ', '100', '100', 'user_declared');
        $setup->confirm((int) $setup->session()->revision, 1, 'origin-race-opening-001');
        $plans = app(KhumsOriginPlan::class);
        $preview = $plans->preview('2027-08-10');
        $facts = array_fill_keys($plans->factLabels(), 'شواهد کاملاً ساختگی برای آزمون همزمانی دو فرایند');
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork the origin-plan check.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('origin_race');
                    Auth::loginUsingId(1);
                    touch($directory.'/ready-'.$index);
                    for ($attempt = 0; $attempt < 5000 && ! file_exists($directory.'/ready-'.(1 - $index)); $attempt++) {
                        usleep(1000);
                    }
                    if (! file_exists($directory.'/ready-'.(1 - $index))) {
                        throw new RuntimeException('The second writer did not reach the barrier.');
                    }
                    $id = app(KhumsOriginPlan::class)->confirm('2027-08-10', $preview['hash'], [], $facts, true);
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
        DB::purge('origin_race');
        expect(file_get_contents($directory.'/result-0'))->toBe(file_get_contents($directory.'/result-1'))
            ->and(DB::table('user_decisions')->where('kind', 'khums_origin_plan')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'khums_origin_plan_confirmed')->count())->toBe(1);
    } finally {
        DB::disconnect('origin_race');
        config()->set('database.default', $original);
        foreach (['ready-0', 'ready-1', 'result-0', 'result-1', 'temporary.sqlite', 'temporary.sqlite-wal', 'temporary.sqlite-shm'] as $file) {
            @unlink($directory.'/'.$file);
        }
        @rmdir($directory);
    }
});
