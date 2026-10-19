<?php

namespace App\Actions\Finance;

use App\Support\Backup\SqliteBackup;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use SensitiveParameter;

/** Checks that an encrypted, restorable private backup contains the reviewed input. */
class KhumsBackupVerifier
{
    /** @return array{path:string,password:string} */
    public function createForReview(int $reviewId): array
    {
        $source = config('database.connections.sqlite.database');
        if (! is_string($source) || $source === ':memory:' || ! is_file($source)) {
            throw new FinanceInputException('An on-disk SQLite database is required for a closure backup.');
        }
        $root = storage_path('app/private');
        $name = 'before-khums-review-'.$reviewId.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $path = $root.'/backups/'.$name.'.khfb';
        $keyPath = $root.'/recovery-'.$name.'.key';
        $password = bin2hex(random_bytes(32));
        $handle = fopen($keyPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create a private recovery key.');
        }
        chmod($keyPath, 0600);
        try {
            fwrite($handle, $password);
        } finally {
            fclose($handle);
        }
        app(SqliteBackup::class)->create($source, $path, $password);
        $this->verifyReview($reviewId, $path, $password);

        return ['path' => $path, 'password' => $password];
    }

    public function verifyReview(int $reviewId, string $path, #[SensitiveParameter] string $password): string
    {
        $directory = realpath(storage_path('app/private/backups'));
        $real = realpath($path);
        if ($directory === false || $real === false || dirname($real) !== $directory || ! str_ends_with($real, '.khfb')) {
            throw new FinanceInputException('Select a private encrypted backup.');
        }
        $backup = app(SqliteBackup::class);
        $result = $backup->verify($real, $password);
        if ($result['schema'] < 7) {
            throw new FinanceInputException('Backup schema predates the khums lifecycle.');
        }
        $temporary = sys_get_temp_dir().'/khums-closure-'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Cannot create a private restore directory.');
        }
        try {
            $target = $temporary.'/restored.sqlite';
            $backup->restoreToEmpty($real, $target, $password);
            $db = new PDO('sqlite:'.$target);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $statement = $db->prepare('SELECT snapshot_hash, input_hash FROM khums_period_reviews WHERE id = ?');
            $statement->execute([$reviewId]);
            $saved = $statement->fetch(PDO::FETCH_ASSOC);
            if ($saved === false) {
                throw new FinanceInputException('Backup does not contain this reviewed period.');
            }
            $current = DB::table('khums_period_reviews')->where('id', $reviewId)->first();
            if ($current === null || ! hash_equals($current->snapshot_hash, $saved['snapshot_hash'])
                || ! hash_equals($current->input_hash, $saved['input_hash'])) {
                throw new FinanceInputException('Backup contains a different review revision.');
            }

            return $result['sha256'];
        } finally {
            unset($db);
            @unlink($temporary.'/restored.sqlite');
            @rmdir($temporary);
        }
    }
}
