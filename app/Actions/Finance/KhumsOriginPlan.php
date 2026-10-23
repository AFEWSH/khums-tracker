<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Khums\ProtectedOriginReplay;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\AssetQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** A versioned explicit origin plan, stored without changing any posted financial fact. */
final class KhumsOriginPlan
{
    public function factLabels(): array
    {
        $labels = [];
        foreach (['K01', 'K12'] as $id) {
            $required = app(RuleCatalog::class)->requireActive($id)['required_facts'] ?? null;
            if (! is_array($required) || $required === []) {
                throw new \LogicException('Origin rules must declare their required facts.');
            }
            foreach ($required as $fact) {
                if (! is_string($fact) || trim($fact) === '') {
                    throw new \LogicException('Origin fact labels must be nonempty text.');
                }
                $labels[] = $id.': '.$fact;
            }
        }

        return [...$labels, 'تعیین واقعی lot خروجی', 'همگنی پایه در مقدار هر lot و تخصیص عوض'];
    }

    public function quantityAtoms(int $lotId, string $quantity): string
    {
        Gate::authorize('access-app');
        $scale = DB::table('lots')->join('instruments', 'instruments.id', '=', 'lots.instrument_id')
            ->where('lots.id', $lotId)->value('quantity_scale');
        if ($scale === null) {
            throw new FinanceInputException('The selected physical lot does not exist.');
        }
        try {
            return AssetQuantity::fromDecimal($quantity, (int) $scale)->atoms();
        } catch (MathException $error) {
            throw new FinanceInputException('Physical quantity exceeds the instrument precision.', previous: $error);
        }
    }

    public function preview(string $date): array
    {
        Gate::authorize('access-app');

        return DB::transaction(function () use ($date): array {
            $source = $this->source($date);
            $source['existing_decision_id'] = null;
            foreach (DB::table('user_decisions')->where('kind', 'khums_origin_plan')->orderByDesc('id')->get() as $decision) {
                $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($payload['date'] === $date) {
                    $source['existing_decision_id'] = $payload['source_hash'] === $source['hash'] ? (int) $decision->id : null;
                    if ($source['existing_decision_id'] !== null && $this->hasAudit($decision->payload)) {
                        $display = $payload['plan'];
                        $scales = [];
                        foreach ($source['operations'] as $operation) {
                            foreach ($operation['new_lots'] as $lot) {
                                $scales[$lot['lot_id']] = $lot['quantity_scale'];
                            }
                        }
                        foreach ($display as &$choice) {
                            foreach ($choice['lots'] ?? [] as $lotId => $lot) {
                                $choice['lots'][$lotId]['atoms'] = (string) BigDecimal::ofUnscaledValue($lot['atoms'], $scales[$lotId])->strippedOfTrailingZeros();
                            }
                        }
                        unset($choice);
                        $source['existing_display_plan'] = $display;
                        $source['existing_facts'] = $payload['facts'];
                    }
                    break;
                }
            }

            return $source;
        });
    }

    public function paymentSource(string $date, int $accountId): ?array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        $candidates = [];
        foreach (DB::table('user_decisions')->where('kind', 'khums_origin_plan')->orderByDesc('id')->get() as $decision) {
            $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($payload['date'] <= $date) {
                $candidates[] = ['date' => $payload['date'], 'id' => (int) $decision->id];
            }
        }
        usort($candidates, fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
        if ($candidates === []) {
            return null;
        }
        $trace = $this->read($candidates[0]['date']);
        $position = collect($trace['cash_positions'] ?? [])->firstWhere('account_id', $accountId);

        return ($position['status'] ?? null) === 'traced' ? [
            'decision_id' => $trace['origin_plan_decision_id'],
            'protected_basis_exact_rial' => $position['protected_basis_exact_rial'],
        ] : null;
    }

