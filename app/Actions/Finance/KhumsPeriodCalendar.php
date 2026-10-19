<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Dates\PersianDate;
use App\Support\Khums\KhumsYearSchedule;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Persisted future schedule changes; each period retains its own boundaries. */
final class KhumsPeriodCalendar
{
    public function forYear(int $startYear): array
    {
        Gate::authorize('access-app');
        if ($startYear < 1405 || $startYear > 9998) {
            throw new InvalidArgumentException('Khums period year is outside the supported range.');
        }
        $endRule = $this->ruleFor($startYear);
        $startRule = $startYear === 1405 ? $this->baseRule() : $this->ruleFor($startYear - 1);
        $startDate = PersianDate::toGregorian($startYear, $startRule['month'], $startRule['day']);
        $endDate = PersianDate::toGregorian($startYear + 1, $endRule['month'], $endRule['day']);
        $startAt = EffectiveTime::atLocalTime($startDate, $startRule['clock'])->utcInstant;
        $endAt = EffectiveTime::atLocalTime($endDate, $endRule['clock'])->utcInstant;
        if ($endAt <= $startAt) {
            throw new FinanceInputException('Transition period must have a positive duration.');
        }

        return [
            'start_persian' => sprintf('%04d-%02d-%02d', $startYear, $startRule['month'], $startRule['day']),
            'end_exclusive_persian' => sprintf('%04d-%02d-%02d', $startYear + 1, $endRule['month'], $endRule['day']),
            'start_date' => $startDate, 'end_exclusive_date' => $endDate,
            'start_at_utc' => $startAt, 'end_exclusive_at_utc' => $endAt,
            'local_clock' => $endRule['clock'], 'start_local_clock' => $startRule['clock'],
            'timezone' => 'Asia/Tehran', 'boundary_status' => 'confirmed',
            'schedule_version' => $endRule['version'],
        ];
    }

    public function pendingPeriods(string $nowUtc): array
    {
        Gate::authorize('access-app');
        $localYear = (int) explode('-', PersianDate::fromGregorian(
            (new DateTimeImmutable($nowUtc))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d')
        ))[0];
        $periods = [];
        for ($year = 1405; $year < $localYear && $year <= 9998; $year++) {
            $period = $this->forYear($year);
            if (KhumsYearSchedule::dueStatus($period, $nowUtc) === 'due') {
                $periods[] = ['period' => $period, 'due_status' => 'due'];
            }
        }

        return $periods;
    }

    public function previewChange(int $effectiveStartYear, int $month, int $day, string $clock): array
    {
        Gate::authorize('access-app');
        $currentYear = (int) explode('-', PersianDate::fromGregorian(now('Asia/Tehran')->toDateString()))[0];
        if ($effectiveStartYear <= $currentYear || $effectiveStartYear < 1406 || $effectiveStartYear > 9997
            || ($month === 12 && $day === 30)
            || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock)) {
            throw new InvalidArgumentException('Schedule change must start in a future year with an exact clock.');
        }
        $endDate = PersianDate::toGregorian($effectiveStartYear + 1, $month, $day);
        EffectiveTime::atLocalTime($endDate, $clock);
        $old = $this->forYear($effectiveStartYear);
        $start = $this->forYear($effectiveStartYear - 1);
        $newEnd = EffectiveTime::atLocalTime($endDate, $clock)->utcInstant;
        if ($newEnd <= $start['end_exclusive_at_utc']) {
            throw new InvalidArgumentException('Transition period must have a positive duration.');
        }
        $preview = [
            'effective_start_year' => $effectiveStartYear,
            'old_start_at_utc' => $old['start_at_utc'], 'old_end_at_utc' => $old['end_exclusive_at_utc'],
            'new_start_at_utc' => $start['end_exclusive_at_utc'], 'new_end_at_utc' => $newEnd,
            'new_end_persian' => sprintf('%04d-%02d-%02d', $effectiveStartYear + 1, $month, $day),
            'new_clock' => $clock,
            'latest_version' => (int) DB::table('khums_schedule_versions')->max('version'),
        ];
        $preview['hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));

        return $preview;
    }

    public function approveChange(int $effectiveStartYear, int $month, int $day, string $clock, string $seenHash, string $reason, bool $approved): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if (! $approved || mb_strlen($reason) < 10 || mb_strlen($reason) > 240
            || ! preg_match('/^[a-f0-9]{64}$/D', $seenHash)) {
            throw new FinanceInputException('Review the transition and give an explicit reason and approval.');
        }

        return DB::transaction(function () use ($effectiveStartYear, $month, $day, $clock, $seenHash, $reason): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $preview = $this->previewChange($effectiveStartYear, $month, $day, $clock);
            if (! hash_equals($preview['hash'], $seenHash)
                || DB::table('khums_period_closures')->where('start_year', '>=', $effectiveStartYear)->exists()
                || DB::table('khums_schedule_versions')->where('effective_start_year', '>=', $effectiveStartYear)->exists()) {
                throw new FinanceInputException('The schedule changed or an affected year is already closed.');
            }
            $version = max(1, $preview['latest_version']) + 1;
            $id = DB::table('khums_schedule_versions')->insertGetId([
                'version' => $version, 'effective_start_year' => $effectiveStartYear,
                'end_month' => $month, 'end_day' => $day, 'end_local_clock' => $clock,
                'timezone' => 'Asia/Tehran', 'reason' => $reason,
                'approved_by' => auth()->id(), 'approved_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_schedule_changed',
                'facts_hash' => $preview['hash'], 'recorded_at' => now(),
            ]);

            return $id;
        });
    }

    private function ruleFor(int $year): array
    {
        $row = DB::table('khums_schedule_versions')->where('effective_start_year', '<=', $year)
            ->orderByDesc('effective_start_year')->first();

        return $row === null ? $this->baseRule() : [
            'month' => (int) $row->end_month, 'day' => (int) $row->end_day,
            'clock' => $row->end_local_clock, 'version' => (int) $row->version,
        ];
    }

    private function baseRule(): array
    {
        return ['month' => 5, 'day' => 20, 'clock' => KhumsYearSchedule::CONFIRMED_LOCAL_CLOCK, 'version' => 1];
    }
}
