<?php

namespace App\Console\Commands;

use App\Support\Backup\SqliteBackup;
use Illuminate\Console\Command;
use Throwable;

final class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify {path}';

    protected $description = 'Decrypt and verify a private SQLite backup';

    public function handle(SqliteBackup $backups): int
    {
        $password = $this->secret('Backup passphrase');
        try {
            $backups->verify((string) $this->argument('path'), (string) $password);
            $this->info('Backup verified.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Verification failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
