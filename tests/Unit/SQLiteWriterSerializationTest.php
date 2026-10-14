<?php

it('serializes two independent SQLite writers and prevents a duplicate or oversold final unit', function (): void {
    $path = sys_get_temp_dir().'/khums-writer-'.bin2hex(random_bytes(8)).'.sqlite';
    try {
        $first = new PDO('sqlite:'.$path);
        $second = new PDO('sqlite:'.$path);
        foreach ([$first, $second] as $db) {
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA busy_timeout=50');
        }
        $first->exec('CREATE TABLE synthetic_state (id INTEGER PRIMARY KEY, remaining INTEGER NOT NULL)');
        $first->exec('CREATE TABLE synthetic_posts (idempotency_key TEXT UNIQUE NOT NULL)');
        $first->exec('INSERT INTO synthetic_state VALUES (1, 1)');

        $first->exec('BEGIN IMMEDIATE');
        $first->exec('UPDATE synthetic_state SET remaining=remaining-1 WHERE id=1 AND remaining>=1');
        $first->exec("INSERT INTO synthetic_posts VALUES ('same-request')");
        expect(fn () => $second->exec('BEGIN IMMEDIATE'))->toThrow(PDOException::class);
        $first->exec('COMMIT');

        $second->exec('BEGIN IMMEDIATE');
        $duplicateRejected = false;
        try {
            $second->exec("INSERT INTO synthetic_posts VALUES ('same-request')");
        } catch (PDOException) {
            $duplicateRejected = true;
        }
        expect($duplicateRejected)->toBeTrue();
        expect($second->exec('UPDATE synthetic_state SET remaining=remaining-1 WHERE id=1 AND remaining>=1'))->toBe(0);
        $second->exec('ROLLBACK');
        expect((int) $first->query('SELECT remaining FROM synthetic_state WHERE id=1')->fetchColumn())->toBe(0)
            ->and((int) $first->query('SELECT COUNT(*) FROM synthetic_posts')->fetchColumn())->toBe(1);
    } finally {
        unset($first, $second);
        @unlink($path);
    }
});
