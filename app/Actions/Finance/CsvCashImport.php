<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\MoneyRial;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** A deliberately small CSV contract for cash operations; posting uses the same Action as manual entry. */
final class CsvCashImport
{
    public const COLUMNS = ['reference', 'type', 'date', 'amount', 'source', 'target', 'category', 'reason', 'after_opening'];

    private const TYPES = ['income', 'accrue_income', 'expense', 'transfer', 'lend', 'collect', 'borrow', 'repay'];

    /** @return array{rows:array, revision:int, digest:string, warnings:array, mapping:array, unit:string, calendar:string} */
    public function preview(string $bytes, array $mapping, string $unit, string $calendar): array
    {
        Gate::authorize('access-app');
        if (! in_array($unit, ['toman', 'rial'], true) || ! in_array($calendar, ['persian', 'gregorian'], true)) {
            throw new InvalidArgumentException('Choose the amount unit and date calendar explicitly.');
        }
        if (strlen($bytes) < 1 || strlen($bytes) > 262144 || str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidArgumentException('CSV must be UTF-8, nonempty, and at most 256 KiB.');
        }
        if (DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') === null) {
            throw new FinanceInputException('Confirm opening before importing transactions.');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new InvalidArgumentException('Cannot read CSV.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (! is_array($headers) || count($headers) < 4 || count($headers) > 20) {
                throw new InvalidArgumentException('CSV header is missing or too wide.');
            }
            $headers = array_map(fn ($value): string => trim((string) $value), $headers);
            if (count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
                throw new InvalidArgumentException('CSV headers must be unique and nonempty.');
            }
            $indexes = [];
            foreach (self::COLUMNS as $column) {
                $name = trim((string) ($mapping[$column] ?? ''));
                if ($name === '' && in_array($column, ['reference', 'type', 'date', 'amount'], true)) {
                    throw new InvalidArgumentException('Reference, type, date and amount mappings are required.');
                }
                if ($name !== '') {
                    $index = array_search($name, $headers, true);
                    if ($index === false || in_array($index, $indexes, true)) {
                        throw new InvalidArgumentException('Mapped CSV header is missing or used twice: '.$name);
                    }
                    $indexes[$column] = $index;
                }
            }
            $rows = [];
            $warnings = [];
            $seenReferences = [];
            $seenFingerprints = [];
            $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= 200 || count($cells) !== count($headers)) {
                    throw new InvalidArgumentException('CSV row '.$line.' is too wide, incomplete, or exceeds 200 rows.');
                }
                foreach ($cells as $cell) {
                    if (! is_string($cell) || mb_strlen($cell) > 240 || preg_match('/[\x00-\x1F]/', $cell)
                        || preg_match('/^\s*[=+\-@]/u', $cell)) {
                        throw new InvalidArgumentException('CSV row '.$line.' contains an unsafe or oversized cell.');
                    }
                }
                $value = fn (string $column): string => isset($indexes[$column]) ? trim($cells[$indexes[$column]]) : '';
                try {
                    $reference = $value('reference');
                    if ($reference === '' || mb_strlen($reference) > 120) {
                        throw new InvalidArgumentException('A stable reference is required.');
                    }
                    $type = $value('type');
                    if (! in_array($type, self::TYPES, true)) {
                        throw new InvalidArgumentException('Unsupported operation type.');
                    }
                    $date = $calendar === 'persian' ? PersianDate::parseInput($value('date')) : $value('date');
                    EffectiveTime::dateOnly($date);
                    if ($date < OpeningSetup::DATE) {
                        throw new InvalidArgumentException('Operation predates opening.');
                    }
                    $money = $unit === 'rial' ? MoneyRial::fromRial($value('amount')) : MoneyRial::fromToman($value('amount'));
                    if ($money->rial() === '0' || $money->rial()[0] === '-') {
                        throw new InvalidArgumentException('Amount must be positive.');
                    }
                    $after = match ($value('after_opening')) {
                        '1', 'true', 'بله' => true,
                        '', '0', 'false', 'خیر' => false,
                        default => throw new InvalidArgumentException('Invalid after-opening flag.'),
                    };
                    if ($date === OpeningSetup::DATE && ! $after) {
                        throw new InvalidArgumentException('Confirm that opening-day operation followed opening.');
                    }
                    $sourceId = $this->accountId($value('source'));
                    $targetId = $this->accountId($value('target'));
                    $categoryId = $this->categoryId($value('category'), $type);
                    $reason = $value('reason');
                    $key = 'csv-'.substr(hash('sha256', $reference), 0, 60);
                    $input = ['type' => $type, 'date' => $date, 'amount_toman' => $money->toman(),
                        'source_id' => $sourceId, 'target_id' => $targetId, 'category_id' => $categoryId,
                        'reason' => $reason, 'after_opening' => $after, 'idempotency_key' => $key];
                    $fingerprint = hash('sha256', json_encode([$type, $date, $money->rial(), $sourceId,
                        $targetId, $categoryId, $reason, $after], JSON_THROW_ON_ERROR));
                    if (isset($seenReferences[$key])) {
                        throw new InvalidArgumentException('Duplicate reference at rows '.$seenReferences[$key].' and '.$line.'.');
                    }
                    if (isset($seenFingerprints[$fingerprint])) {
                        $warnings[] = 'سطرهای '.$seenFingerprints[$fingerprint].' و '.$line.' محتوای مشابه دارند؛ مرجع‌هایشان متفاوت است.';
                    }
                    $seenReferences[$key] = $line;
                    $seenFingerprints[$fingerprint] = $line;
                    if (DB::table('operations')->where('idempotency_key', $key)->exists()) {
                        $warnings[] = 'مرجع سطر '.$line.' قبلاً ثبت شده است؛ ثبت دوباره فقط در صورت یکسان‌بودن محتوا بی‌اثر خواهد بود.';
                    }
                    $rows[] = ['line' => $line, 'reference' => $reference, 'input' => $input, 'fingerprint' => $fingerprint];
                } catch (InvalidArgumentException|MathException $error) {
                    throw new InvalidArgumentException('CSV row '.$line.': '.$error->getMessage(), previous: $error);
                }
            }
            if ($rows === []) {
                throw new InvalidArgumentException('CSV has no operations.');
            }
            $revision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            $digest = hash('sha256', json_encode([$rows, $revision], JSON_THROW_ON_ERROR));

