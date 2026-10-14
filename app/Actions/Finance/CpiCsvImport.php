<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\DecimalInput;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

final class CpiCsvImport
{
    public function preview(string $bytes, int $seriesId): array
    {
        Gate::authorize('access-app');
        if (strlen($bytes) > 262144 || str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new InvalidArgumentException('CSV must be UTF-8 and at most 256 KiB.');
        }
        if (! DB::table('cpi_series')->where('id', $seriesId)->exists()) {
            throw new InvalidArgumentException('Select an existing CPI series.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $bytes));
        rewind($stream);
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== ['year', 'month', 'level', 'source_url', 'publication_date', 'reason']) {
                throw new InvalidArgumentException('CSV header must be year,month,level,source_url,publication_date,reason.');
            }
            $rows = [];
            $seen = [];
            $line = 1;
            while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if ($line > 201 || count($fields) !== 6) {
                    throw new InvalidArgumentException('CSV row limit or column count is invalid at line '.$line.'.');
                }
                if ($fields === [null, null, null, null, null, null]) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (preg_match('/^\s*[=+@]/u', (string) $field)) {
                        throw new InvalidArgumentException('Unsafe formula-like CSV value at line '.$line.'.');
                    }
                }
                $year = $this->smallInteger((string) $fields[0]);
                $month = $this->smallInteger((string) $fields[1]);
                $key = $year.'-'.$month;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('Duplicate CPI month in CSV at line '.$line.'.');
                }
                $seen[$key] = true;
                if ($year < 1300 || $year > 1500 || $month < 1 || $month > 12) {
                    throw new InvalidArgumentException('Invalid Persian CPI month at line '.$line.'.');
                }
                PersianDate::toGregorian($year, $month, 1);
                $decimal = BigDecimal::of(DecimalInput::normalize((string) $fields[2]));
                if ($decimal->isLessThanOrEqualTo(0) || $decimal->getScale() > 6 || strlen((string) $decimal->getUnscaledValue()) > 30) {
                    throw new InvalidArgumentException('Invalid CPI level at line '.$line.'.');
                }
                $level = (string) $decimal->strippedOfTrailingZeros();
                $source = trim((string) $fields[3]);
                if (strlen($source) > 500 || ! filter_var($source, FILTER_VALIDATE_URL)
                    || parse_url($source, PHP_URL_SCHEME) !== 'https') {
                    throw new InvalidArgumentException('A direct HTTPS source URL is required at line '.$line.'.');
                }
                $publicationDate = trim((string) $fields[4]) ?: null;
                if ($publicationDate !== null) {
                    EffectiveTime::dateOnly($publicationDate);
                }
                $reason = trim((string) $fields[5]) ?: null;
                if ($reason !== null && mb_strlen($reason) > 240) {
                    throw new InvalidArgumentException('Revision reason is too long at line '.$line.'.');
                }
                $rows[] = [
                    'line' => $line, 'year' => $year, 'month' => $month, 'level' => $level,
                    'source_url' => $source,
                    'publication_date' => $publicationDate,
                    'reason' => $reason,
                ];
            }
            if ($rows === []) {
                throw new InvalidArgumentException('CSV has no CPI observations.');
            }
        } finally {
            fclose($stream);
        }
        $revision = (int) DB::table('financial_state')->where('id', 1)->value('revision');

        return [
            'series_id' => $seriesId, 'rows' => $rows, 'revision' => $revision,
            'digest' => hash('sha256', json_encode([$seriesId, $rows, $revision], JSON_THROW_ON_ERROR)),
        ];
    }

    public function commit(array $plan): int
    {
        Gate::authorize('access-app');
        if (! isset($plan['series_id'], $plan['rows'], $plan['revision'], $plan['digest'])
            || ! hash_equals(hash('sha256', json_encode([$plan['series_id'], $plan['rows'], $plan['revision']], JSON_THROW_ON_ERROR)), $plan['digest'])) {
            throw new InvalidArgumentException('Invalid CPI preview.');
        }

        return DB::transaction(function () use ($plan): int {
            $current = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            if ($current !== $plan['revision']) {
                throw new RuntimeException('CPI or financial data changed after preview; preview the file again.');
            }
            $catalog = app(CpiCatalog::class);
            foreach ($plan['rows'] as $row) {
                try {
                    $catalog->record((int) $plan['series_id'], $row['year'], $row['month'], $row['level'],
                        $row['source_url'], $row['publication_date'], $row['reason']);
                } catch (\Throwable $error) {
                    throw new RuntimeException('CPI CSV line '.$row['line'].': '.$error->getMessage(), previous: $error);
                }
            }

            return count($plan['rows']);
        });
    }

    private function smallInteger(string $value): int
    {
        $normalized = DecimalInput::normalize($value);
        if (! preg_match('/^[0-9]{1,4}$/D', $normalized)) {
            throw new InvalidArgumentException('CSV year or month must be an integer.');
        }

        return (int) $normalized;
    }
}
