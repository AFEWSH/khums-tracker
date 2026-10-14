<?php

namespace App\Console\Commands;

use App\Support\Backup\SqliteBackup;
use Illuminate\Console\Command;
use Throwable;

final class RestoreBackupToEmptyCommand extends Command
{
    protected $signature = 'backup:restore-empty {path}';

    protected $description = 'Restore a backup to a new private database file without changing the active database';

    public function handle(SqliteBackup $backups): int
    {
        $password = $this->secret('Backup passphrase');
        $directory = storage_path('app/private/restores');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('Cannot create private restore directory.');

            return self::FAILURE;
        }
        chmod($directory, 0700);
        $target = $directory.'/restore-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';

        try {
            $backups->restoreToEmpty((string) $this->argument('path'), $target, (string) $password);
            $this->info('Verified restore in a separate private file: '.$target);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Restore failed; active database was not changed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
