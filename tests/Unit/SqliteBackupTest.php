<?php

use App\Support\Backup\SqliteBackup;

function syntheticPhaseTwoDatabase(string $path): PDO
{
    $db = new PDO('sqlite:'.$path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
    $db->exec("INSERT INTO migrations VALUES (1, '2026_10_13_000000_create_identity_tables', 1)");
    $db->exec('CREATE TABLE synthetic_rows (id INTEGER PRIMARY KEY, amount TEXT NOT NULL)');

    return $db;
}

it('takes a consistent encrypted WAL snapshot and restores only into an empty path', function () {
    $directory = sys_get_temp_dir().'/khfb-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $backup = $directory.'/backup.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        $db->exec("INSERT INTO synthetic_rows VALUES (1, '100000000')");
        $other = new PDO('sqlite:'.$source);
        $other->exec("INSERT INTO synthetic_rows VALUES (2, '11')");
        $other->beginTransaction();
        $other->exec("INSERT INTO synthetic_rows VALUES (3, '999')");
        $service = new SqliteBackup;
        $service->create($source, $backup, 'synthetic-strong-passphrase');
        $other->commit();
        expect(file_get_contents($backup))->not->toContain('100000000')
            ->and($service->verify($backup, 'synthetic-strong-passphrase')['schema'])->toBe(1);
        $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect($copy->query('SELECT amount FROM synthetic_rows ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe(['100000000', '11']);
        expect(fn () => $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase'))->toThrow(RuntimeException::class);
        expect(fn () => $service->verify($backup, 'wrong-strong-passphrase'))->toThrow(RuntimeException::class);
        $bytes = file_get_contents($backup);
        $tampered = $bytes;
        $tampered[100] = chr(ord($tampered[100]) ^ 1);
        file_put_contents($backup, $tampered);
        expect(fn () => $service->verify($backup, 'synthetic-strong-passphrase'))->toThrow(RuntimeException::class);
        file_put_contents($backup, substr($bytes, 0, -10));
        expect(fn () => $service->verify($backup, 'synthetic-strong-passphrase'))->toThrow(RuntimeException::class);
        $db->exec("UPDATE migrations SET migration = 'unknown_future_schema'");
        expect(fn () => $service->create($source, $directory.'/unknown.khfb', 'synthetic-strong-passphrase'))->toThrow(RuntimeException::class);
    } finally {
        unset($db, $other, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('recognizes the phase-eight review schema in a private backup', function (): void {
    $directory = sys_get_temp_dir().'/khfb-phase8-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $bundle = $directory.'/bundle.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        foreach ([
            '2026_10_14_000000_create_cash_finance_core',
            '2026_10_14_000001_create_quantity_assets',
            '2026_10_14_000002_create_replay_and_facts',
            '2026_10_14_000003_create_cpi_catalog',
            '2026_10_19_000000_create_khums_period_reviews',
        ] as $index => $migration) {
            $statement = $db->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $statement->execute([$migration, $index + 2]);
        }
        $backup = new SqliteBackup;
        $backup->create($source, $bundle, 'synthetic-phase-eight-passphrase');
        expect($backup->verify($bundle, 'synthetic-phase-eight-passphrase')['schema'])->toBe(6);
        $backup->restoreToEmpty($bundle, $restored, 'synthetic-phase-eight-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(6);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('recognizes the phase-eight lifecycle schema in a private backup', function (): void {
    $directory = sys_get_temp_dir().'/khfb-lifecycle-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $bundle = $directory.'/bundle.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        foreach ([
            '2026_10_14_000000_create_cash_finance_core',
            '2026_10_14_000001_create_quantity_assets',
            '2026_10_14_000002_create_replay_and_facts',
            '2026_10_14_000003_create_cpi_catalog',
            '2026_10_19_000000_create_khums_period_reviews',
            '2026_10_19_000001_create_khums_year_lifecycle',
        ] as $index => $migration) {
            $statement = $db->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $statement->execute([$migration, $index + 2]);
        }
        $service = new SqliteBackup;
        $service->create($source, $bundle, 'synthetic-lifecycle-passphrase');
        expect($service->verify($bundle, 'synthetic-lifecycle-passphrase')['schema'])->toBe(7);
        $service->restoreToEmpty($bundle, $restored, 'synthetic-lifecycle-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(7);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('recognizes the versioned khums schedule schema in a private backup', function (): void {
    $directory = sys_get_temp_dir().'/khfb-schedule-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $bundle = $directory.'/bundle.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        foreach ([
            '2026_10_14_000000_create_cash_finance_core',
            '2026_10_14_000001_create_quantity_assets',
            '2026_10_14_000002_create_replay_and_facts',
            '2026_10_14_000003_create_cpi_catalog',
            '2026_10_19_000000_create_khums_period_reviews',
            '2026_10_19_000001_create_khums_year_lifecycle',
            '2026_10_19_000002_create_khums_schedule_versions',
        ] as $index => $migration) {
            $statement = $db->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $statement->execute([$migration, $index + 2]);
        }
        $backup = new SqliteBackup;
        $backup->create($source, $bundle, 'synthetic-schedule-passphrase');
        expect($backup->verify($bundle, 'synthetic-schedule-passphrase')['schema'])->toBe(8);
        $backup->restoreToEmpty($bundle, $restored, 'synthetic-schedule-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(8);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('recognizes the phase-three schema without breaking version-one backups', function () {
    $directory = sys_get_temp_dir().'/khfb-v2-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $backup = $directory.'/backup.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        $db->exec("INSERT INTO migrations VALUES (2, '2026_10_14_000000_create_cash_finance_core', 2)");
        $service = new SqliteBackup;
        $service->create($source, $backup, 'synthetic-strong-passphrase');
        expect($service->verify($backup, 'synthetic-strong-passphrase')['schema'])->toBe(2);
        $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(2);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('verifies and restores the phase-four schema manifest', function () {
    $directory = sys_get_temp_dir().'/khfb-v3-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $backup = $directory.'/backup.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        $db->exec("INSERT INTO migrations VALUES (2, '2026_10_14_000000_create_cash_finance_core', 2)");
        $db->exec("INSERT INTO migrations VALUES (3, '2026_10_14_000001_create_quantity_assets', 3)");
        $service = new SqliteBackup;
        $service->create($source, $backup, 'synthetic-strong-passphrase');
        expect($service->verify($backup, 'synthetic-strong-passphrase')['schema'])->toBe(3);
        $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(3);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('verifies and restores the phase-five schema manifest', function () {
    $directory = sys_get_temp_dir().'/khfb-v4-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $backup = $directory.'/backup.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        $db->exec("INSERT INTO migrations VALUES (2, '2026_10_14_000000_create_cash_finance_core', 2)");
        $db->exec("INSERT INTO migrations VALUES (3, '2026_10_14_000001_create_quantity_assets', 3)");
        $db->exec("INSERT INTO migrations VALUES (4, '2026_10_14_000002_create_replay_and_facts', 4)");
        $service = new SqliteBackup;
        $service->create($source, $backup, 'synthetic-strong-passphrase');
        expect($service->verify($backup, 'synthetic-strong-passphrase')['schema'])->toBe(4);
        $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(4);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('verifies and restores the phase-six CPI schema manifest', function () {
    $directory = sys_get_temp_dir().'/khfb-v5-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $source = $directory.'/source.sqlite';
    $backup = $directory.'/backup.khfb';
    $restored = $directory.'/restored.sqlite';
    try {
        $db = syntheticPhaseTwoDatabase($source);
        $db->exec("INSERT INTO migrations VALUES (2, '2026_10_14_000000_create_cash_finance_core', 2)");
        $db->exec("INSERT INTO migrations VALUES (3, '2026_10_14_000001_create_quantity_assets', 3)");
        $db->exec("INSERT INTO migrations VALUES (4, '2026_10_14_000002_create_replay_and_facts', 4)");
        $db->exec("INSERT INTO migrations VALUES (5, '2026_10_14_000003_create_cpi_catalog', 5)");
        $service = new SqliteBackup;
        $service->create($source, $backup, 'synthetic-strong-passphrase');
        expect($service->verify($backup, 'synthetic-strong-passphrase')['schema'])->toBe(5);
        $service->restoreToEmpty($backup, $restored, 'synthetic-strong-passphrase');
        $copy = new PDO('sqlite:'.$restored);
        expect((int) $copy->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBe(5);
    } finally {
        unset($db, $copy);
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
