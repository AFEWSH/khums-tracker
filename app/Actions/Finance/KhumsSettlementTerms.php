<?php

namespace App\Actions\Finance;

use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\MoneyRial;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** An explicitly evidenced whole-rial payment agreement; never a general religious rounding rule. */
final class KhumsSettlementTerms
{
    public const CAPABILITY = 'khums_fractional_settlement';

    public function factLabels(string $ruleId): array
    {
        Gate::authorize('access-app');
        $rule = app(RuleCatalog::class)->requireActive($ruleId);
        if (! in_array(self::CAPABILITY, $rule['capabilities'] ?? [], true)
            || ! is_array($rule['required_facts'] ?? null) || $rule['required_facts'] === []) {
            throw new FinanceInputException('A reviewed rule specifically supporting this payment agreement is required.');
        }
        foreach ($rule['required_facts'] as $label) {
            if (! is_string($label) || trim($label) === '') {
                throw new FinanceInputException('Agreement rules must declare nonempty fact labels.');
            }
        }

        return array_values(array_unique([...$rule['required_facts'], 'تأیید مجوز همین مبلغ نهایی برای همین تعهد']));
    }

    public function forAssessment(int $assessmentId): array
    {
        Gate::authorize('access-app');

        return DB::transaction(fn (): array => $this->readTerms($assessmentId));
    }

    private function readTerms(int $assessmentId): array
    {
        $assessment = DB::table('khums_assessments')->where('id', $assessmentId)->first();
        if ($assessment === null) {
            throw new FinanceInputException('Assessment does not exist.');
        }
        $computed = (string) $assessment->due_exact_rial;
        $result = ['computed_due_exact_rial' => $computed, 'payable_due_exact_rial' => $computed,
            'fractional' => ! BigDecimal::of($computed)->isEqualTo(BigDecimal::of($computed)->toScale(0, RoundingMode::Down)),
            'decision_id' => null, 'case_reference' => null, 'rule_id' => null, 'facts' => []];
        $decision = DB::table('user_decisions')->where('kind', 'khums_settlement_terms')
            ->where('target_type', 'assessment')->where('target_id', $assessmentId)->orderByDesc('id')->first();
        if ($decision === null) {
            return $result;
        }
        $payload = json_decode($decision->payload, true, flags: JSON_THROW_ON_ERROR);
        $closureHash = DB::table('khums_period_closures')->where('id', $assessment->closure_id)->value('snapshot_hash');
        $rule = $payload['rule_snapshot'] ?? [];
        $recorded = DB::table('audit_events')->where('action', 'khums_settlement_terms_confirmed')
            ->where('facts_hash', hash('sha256', $decision->payload))->exists();
        if (! $recorded || ($payload['policy_version'] ?? null) !== 'case-whole-rial-v1' || ($payload['assessment_id'] ?? null) !== $assessmentId
            || ($payload['closure_snapshot_hash'] ?? null) !== $closureHash
            || ($payload['computed_due_exact_rial'] ?? null) !== $computed
            || ($rule['status'] ?? null) !== 'active' || ($rule['review'] ?? null) !== 'validated'
            || ! in_array(self::CAPABILITY, $rule['capabilities'] ?? [], true)
            || ! $this->isWholeAgreement($computed, $payload['payable_rial'] ?? '')) {
            throw new FinanceInputException('The payment agreement is damaged or does not match this assessment.');
        }

        return array_replace($result, ['payable_due_exact_rial' => $payload['payable_rial'],
            'decision_id' => (int) $decision->id, 'case_reference' => $payload['case_reference'],
            'rule_id' => $rule['id'], 'facts' => $payload['proposal']['facts']]);
    }

