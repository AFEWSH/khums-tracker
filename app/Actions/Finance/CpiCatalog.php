<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\DecimalInput;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Stores manually supplied CPI levels as immutable revisions; no figure is fetched or presumed official. */
final class CpiCatalog
{
    public function createSeries(string $coverage, int $baseYear, string $methodologyVersion, string $sourceUrl): int
    {
        Gate::authorize('access-app');
        $methodologyVersion = trim($methodologyVersion);
        $this->validateUrl($sourceUrl);
        if (! in_array($coverage, ['national_all_households', 'urban_households', 'rural_households'], true)
            || $baseYear < 1300 || $baseYear > 1500 || $methodologyVersion === '' || mb_strlen($methodologyVersion) > 80) {
            throw new InvalidArgumentException('Explicit CPI coverage, base year, and methodology are required.');
        }

        return DB::transaction(function () use ($coverage, $baseYear, $methodologyVersion, $sourceUrl): int {
            $this->lockState();
            $existing = DB::table('cpi_series')->where('provider', 'SCI')->where('coverage', $coverage)
                ->where('base_year', $baseYear)->where('methodology_version', $methodologyVersion)->first();
            if ($existing !== null) {
                if ($existing->source_url !== $sourceUrl) {
                    throw new FinanceInputException('Series identity already exists with another source; review it instead of overwriting.');
                }

                return (int) $existing->id;
            }
            $id = DB::table('cpi_series')->insertGetId([
                'provider' => 'SCI', 'coverage' => $coverage, 'base_year' => $baseYear,
                'methodology_version' => $methodologyVersion, 'source_url' => $sourceUrl,
                'verification_status' => 'unverified', 'actor_id' => auth()->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('cpi_series_created', [$id, $coverage, $baseYear, $methodologyVersion, $sourceUrl]);

            return $id;
        });
    }

    public function record(int $seriesId, int $jalaliYear, int $jalaliMonth, string $level, string $sourceUrl, ?string $publicationDate = null, ?string $reason = null): int
    {
        Gate::authorize('access-app');
        $this->validateUrl($sourceUrl);
        if ($jalaliYear < 1300 || $jalaliYear > 1500 || $jalaliMonth < 1 || $jalaliMonth > 12) {
            throw new InvalidArgumentException('Invalid Persian CPI month.');
        }
        $start = PersianDate::toGregorian($jalaliYear, $jalaliMonth, 1);
        $next = $jalaliMonth === 12 ? PersianDate::toGregorian($jalaliYear + 1, 1, 1)
            : PersianDate::toGregorian($jalaliYear, $jalaliMonth + 1, 1);
        if ($publicationDate !== null) {
            EffectiveTime::dateOnly($publicationDate);
        }
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 240) {
            throw new InvalidArgumentException('CPI revision reason is too long.');
        }
        $value = BigDecimal::of(DecimalInput::normalize($level));
        if ($value->isLessThanOrEqualTo(0) || $value->getScale() > 6 || strlen((string) $value->getUnscaledValue()) > 30) {
            throw new InvalidArgumentException('CPI index level must be positive with at most six decimals.');
        }
        $normalized = (string) $value->strippedOfTrailingZeros();

        return DB::transaction(function () use ($seriesId, $jalaliYear, $jalaliMonth, $start, $next, $normalized, $sourceUrl, $publicationDate, $reason): int {
            $this->lockState();
            if (! DB::table('cpi_series')->where('id', $seriesId)->exists()) {
                throw new InvalidArgumentException('CPI series does not exist.');
            }
            $point = DB::table('cpi_points')->where('series_id', $seriesId)
                ->where('jalali_year', $jalaliYear)->where('jalali_month', $jalaliMonth)->first();
            if ($point === null) {
                $pointId = DB::table('cpi_points')->insertGetId([
                    'series_id' => $seriesId, 'jalali_year' => $jalaliYear, 'jalali_month' => $jalaliMonth,
                    'month_start_date' => $start, 'next_month_start_date' => $next,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $revisionNo = 1;
            } else {
                $pointId = (int) $point->id;
                $latest = DB::table('cpi_revisions')->where('point_id', $pointId)->orderByDesc('revision_no')->first();
                if ($latest->value_decimal === $normalized && $latest->source_url === $sourceUrl
                    && $latest->publication_date === $publicationDate) {
                    return (int) $latest->id;
                }
                if ($reason === null || $reason === '') {
                    throw new FinanceInputException('Changing a CPI observation requires a reason.');
                }
                $revisionNo = (int) $latest->revision_no + 1;
            }
            $id = DB::table('cpi_revisions')->insertGetId([
                'point_id' => $pointId, 'revision_no' => $revisionNo, 'value_decimal' => $normalized,
                'source_url' => $sourceUrl, 'publication_date' => $publicationDate,
                'reason' => $reason, 'actor_id' => auth()->id(), 'recorded_at' => now(),
            ]);
            $this->audit('cpi_revision_recorded', [$id, $pointId, $revisionNo, $normalized, $sourceUrl, $publicationDate, $reason]);

            return $id;
        });
    }

    public function series(): array
    {
        Gate::authorize('access-app');

        return DB::table('cpi_series')->orderBy('id')->get()->all();
    }

    public function latestPoints(int $seriesId): array
    {
        Gate::authorize('access-app');

        return DB::table('cpi_points')->where('series_id', $seriesId)->orderBy('jalali_year')->orderBy('jalali_month')
            ->get()->map(function ($point): array {
                $revision = DB::table('cpi_revisions')->where('point_id', $point->id)->orderByDesc('revision_no')->first();

                return ['year' => (int) $point->jalali_year, 'month' => (int) $point->jalali_month,
                    'level' => $revision->value_decimal, 'revision' => (int) $revision->revision_no,
                    'revision_id' => (int) $revision->id, 'source_url' => $revision->source_url];
            })->all();
    }

    private function validateUrl(string $url): void
    {
        if (strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('A direct HTTPS source URL is required.');
        }
    }

    private function lockState(): void
    {
        DB::table('financial_state')->where('id', 1)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }

    private function audit(string $action, array $facts): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => auth()->id(), 'action' => $action,
            'facts_hash' => hash('sha256', json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'recorded_at' => now(),
        ]);
    }
}
