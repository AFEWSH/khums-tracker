<?php

namespace App\Support\Backup;

use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class SqliteBackup
{
    private const MAGIC = "KHFB1\n";

    private const CHUNK = 65536;

    private const SCHEMAS = [
        1 => ['2026_10_13_000000_create_identity_tables'],
        2 => ['2026_10_13_000000_create_identity_tables', '2026_10_14_000000_create_cash_finance_core'],
        3 => ['2026_10_13_000000_create_identity_tables', '2026_10_14_000000_create_cash_finance_core', '2026_10_14_000001_create_quantity_assets'],
        4 => ['2026_10_13_000000_create_identity_tables', '2026_10_14_000000_create_cash_finance_core', '2026_10_14_000001_create_quantity_assets', '2026_10_14_000002_create_replay_and_facts'],
    ];

    public function create(string $source, string $target, #[SensitiveParameter] string $password): void
    {
        $this->checkPassword($password);
        if (is_link($source)) {
            throw new RuntimeException('Source database must be a regular file.');
        }
        $source = realpath($source) ?: throw new RuntimeException('Source database is missing.');
        if (! is_file($source)) {
            throw new RuntimeException('Source database must be a regular file.');
        }

        $snapshot = tempnam(sys_get_temp_dir(), 'khfb-snapshot-');
        if ($snapshot === false) {
            throw new RuntimeException('Cannot create a private snapshot.');
        }
        chmod($snapshot, 0600);

        try {
            $db = new PDO('sqlite:'.$source);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('VACUUM INTO '.$db->quote($snapshot));
            $schema = $this->inspect($snapshot);
            $hash = hash_file('sha256', $snapshot);
            if ($hash === false) {
                throw new RuntimeException('Cannot hash snapshot.');
            }
            $this->encrypt($snapshot, $target, $password, $hash, $schema);
        } finally {
            unset($db);
            @unlink($snapshot);
        }
    }

    /** @return array{sha256: string, schema: int} */
    public function verify(string $backup, #[SensitiveParameter] string $password): array
    {
        return $this->decryptAndInspect($backup, $password, null);
    }

    /** Restore only to a new, absent path. Never replaces an active database. */
    public function restoreToEmpty(string $backup, string $target, #[SensitiveParameter] string $password): void
    {
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Restore destination must not exist.');
        }
        $directory = dirname($target);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Restore directory must exist and be writable.');
        }

        $this->decryptAndInspect($backup, $password, $target);
    }

    /** @return array{sha256: string, schema: int} */
    private function decryptAndInspect(string $backup, string $password, ?string $target): array
    {
        $this->checkPassword($password);
        if (is_link($backup)) {
            throw new RuntimeException('Backup must be a regular file.');
        }
        $path = realpath($backup) ?: throw new RuntimeException('Backup is missing.');
        if (! is_file($path)) {
            throw new RuntimeException('Backup must be a regular file.');
        }

        $directory = $target === null ? sys_get_temp_dir() : dirname($target);
        $temp = tempnam($directory, '.khfb-restore-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create a private restore file.');
        }
        chmod($temp, 0600);
        try {
            $in = fopen($path, 'rb');
            $out = fopen($temp, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException('Cannot open backup or restore file.');
            }
            try {
                $header = $this->readExactly($in, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
                if (! str_starts_with($header, self::MAGIC)) {
                    throw new RuntimeException('Unsupported backup format.');
                }
                $salt = substr($header, strlen(self::MAGIC), SODIUM_CRYPTO_PWHASH_SALTBYTES);
                $streamHeader = substr($header, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES);
                $key = $this->derive($password, $salt);
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
                sodium_memzero($key);
                $metadata = null;
                $ended = false;
                while (! feof($in)) {
                    $lengthBytes = fread($in, 4);
                    if ($lengthBytes === '') {
                        break;
                    }
                    if ($lengthBytes === false || strlen($lengthBytes) !== 4) {
                        throw new RuntimeException('Truncated backup frame.');
                    }
                    $length = unpack('N', $lengthBytes)[1];
                    if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + 4096) {
                        throw new RuntimeException('Invalid backup frame length.');
                    }
                    $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->readExactly($in, $length));
                    if ($opened === false) {
                        throw new RuntimeException('Wrong password or damaged backup.');
                    }
                    [$plain, $tag] = $opened;
                    if ($metadata === null) {
                        $metadata = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
                        if (! is_array($metadata) || ! isset(self::SCHEMAS[$metadata['schema'] ?? 0]) || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256'] ?? '')) {
                            throw new RuntimeException('Unsupported backup schema.');
                        }
                    } elseif ($plain !== '') {
                        $this->write($out, $plain);
                    }
                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        $ended = true;
                        if (fread($in, 1) !== '') {
                            throw new RuntimeException('Trailing backup data.');
                        }
                        break;
                    }
                }
                if (! $ended || $metadata === null) {
                    throw new RuntimeException('Incomplete backup.');
                }
            } finally {
                fclose($in);
                fclose($out);
            }

            $hash = hash_file('sha256', $temp);
            if ($hash === false || ! hash_equals($metadata['sha256'], $hash)) {
                throw new RuntimeException('Backup checksum mismatch.');
            }
            if ($this->inspect($temp) !== $metadata['schema']) {
                throw new RuntimeException('Backup schema does not match its manifest.');
            }
            if ($target !== null && ! link($temp, $target)) {
                throw new RuntimeException('Restore destination already exists or cannot be created.');
            }

            return ['sha256' => $hash, 'schema' => $metadata['schema']];
        } finally {
            @unlink($temp);
        }
    }

    private function encrypt(string $snapshot, string $target, string $password, string $hash, int $schema): void
    {
        if (! is_dir(dirname($target)) || ! is_writable(dirname($target))) {
            throw new RuntimeException('Backup directory must exist and be writable.');
        }
        $in = fopen($snapshot, 'rb');
        $out = fopen($target, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            throw new RuntimeException('Backup destination must be a new file.');
        }
        chmod($target, 0600);
        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->derive($password, $salt);
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            $this->write($out, self::MAGIC.$salt.$streamHeader);
            $this->writeFrame($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, json_encode(['schema' => $schema, 'sha256' => $hash], JSON_THROW_ON_ERROR)));
            while (! feof($in)) {
                $plain = fread($in, self::CHUNK);
                if ($plain === false) {
                    throw new RuntimeException('Cannot read snapshot.');
                }
                if ($plain !== '') {
                    $this->writeFrame($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain));
                }
            }
            $this->writeFrame($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, '', tag: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
            fflush($out);
        } catch (Throwable $error) {
            @unlink($target);
            throw $error;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function inspect(string $path): int
    {
        $db = new PDO('sqlite:'.$path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $db->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('Backup database integrity failed.');
        }
        $migrations = $db->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::SCHEMAS as $version => $expected) {
            if ($migrations === $expected) {
                return $version;
            }
        }

        throw new RuntimeException('Unsupported database schema.');
    }

    private function derive(#[SensitiveParameter] string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }

    private function checkPassword(#[SensitiveParameter] string $password): void
    {
        if (strlen($password) < 16) {
            throw new RuntimeException('Backup passphrase must have at least 16 bytes.');
        }
    }

    /** @param resource $stream */
    private function writeFrame($stream, string $cipher): void
    {
        $this->write($stream, pack('N', strlen($cipher)).$cipher);
    }

    /** @param resource $stream */
    private function write($stream, string $content): void
    {
        $written = 0;
        while ($written < strlen($content)) {
            $part = fwrite($stream, substr($content, $written));
            if ($part === false || $part === 0) {
                throw new RuntimeException('Cannot write backup.');
            }
            $written += $part;
        }
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $part = fread($stream, $length - strlen($result));
            if ($part === false || $part === '') {
                throw new RuntimeException('Truncated backup.');
            }
            $result .= $part;
        }

        return $result;
    }
}
