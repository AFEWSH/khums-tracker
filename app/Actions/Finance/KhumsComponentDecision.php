<?php

namespace App\Actions\Finance;

use App\Support\Khums\KhumsYearSchedule;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** An explicit, evidence-backed amount for one account in one financial snapshot. */
final class KhumsComponentDecision
{
    public function confirm(int $startYear, int $accountId, string $seenBaseHash, string $eligibleRial, string $ruleId, array $facts, string $key): int
    {
        Gate::authorize('access-app');
        if ($accountId < 1 || ! preg_match('/^[a-f0-9]{64}$/D', $seenBaseHash)
            || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new InvalidArgumentException('Invalid component decision reference.');
        }
        $eligible = MoneyRial::fromRial($eligibleRial);
        if (str_starts_with($eligible->rial(), '-')) {
            throw new InvalidArgumentException('Eligible amount must be nonnegative.');
        }
        $catalog = app(RuleCatalog::class);
        $rule = $catalog->requireActive($ruleId);
        $required = $rule['required_facts'] ?? [];
        if (! is_array($required) || $required === [] || array_keys($facts) !== $required) {
            throw new FinanceInputException('Every required fact must be supplied explicitly.');
        }
        foreach ($facts as $statement) {
            if (! is_string($statement) || mb_strlen(trim($statement)) < 10 || mb_strlen($statement) > 1000) {
                throw new FinanceInputException('Each religious fact needs a specific written explanation.');
            }
        }

        return DB::transaction(function () use ($startYear, $accountId, $seenBaseHash, $eligible, $ruleId, $facts, $key, $catalog): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $period = app(KhumsPeriodCalendar::class)->forYear($startYear);
            if (KhumsYearSchedule::dueStatus($period, now('UTC')->format('Y-m-d\TH:i:s.u\Z')) !== 'due') {
                throw new FinanceInputException('A component cannot be confirmed before its due boundary.');
            }
            $date = (new DateTimeImmutable($period['end_exclusive_date'], new DateTimeZone('Asia/Tehran')))
                ->modify('-1 day')->format('Y-m-d');
            $simulation = app(KhumsSimulation::class)->asOf($date);
            if (! hash_equals($simulation['input_hash'], $seenBaseHash)) {
                throw new FinanceInputException('Financial data changed; review the component again.');
            }
            $componentId = 'account:'.$accountId;
            $item = collect($simulation['items'])->firstWhere('component_id', $componentId);
            $account = DB::table('accounts')->where('id', $accountId)->first();
            if ($item === null || $account === null || $account->class !== 'asset' || $account->subtype === 'holding'
                || $item['value_rial'] === null
                || BigDecimal::of($eligible->rial())->isGreaterThan($item['value_rial'])) {
                throw new FinanceInputException('This component has no reviewed value or the eligible amount exceeds it.');
            }
            if ($startYear > 1405) {
                $prior = DB::table('khums_period_closures')->where('start_year', $startYear - 1)
                    ->where('active', true)->first();
                if ($prior === null || $prior->dependency_stale) {
                    throw new FinanceInputException('Resolve the preceding year first.');
                }
                $carry = DB::table('khums_basis_carryovers')->where('closure_id', $prior->id)
                    ->where('account_id', $accountId)->first();
                if ($carry !== null) {
                    if ($carry->status !== 'resolved_protected') {
                        throw new FinanceInputException('The preceding protected basis needs review.');
                    }
                    $resolution = DB::table('user_decisions')->where('kind', 'khums_basis_resolved')
                        ->where('target_id', $accountId)->orderByDesc('id')->get()
                        ->first(fn ($row): bool => (json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['closure_id'] ?? null) === (int) $prior->id);
                    if ($resolution === null) {
                        throw new FinanceInputException('The protected basis evidence is missing.');
                    }
                    $resolved = json_decode($resolution->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ((int) DB::table('operations')->max('id') !== ($resolved['last_operation_id'] ?? null)) {
                        throw new FinanceInputException('Later operations need a replayed protected-basis allocation.');
                    }
                    $protected = BigDecimal::of($resolved['protected_basis_rial']);
                    if (BigDecimal::of($eligible->rial())->isGreaterThan(BigDecimal::of($item['value_rial'])->minus($protected))) {
                        throw new FinanceInputException('Eligible amount would count a previously settled basis again.');
                    }
                }
            }
            $payload = [
                'start_year' => $startYear, 'component_id' => $componentId,
                'base_hash' => $seenBaseHash, 'value_rial' => $item['value_rial'],
                'eligible_rial' => $eligible->rial(), 'rule_id' => $ruleId,
                'rule_manifest_hash' => $catalog->manifestHash(), 'facts' => $facts,
                'idempotency_key' => $key,
            ];
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            foreach (DB::table('user_decisions')->where('kind', 'khums_component')->get() as $existing) {
                $old = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($old['idempotency_key'] ?? null) === $key) {
                    if (! hash_equals(hash('sha256', json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $hash)) {
                        throw new FinanceInputException('Decision key was used with different facts.');
                    }

                    return (int) $existing->id;
                }
            }
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'khums_component', 'target_type' => 'account', 'target_id' => $accountId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_component_confirmed',
                'facts_hash' => $hash, 'recorded_at' => now(),
            ]);

            return $id;
        });
    }
}
