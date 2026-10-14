<?php

namespace App\Console\Commands;

use App\Support\Backup\SqliteBackup;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class CreateBackupCommand extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'Create an encrypted, consistent SQLite backup';

    public function handle(SqliteBackup $backups): int
    {
        $password = $this->secret('Backup passphrase (at least 16 bytes)');
        $confirmation = $this->secret('Repeat backup passphrase');
        if (! is_string($password) || ! hash_equals($password, (string) $confirmation)) {
            $this->error('Passphrases do not match.');

            return self::FAILURE;
        }

        $source = config('database.connections.sqlite.database');
        if (config('database.default') !== 'sqlite' || ! is_string($source) || $source === ':memory:') {
            $this->error('A file-backed SQLite connection is required.');

            return self::FAILURE;
        }

        $directory = storage_path('app/private/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create private backup directory.');
        }
        chmod($directory, 0700);
        $target = $directory.'/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.khfb';

        try {
            $backups->create($source, $target, $password);
            $backups->verify($target, $password);
            $this->info('Verified backup created: '.$target);

            return self::SUCCESS;
        } catch (Throwable $error) {
            @unlink($target);
            $this->error('Backup failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
