<?php

use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsBackupVerifier;
use App\Models\User;
use App\Support\Backup\SqliteBackup;
use Illuminate\Support\Facades\DB;

it('restores a private encrypted backup and checks it contains the exact review', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $hash = str_repeat('a', 64);
    $input = str_repeat('b', 64);
    $reviewId = DB::table('khums_period_reviews')->insertGetId([
        'start_year' => 1405, 'start_at_utc' => '2026-08-10T20:30:00.000000Z',
        'end_exclusive_at_utc' => '2027-08-10T20:30:00.000000Z',
        'as_of_date' => '2027-08-10', 'financial_revision' => 0,
        'rule_manifest_hash' => str_repeat('c', 64), 'input_hash' => $input,
        'snapshot_hash' => $hash, 'snapshot_json' => '{}', 'status' => 'incomplete',
        'created_by' => $user->id, 'created_at' => now(),
    ]);
    $directory = sys_get_temp_dir().'/khums-proof-'.bin2hex(random_bytes(6));
    mkdir($directory.'/app/private/backups', 0700, true);
    $this->app->useStoragePath($directory);
    $source = $directory.'/source.sqlite';
    $bundle = $directory.'/app/private/backups/test.khfb';
    $db = new PDO('sqlite:'.$source);
    $db->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
    $db->exec('CREATE TABLE khums_period_reviews (id INTEGER PRIMARY KEY, snapshot_hash TEXT, input_hash TEXT)');
    $migrations = [
        '2026_10_13_000000_create_identity_tables',
        '2026_10_14_000000_create_cash_finance_core',
        '2026_10_14_000001_create_quantity_assets',
        '2026_10_14_000002_create_replay_and_facts',
        '2026_10_14_000003_create_cpi_catalog',
        '2026_10_19_000000_create_khums_period_reviews',
        '2026_10_19_000001_create_khums_year_lifecycle',
        '2026_10_19_000002_create_khums_schedule_versions',
    ];
    $statement = $db->prepare('INSERT INTO migrations (migration,batch) VALUES (?,1)');
    foreach ($migrations as $migration) {
        $statement->execute([$migration]);
    }
    $statement = $db->prepare('INSERT INTO khums_period_reviews VALUES (?,?,?)');
    $statement->execute([$reviewId, $hash, $input]);
    $password = 'synthetic-private-proof-password';
    $backup = new SqliteBackup;
    try {
        $backup->create($source, $bundle, $password);
        $verifier = app(KhumsBackupVerifier::class);
        expect($verifier->verifyReview($reviewId, $bundle, $password))->toBe($backup->verify($bundle, $password)['sha256']);
        $db->exec('UPDATE khums_period_reviews SET input_hash = '.$db->quote(str_repeat('d', 64)));
        $changed = $directory.'/app/private/backups/changed.khfb';
        $backup->create($source, $changed, $password);
        expect(fn () => $verifier->verifyReview($reviewId, $changed, $password))->toThrow(FinanceInputException::class);
    } finally {
        unset($db);
        @unlink($source);
        @unlink($bundle);
        @unlink($directory.'/app/private/backups/changed.khfb');
        @rmdir($directory.'/app/private/backups');
        @rmdir($directory.'/app/private');
        @rmdir($directory.'/app');
        @rmdir($directory);
    }
});