    public function confirm(string $date, string $seenHash, array $plan, array $facts, bool $approved, ?int $replacesDecisionId = null, string $reason = ''): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if (! $approved || ! preg_match('/^[a-f0-9]{64}$/D', $seenHash) || array_keys($facts) !== $this->factLabels()) {
            throw new FinanceInputException('Explicit origin approval, active rules and all facts are required.');
        }
        if ($replacesDecisionId !== null && ($replacesDecisionId < 1 || mb_strlen($reason) < 10 || mb_strlen($reason) > 240)) {
            throw new FinanceInputException('An origin revision requires the previous decision and a specific reason.');
        }
        foreach ($facts as $fact) {
            if (! is_string($fact) || mb_strlen(trim($fact)) < 10 || mb_strlen($fact) > 1000) {
                throw new FinanceInputException('Each origin fact needs a specific written explanation.');
            }
        }

        return DB::transaction(function () use ($date, $seenHash, $plan, $facts, $replacesDecisionId, $reason): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $source = $this->source($date);
            if (! hash_equals($source['hash'], $seenHash) || $source['issues'] !== []) {
                throw new FinanceInputException('Origin history changed or contains unsupported evidence; review it again.');
            }
            ProtectedOriginReplay::evaluate($source['operations'], $plan, $source['anchors']);
            $payload = ['date' => $date, 'source_hash' => $seenHash, 'plan' => $plan, 'facts' => $facts,
                'rule_manifest_hash' => app(RuleCatalog::class)->manifestHash(), 'policy_version' => ProtectedOriginReplay::VERSION];
            if ($replacesDecisionId !== null) {
                $payload['replaces_decision_id'] = $replacesDecisionId;
                $payload['reason'] = $reason;
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $replacementFound = false;
            foreach (DB::table('user_decisions')->where('kind', 'khums_origin_plan')->orderByDesc('id')->get() as $existing) {
                if ($existing->payload === $encoded) {
                    if (! $this->hasAudit($encoded)) {
                        throw new FinanceInputException('The existing origin decision has no matching audit proof.');
                    }

                    return (int) $existing->id;
                }
                $old = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($old['date'] === $date && $old['source_hash'] === $seenHash) {
                    if ($replacesDecisionId !== (int) $existing->id) {
                        throw new FinanceInputException('This history already has a different approved origin plan.');
                    }
                    $replacementFound = true;
                    break;
                }
            }
            if ($replacesDecisionId !== null && ! $replacementFound) {
                throw new FinanceInputException('The origin decision being revised is not current.');
            }
            $id = DB::table('user_decisions')->insertGetId([
                'kind' => 'khums_origin_plan', 'target_type' => 'financial_state', 'target_id' => 1,
                'payload' => $encoded, 'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $affected = [];
            foreach (DB::table('khums_period_closures')->where('active', true)->orderBy('id')->get(['id']) as $closure) {
                $snapshot = app(KhumsPeriodClosure::class)->read((int) $closure->id);
                if ($snapshot['report']['as_of_date'] === $date) {
                    $affected[] = (int) $closure->id;
                    $affected = [...$affected, ...app(KhumsYearDependencies::class)->affectedClosureIds((int) $closure->id)];
                }
            }
            $affected = array_values(array_unique($affected));
            if ($affected !== []) {
                DB::table('khums_period_closures')->whereIn('id', $affected)->update(['dependency_stale' => true]);
                DB::table('user_decisions')->insert([
                    'kind' => 'khums_origin_plan_impact', 'target_type' => 'origin_plan', 'target_id' => $id,
                    'payload' => json_encode(['stale_closure_ids' => $affected], JSON_THROW_ON_ERROR),
                    'actor_id' => auth()->id(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'action' => 'khums_origin_plan_confirmed',
                'facts_hash' => hash('sha256', $encoded), 'recorded_at' => now()]);

            return $id;
        });
    }

    /** A stale plan blocks its covered history instead of resurrecting an earlier implicit allocation. */
    public function read(string $date): ?array
    {
        Gate::authorize('access-app');

        return DB::transaction(fn (): ?array => $this->buildRead($date));
    }

    private function buildRead(string $date): ?array
    {
        $decision = DB::table('user_decisions')->where('kind', 'khums_origin_plan')->orderByDesc('id')->get()
            ->first(fn ($row): bool => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['date'] === $date);
        if ($decision === null) {
            return null;
        }
        $source = $this->source($date);
        $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
        $valid = $source['issues'] === [] && $this->hasAudit($decision->payload) && $payload['source_hash'] === $source['hash']
            && ($payload['policy_version'] ?? null) === ProtectedOriginReplay::VERSION
            && $payload['rule_manifest_hash'] === app(RuleCatalog::class)->manifestHash();
        try {
            $this->factLabels();
            $result = $valid ? ProtectedOriginReplay::evaluate($source['operations'], $payload['plan'], $source['anchors']) : null;
        } catch (\LogicException|InvalidArgumentException $error) {
            $result = null;
        }
        if ($result === null) {
            $result = ['holdings' => [], 'cash_positions' => [], 'sales' => []];
            foreach ($source['accounts'] as $account) {
                $position = ['account_id' => $account['id'], 'status' => 'needs_review',
                    'protected_basis_exact_rial' => null, 'issues' => ['origin_plan_requires_review']];
                if ($account['subtype'] === 'holding') {
                    $result['holdings'][] = $position + ['lot_ids' => [], 'remaining_atoms' => null];
                } else {
                    $result['cash_positions'][] = $position + ['sale_operation_ids' => []];
                }
            }
        }
        $result['origin_plan_decision_id'] = (int) $decision->id;
        $result['financial_revision'] = $source['revision'];
        $result['raw_hash'] = $source['hash'];
        $result['hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function source(string $date): array
    {
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || $date > now('Asia/Tehran')->toDateString()) {
            throw new FinanceInputException('Origin date must be within the known history.');
        }
        $accounts = DB::table('accounts')->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash', 'holding'])
            ->orderBy('id')->get(['id', 'subtype', 'name'])->map(fn ($row): array => (array) $row)->all();
        $cashIds = array_column(array_filter($accounts, fn ($a) => $a['subtype'] !== 'holding'), 'id');
        $scales = DB::table('asset_accounts')->join('instruments', 'instruments.id', '=', 'asset_accounts.instrument_id')
            ->pluck('instruments.quantity_scale', 'asset_accounts.account_id')->all();
        $ops = DB::table('operations')->where('effective_date', '<=', $date)
            ->orderBy('effective_date')->orderBy('sequence')->orderBy('id')->get();
        $neutral = $this->neutralReversals($ops->all());
        $lots = DB::table('lots')->whereIn('acquisition_operation_id', $ops->pluck('id'))->orderBy('id')->get();
        $origins = DB::table('asset_origin_events')->where('kind', 'opening')->whereIn('lot_id', $lots->pluck('id'))->orderBy('id')->get();
        $openingCash = DB::table('provenance_events')->where('origin_kind', 'opening')->whereIn('account_id', $cashIds)->orderBy('id')->get();
        $issues = [];
        if (DB::table('amendment_cases')->where('status', 'approved')->exists()) {
            $issues[] = 'amended_opening_roots_require_review';
        }
        $operations = [];
        foreach ($ops as $op) {
            if (in_array((int) $op->id, $neutral, true)) {
                $operations[] = ['id' => (int) $op->id, 'type' => 'neutral_reversal', 'date' => $op->effective_date,
                    'payload_hash' => $op->payload_hash, 'cash_lines' => [], 'opening_cash' => [], 'new_lots' => [], 'exit' => null];

                continue;
            }
            $lines = DB::table('journal_lines')->where('operation_id', $op->id)->orderBy('line_no')->get();
            $cashLines = $lines->whereIn('account_id', $cashIds)->map(fn ($line): array => [
                'account_id' => (int) $line->account_id, 'side' => $line->side, 'amount_rial' => (string) $line->amount_rial,
            ])->values()->all();
            $newLots = [];
            foreach ($lots->where('acquisition_operation_id', $op->id) as $lot) {
                $origin = $origins->firstWhere('lot_id', $lot->id);
                $known = $origin !== null && $origin->evidence === 'user_declared'
                    && (string) $origin->protected_min_atoms === (string) $lot->acquired_atoms;
                $scale = $scales[$lot->account_id] ?? DB::table('instruments')->where('id', $lot->instrument_id)->value('quantity_scale');
                if ($scale === null) {
                    $issues[] = 'acquisition_quantity_requires_review:'.$lot->id;
                }
                $newLots[] = ['lot_id' => (int) $lot->id, 'account_id' => (int) $lot->account_id,
                    'atoms' => (string) $lot->acquired_atoms, 'opening_basis_rial' => $known && $origin->protected_basis_rial !== null
                        ? (string) $origin->protected_basis_rial : null,
                    'quantity_scale' => $scale === null ? null : (int) $scale,
                    'quantity' => $scale === null ? null : (string) BigDecimal::ofUnscaledValue($lot->acquired_atoms, (int) $scale)->strippedOfTrailingZeros()];
                if ($op->type === 'opening' && (! $known || $origin->protected_basis_rial === null)) {
                    $issues[] = 'opening_lot_origin_requires_review:'.$lot->id;
                }
                $acquired = BigInteger::zero();
                foreach (DB::table('quantity_entries')->where('operation_id', $op->id)->where('account_id', $lot->account_id)
                    ->where('signed_atoms', 'not like', '-%')->pluck('signed_atoms') as $signed) {
                    $acquired = $acquired->plus($signed);
                }
                if (! $acquired->isEqualTo($lot->acquired_atoms)) {
                    $issues[] = 'acquisition_quantity_requires_review:'.$lot->id;
                }
            }
            $exit = null;
            $quantity = DB::table('quantity_entries')->where('operation_id', $op->id)->where('signed_atoms', 'like', '-%')->get();
            if ($quantity->count() > 1) {
                $issues[] = 'multiple_exit_accounts_require_review';
            }
            if ($quantity->count() === 1) {
                $q = $quantity->first();
                $cost = BigInteger::zero();
                foreach ($lines->where('account_id', $q->account_id)->where('side', 'credit') as $line) {
                    $cost = $cost->plus((string) $line->amount_rial);
                }
                $net = BigInteger::zero();
                $cashTarget = null;
                if ($op->type === 'asset_sell') {
                    foreach ($cashLines as $line) {
                        if ($line['side'] === 'debit') {
                            $net = $net->plus($line['amount_rial']);
                            $cashTarget = $line['account_id'];
                        }
                    }
                } elseif ($op->type === 'asset_convert' && count($newLots) === 1) {
                    foreach ($lines->where('account_id', $newLots[0]['account_id'])->where('side', 'debit') as $line) {
                        $net = $net->plus((string) $line->amount_rial);
                    }
                    foreach ($cashLines as $line) {
                        if ($line['side'] === 'credit') {
                            $net = $net->minus($line['amount_rial']);
                        }
                    }
                }
                $scale = $scales[$q->account_id] ?? DB::table('instruments')->where('id', $q->instrument_id)->value('quantity_scale');
                if ($scale === null) {
                    $issues[] = 'acquisition_quantity_requires_review:'.$q->account_id;
                }
                $exit = ['account_id' => (int) $q->account_id, 'atoms' => substr($q->signed_atoms, 1),
                    'quantity' => $scale === null ? null : (string) BigDecimal::ofUnscaledValue(substr($q->signed_atoms, 1), (int) $scale)->strippedOfTrailingZeros(),
                    'cash_account_id' => $cashTarget, 'net_proceeds_rial' => (string) $net, 'economic_cost_rial' => (string) $cost];
            }
            $cashRoots = [];
            foreach ($openingCash->where('operation_id', $op->id) as $root) {
                if ((string) $root->balance_after_rial === '0' || ($root->evidence === 'user_declared'
                    && (string) $root->protected_min_after_rial === (string) $root->balance_after_rial)) {
                    $cashRoots[(int) $root->account_id] = (string) $root->protected_min_after_rial;
                } else {
                    $issues[] = 'opening_cash_origin_requires_review:'.$root->account_id;
                }
            }
            if (! in_array($op->type, ['opening', 'income', 'expense', 'transfer', 'asset_buy', 'asset_sell', 'asset_convert'], true)) {
                $issues[] = 'unsupported_origin_operation:'.$op->id;
            }
            $operation = ['id' => (int) $op->id, 'type' => $op->type, 'date' => $op->effective_date, 'payload_hash' => $op->payload_hash,
                'cash_lines' => $cashLines, 'opening_cash' => $cashRoots, 'new_lots' => $newLots, 'exit' => $exit];
            if ($op->type === 'asset_buy') {
                try {
                    ProtectedOriginReplay::verifiedPurchasePrice($operation, '0');
                    $operation['zero_fee_known'] = true;
                } catch (InvalidArgumentException $error) {
                    $operation['zero_fee_known'] = false;
                }
            }
            $operations[] = $operation;
        }
        [$anchors, $anchorIssues] = $this->anchors($date, $ops->pluck('id')->all());
        $issues = array_merge($issues, $anchorIssues);
        $revision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
        $issues = array_values(array_unique($issues));
        $labels = array_map(static fn (string $issue): string => match (true) {
            str_starts_with($issue, 'opening_cash_origin') => 'منشأ افتتاحیهٔ یکی از حساب‌ها کامل نیست.',
            str_starts_with($issue, 'opening_lot_origin') => 'منشأ مخمّس افتتاحیهٔ یکی از بخش‌های دارایی کامل نیست.',
            str_starts_with($issue, 'acquisition_quantity') => 'مقدار یکی از بخش‌های دارایی با ثبت مقدار تطبیق ندارد.',
            str_starts_with($issue, 'unsupported_origin_operation') => 'منشأ عملیات شمارهٔ '.substr($issue, strrpos($issue, ':') + 1).' به بررسی جدا نیاز دارد.',
            $issue === 'amended_opening_roots_require_review' => 'اصلاح افتتاحیه به بازسازی شواهد منشأ نیاز دارد.',
            default => 'پایهٔ انتقالی یا زنجیرهٔ منشأ هنوز نیازمند بررسی است.',
        }, $issues);
        $result = ['policy_version' => ProtectedOriginReplay::VERSION, 'date' => $date, 'revision' => $revision, 'operations' => $operations,
            'anchors' => $anchors, 'accounts' => $accounts, 'issues' => $issues, 'issue_labels' => $labels];
        $result['hash'] = hash('sha256', json_encode([$result, app(RuleCatalog::class)->manifestHash()], JSON_THROW_ON_ERROR));

        return $result;
    }

    private function anchors(string $date, array $operationIds): array
    {
        $valid = [];
        $invalid = [];
        foreach (DB::table('user_decisions')->where('kind', 'khums_basis_resolved')->orderBy('id')->get() as $decision) {
            $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
            $closure = DB::table('khums_period_closures')->where('id', $payload['closure_id'])->first();
            if ($closure === null) {
                $invalid['closure:'.$payload['closure_id']] = true;

                continue;
            }
            $snapshot = app(KhumsPeriodClosure::class)->read((int) $closure->id);
            if ($snapshot['report']['period']['end_exclusive_date'] > $date) {
                continue;
            }
            $key = $payload['account_id'].':'.$closure->start_year;
            $assessment = DB::table('khums_assessments')->where('closure_id', $closure->id)->first();
            $terms = $assessment === null ? null : app(KhumsSettlementTerms::class)->forAssessment((int) $assessment->id);
            $settled = $assessment !== null && $assessment->status === 'settled'
                && BigDecimal::of((string) $assessment->settled_rial)->isEqualTo($terms['payable_due_exact_rial']);
            $resolved = DB::table('khums_basis_carryovers')->where('closure_id', $closure->id)
                ->where('account_id', $payload['account_id'])->where('status', 'resolved_protected')->exists();
            if (! $closure->active || $closure->dependency_stale || ! $settled
                || ! $resolved || ($payload['settlement_terms_decision_id'] ?? null) !== ($terms['decision_id'] ?? null)
                || ($payload['closure_snapshot_hash'] ?? null) !== $closure->snapshot_hash) {
                $invalid[$key] = true;

                continue;
            }
            $items = [];
            foreach ($payload['lot_bases'] ?? [] as $lot) {
                if ($lot['effective_date'] <= $date) {
                    $items[] = ['kind' => 'lot', 'date' => $lot['effective_date'], 'lot_id' => $lot['lot_id'],
                        'covered_atoms' => $lot['covered_atoms'], 'basis_rial' => $lot['protected_basis_rial'],
                        'decision_id' => (int) $decision->id];
                }
            }
            $afterOperation = $payload['basis_after_operation_id'] ?? (int) DB::table('operations')
                ->where('id', '<=', $payload['last_operation_id'])->orderByDesc('effective_date')
                ->orderByDesc('sequence')->orderByDesc('id')->value('id');
            if (($payload['lot_bases'] ?? []) === [] && in_array($afterOperation, $operationIds, true)) {
                $items[] = ['kind' => 'cash', 'account_id' => $payload['account_id'],
                    'after_operation_id' => $afterOperation, 'basis_rial' => $payload['protected_basis_rial'],
                    'decision_id' => (int) $decision->id];
            }
            $valid[$key] = $items;
        }
        $issues = array_diff_key($invalid, $valid) === [] ? [] : ['settled_origin_anchor_requires_review'];
        $anchors = [];
        foreach ($valid as $items) {
            $anchors = [...$anchors, ...$items];
        }

        return [$anchors, $issues];
    }

    private function neutralReversals(array $operations): array
    {
        $ids = [];
        foreach ($operations as $index => $operation) {
            if ($index === 0 || $operation->type !== 'reversal' || $operation->reversal_of_id === null) {
                continue;
            }
            $original = $operations[$index - 1];
            if ((int) $original->id !== (int) $operation->reversal_of_id || $original->effective_date !== $operation->effective_date
                || ! in_array($original->type, ['income', 'accrue_income', 'expense', 'transfer', 'lend', 'collect', 'borrow', 'repay'], true)
                || DB::table('quantity_entries')->whereIn('operation_id', [$original->id, $operation->id])->exists()) {
                continue;
            }
            $net = [];
            foreach (DB::table('journal_lines')->whereIn('operation_id', [$original->id, $operation->id])->get() as $line) {
                $net[$line->account_id] ??= BigInteger::zero();
                $net[$line->account_id] = $line->side === 'debit'
                    ? $net[$line->account_id]->plus((string) $line->amount_rial)
                    : $net[$line->account_id]->minus((string) $line->amount_rial);
            }
            if ($net === [] || array_filter($net, fn ($amount) => ! $amount->isZero()) !== []) {
                continue;
            }
            $ids[] = (int) $original->id;
            $ids[] = (int) $operation->id;
        }

        return $ids;
    }

    private function hasAudit(string $payload): bool
    {
        return DB::table('audit_events')->where('action', 'khums_origin_plan_confirmed')
            ->where('facts_hash', hash('sha256', $payload))->exists();
    }
}