    public function preview(int $assessmentId): array
    {
        Gate::authorize('access-app');

        return DB::transaction(function () use ($assessmentId): array {
            $assessment = DB::table('khums_assessments')->where('id', $assessmentId)->first();
            $closure = $assessment === null ? null : DB::table('khums_period_closures')->where('id', $assessment->closure_id)->first();
            if ($closure === null || ! $closure->active || $closure->dependency_stale) {
                throw new FinanceInputException('An active reviewed assessment is required.');
            }
            app(KhumsPeriodClosure::class)->read((int) $closure->id);
            $preview = ['assessment_id' => $assessmentId, 'closure_id' => (int) $closure->id,
                'closure_snapshot_hash' => $closure->snapshot_hash, 'settled_rial' => (string) $assessment->settled_rial,
                'status' => $assessment->status, 'terms' => $this->forAssessment($assessmentId),
                'rule_manifest_hash' => app(RuleCatalog::class)->manifestHash()];
            $preview['hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));

            return $preview;
        });
    }

    public function confirm(int $assessmentId, string $seenHash, string $payableRial, string $ruleId, array $facts,
        string $caseReference, bool $approved, ?int $replacesId = null, string $reason = ''): int
    {
        Gate::authorize('access-app');
        $caseReference = trim($caseReference);
        $reason = trim($reason);
        $payable = MoneyRial::fromRial($payableRial)->rial();
        if (! $approved || ! preg_match('/^[a-f0-9]{64}$/D', $seenHash)
            || mb_strlen($caseReference) < 10 || mb_strlen($caseReference) > 500
            || array_keys($facts) !== $this->factLabels($ruleId)
            || ($replacesId !== null && ($replacesId < 1 || mb_strlen($reason) < 10 || mb_strlen($reason) > 240))) {
            throw new FinanceInputException('A specific approved payment agreement and its evidence are required.');
        }
        foreach ($facts as $fact) {
            if (! is_string($fact) || mb_strlen(trim($fact)) < 10 || mb_strlen($fact) > 1000) {
                throw new FinanceInputException('Every agreement fact must be stated explicitly.');
            }
        }

        return DB::transaction(function () use ($assessmentId, $seenHash, $payable, $ruleId, $facts, $caseReference, $replacesId, $reason): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $current = DB::table('user_decisions')->where('kind', 'khums_settlement_terms')
                ->where('target_type', 'assessment')->where('target_id', $assessmentId)->orderByDesc('id')->first();
            $proposal = ['observed_hash' => $seenHash, 'payable_rial' => $payable, 'rule_id' => $ruleId,
                'rule_manifest_hash' => app(RuleCatalog::class)->manifestHash(), 'facts' => $facts,
                'case_reference' => $caseReference, 'replaces_id' => $replacesId, 'reason' => $reason];
            if ($current !== null) {
                $old = json_decode($current->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($old['proposal'] ?? null) === $proposal) {
                    $this->forAssessment($assessmentId);

                    return (int) $current->id;
                }
                if ($replacesId !== (int) $current->id) {
                    throw new FinanceInputException('A different agreement requires an explicit revision.');
                }
            } elseif ($replacesId !== null) {
                throw new FinanceInputException('There is no previous agreement to revise.');
            }
            $preview = $this->preview($assessmentId);
            if (! hash_equals($preview['hash'], $seenHash) || ! $this->isWholeAgreement($preview['terms']['computed_due_exact_rial'], $payable)) {
                throw new FinanceInputException('Review the exact fractional amount and case evidence again.');
            }
            $payload = ['assessment_id' => $assessmentId, 'closure_snapshot_hash' => $preview['closure_snapshot_hash'],
                'computed_due_exact_rial' => $preview['terms']['computed_due_exact_rial'], 'payable_rial' => $payable,
                'case_reference' => $caseReference, 'rule_snapshot' => app(RuleCatalog::class)->requireActive($ruleId),
                'proposal' => $proposal, 'policy_version' => 'case-whole-rial-v1'];
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $id = DB::table('user_decisions')->insertGetId(['kind' => 'khums_settlement_terms', 'target_type' => 'assessment',
                'target_id' => $assessmentId, 'payload' => $encoded, 'actor_id' => auth()->id(), 'confirmed_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            $assessment = DB::table('khums_assessments')->where('id', $assessmentId)->first();
            $reconciliation = DB::table('user_decisions')->where('kind', 'khums_reconciliation')
                ->where('target_type', 'assessment')->where('target_id', $assessmentId)->exists();
            $status = $reconciliation || str_starts_with($assessment->status, 'reconciliation') || $assessment->status === 'settled_excess_review'
                ? 'reconciliation_required'
                : (BigDecimal::of((string) $assessment->settled_rial)->isEqualTo($payable) ? 'settled'
                    : ((string) $assessment->settled_rial === '0' ? 'unpaid' : 'partially_paid'));
            DB::table('khums_assessments')->where('id', $assessmentId)->update(['status' => $status]);
            DB::table('khums_basis_carryovers')->where('closure_id', $preview['closure_id'])
                ->update(['status' => $status === 'settled' ? 'settled_basis_review' : 'assessed_unsettled']);
            $affected = app(KhumsYearDependencies::class)->affectedClosureIds($preview['closure_id']);
            if ($affected !== []) {
                DB::table('khums_period_closures')->whereIn('id', $affected)->update(['dependency_stale' => true]);
            }
            DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'action' => 'khums_settlement_terms_confirmed',
                'facts_hash' => hash('sha256', $encoded), 'recorded_at' => now()]);

            return $id;
        });
    }

    private function isWholeAgreement(string $computed, string $payable): bool
    {
        if (! preg_match('/^[0-9]+$/D', $payable)) {
            return false;
        }
        $exact = BigDecimal::of($computed);

        return ! $exact->isEqualTo($exact->toScale(0, RoundingMode::Down))
            && $exact->toScale(0, RoundingMode::Ceiling)->isEqualTo($payable);
    }
}
