<?php

it('serializes two independent SQLite writers before they read a spendable balance', function (): void {
    $path = sys_get_temp_dir().'/khums-write-lock-'.bin2hex(random_bytes(6)).'.sqlite';
    try {
        $first = new PDO('sqlite:'.$path);
        $second = new PDO('sqlite:'.$path);
        foreach ([$first, $second] as $connection) {
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connection->exec('PRAGMA busy_timeout=100');
        }
        $first->exec('CREATE TABLE financial_state (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL)');
        $first->exec('CREATE TABLE cash_position (id INTEGER PRIMARY KEY, balance_rial INTEGER NOT NULL)');
        $first->exec('INSERT INTO financial_state VALUES (1, 0)');
        $first->exec('INSERT INTO cash_position VALUES (1, 100)');
        $first->beginTransaction();
        $first->exec('UPDATE financial_state SET revision = revision + 1 WHERE id = 1');
        expect((int) $first->query('SELECT balance_rial FROM cash_position')->fetchColumn())->toBe(100);
        $first->exec('UPDATE cash_position SET balance_rial = 20 WHERE id = 1');
        $second->beginTransaction();
        expect(fn () => $second->exec('UPDATE financial_state SET revision = revision + 1 WHERE id = 1'))->toThrow(PDOException::class);
        $second->rollBack();
        $first->commit();
        $second->beginTransaction();
        $second->exec('UPDATE financial_state SET revision = revision + 1 WHERE id = 1');
        expect((int) $second->query('SELECT balance_rial FROM cash_position')->fetchColumn())->toBe(20);
        $second->commit();
        expect((int) $first->query('SELECT revision FROM financial_state')->fetchColumn())->toBe(2);
    } finally {
        unset($first, $second);
        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