            return compact('rows', 'revision', 'digest', 'warnings', 'mapping', 'unit', 'calendar');
        } finally {
            fclose($stream);
        }
    }

    /** @return array{posted:int, repeated:int} */
    public function commit(array $plan, bool $acknowledgeWarnings): array
    {
        Gate::authorize('access-app');
        if (($plan['warnings'] ?? []) !== [] && ! $acknowledgeWarnings) {
            throw new FinanceInputException('Review and acknowledge duplicate warnings before importing.');
        }
        if (! hash_equals((string) ($plan['digest'] ?? ''), hash('sha256', json_encode([$plan['rows'] ?? [], $plan['revision'] ?? null], JSON_THROW_ON_ERROR)))) {
            throw new FinanceInputException('Import preview changed; upload it again.');
        }

        return DB::transaction(function () use ($plan): array {
            if (DB::table('financial_state')->where('id', 1)->where('revision', $plan['revision'])
                ->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]) !== 1) {
                throw new FinanceInputException('Financial data changed after preview; review this CSV again.');
            }
            $posted = 0;
            $repeated = 0;
            foreach ($plan['rows'] as $row) {
                $key = $row['input']['idempotency_key'];
                $exists = DB::table('operations')->where('idempotency_key', $key)->exists();
                try {
                    app(BackdatedPosting::class)->cash($row['input']);
                } catch (\Throwable $error) {
                    throw new FinanceInputException('CSV row '.$row['line'].' ('.$row['reference'].'): '.$error->getMessage(), previous: $error);
                }
                $exists ? $repeated++ : $posted++;
            }

            return compact('posted', 'repeated');
        });
    }

    private function accountId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $ids = DB::table('accounts')->where('name', $name)->whereNull('archived_at')->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            throw new InvalidArgumentException('Account name is missing or ambiguous: '.$name);
        }

        return (int) $ids[0];
    }

    private function categoryId(string $name, string $type): ?int
    {
        if ($name === '') {
            return null;
        }
        $kind = $type === 'accrue_income' ? 'income' : $type;
        if (! in_array($kind, ['income', 'expense'], true)) {
            throw new InvalidArgumentException('Category is not allowed for this operation.');
        }
        $ids = DB::table('categories')->where('name', $name)->where('kind', $kind)->whereNull('archived_at')->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            throw new InvalidArgumentException('Category name is missing or ambiguous: '.$name);
        }

        return (int) $ids[0];
    }
}
