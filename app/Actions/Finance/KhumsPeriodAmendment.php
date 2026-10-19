<?php

namespace App\Actions\Finance;

use App\Support\Khums\KhumsClosureGate;
use App\Support\Khums\RuleCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Preserves the old closure and payments while creating a reviewed replacement. */
final class KhumsPeriodAmendment
{
    public function approveWithLocalBackup(int $closureId, int $reviewId, string $seenDiffHash, string $reason, bool $explicitApproval): int
    {
        Gate::authorize('access-app');
        if (! $explicitApproval) {
            throw new FinanceInputException('Explicit approval is required.');
        }
        $duplicate = $this->existingAmendment($closureId, $reviewId, $seenDiffHash, trim($reason));
        if ($duplicate !== null) {
            return $duplicate;
        }
        $proof = app(KhumsBackupVerifier::class)->createForReview($reviewId);

        return $this->approve($closureId, $reviewId, $seenDiffHash, $reason, $proof['path'], $proof['password'], true);
    }

    public function preview(int $closureId, int $reviewId): array
    {
        Gate::authorize('access-app');
        $old = DB::table('khums_period_closures')->where('id', $closureId)->first();
        if ($old === null || ! $old->active) {
            throw new FinanceInputException('Select the current closed revision.');
        }
        $review = app(KhumsPeriodReview::class)->read($reviewId);
        if ($review['status'] !== 'computed' || (int) substr($review['period']['start_persian'], 0, 4) !== (int) $old->start_year) {
            throw new FinanceInputException('A complete review of the same year is required.');
        }
        $fresh = app(KhumsPeriodReport::class)->forYear((int) $old->start_year);
        if (! hash_equals($review['input_hash'], $fresh['input_hash'])
            || hash_equals($old->input_hash, $fresh['input_hash'])) {
            throw new FinanceInputException('The replacement must be current and different.');
        }
        $assessment = DB::table('khums_assessments')->where('closure_id', $closureId)->first();
        $affected = DB::table('khums_period_closures')->where('depends_on_closure_id', $closureId)
            ->orderBy('start_year')->pluck('id')->all();
        $delta = BigDecimal::of($fresh['total_due_exact_rial'])->minus($old->due_exact_rial);
        $preview = [
            'old_closure_id' => $closureId, 'new_review_id' => $reviewId,
            'old_snapshot_hash' => $old->snapshot_hash,
            'old_due_exact_rial' => $old->due_exact_rial,
            'new_due_exact_rial' => $fresh['total_due_exact_rial'],
            'delta_exact_rial' => (string) $delta,
            'prior_settled_rial' => (string) $assessment->settled_rial,
            'dependent_closure_ids' => $affected,
            'input_hash' => $fresh['input_hash'],
        ];
        $preview['diff_hash'] = hash('sha256', json_encode($preview, JSON_THROW_ON_ERROR));

        return $preview;
    }

    public function approve(int $closureId, int $reviewId, string $seenDiffHash, string $reason, string $backupPath, string $backupPassword, bool $explicitApproval): int
    {
        Gate::authorize('access-app');
        $reason = trim($reason);
        if (! $explicitApproval || ! preg_match('/^[a-f0-9]{64}$/D', $seenDiffHash)
            || mb_strlen($reason) < 10 || mb_strlen($reason) > 240) {
            throw new FinanceInputException('A reviewed difference, reason and explicit approval are required.');
        }
        $duplicate = $this->existingAmendment($closureId, $reviewId, $seenDiffHash, $reason);
        if ($duplicate !== null) {
            return $duplicate;
        }
        $preview = $this->preview($closureId, $reviewId);
        if (! hash_equals($preview['diff_hash'], $seenDiffHash)) {
            throw new FinanceInputException('The amendment difference changed.');
        }
        $backupHash = app(KhumsBackupVerifier::class)->verifyReview($reviewId, $backupPath, $backupPassword);

        return DB::transaction(function () use ($closureId, $reviewId, $seenDiffHash, $reason, $backupHash): int {
            DB::table('financial_state')->where('id', 1)->update(['updated_at' => now()]);
            $duplicate = $this->existingAmendment($closureId, $reviewId, $seenDiffHash, $reason);
            if ($duplicate !== null) {
                return $duplicate;
            }
            $diff = $this->preview($closureId, $reviewId);
            if (! hash_equals($diff['diff_hash'], $seenDiffHash)) {
                throw new FinanceInputException('The amendment difference changed.');
            }
            $old = DB::table('khums_period_closures')->where('id', $closureId)->first();
            $fresh = app(KhumsPeriodReport::class)->forYear((int) $old->start_year);
            $catalog = app(RuleCatalog::class);
            $active = array_column($catalog->active(), 'id');
            $rulesValid = $fresh['rule_manifest_hash'] === $catalog->manifestHash();
            foreach ($fresh['items'] as $item) {
                if ($item['status'] !== 'computed' || ! in_array($item['rule_id'], $active, true)) {
                    $rulesValid = false;
                }
            }
            $blockers = KhumsClosureGate::blockers($fresh['period'], 'due', $fresh, [
                'input_current' => true, 'coverage_confirmed' => ! in_array('coverage_not_confirmed', $fresh['issues'], true),
                'dependencies_valid' => ! $old->dependency_stale,
                'rules_validated' => $rulesValid, 'backup_verified' => true, 'snapshot_complete' => true,
            ], true);
            if ($blockers !== []) {
                throw new FinanceInputException('Amendment blocked: '.implode(', ', $blockers));
            }
            $oldSnapshot = app(KhumsPeriodClosure::class)->read($closureId);
            $snapshot = json_encode([
                'schema' => 1, 'report' => $fresh, 'prior_snapshot_hash' => $old->snapshot_hash,
                'prior_report' => $oldSnapshot['report'], 'amendment_diff' => $diff,
                'sources' => app(KhumsPeriodClosure::class)->sourceSnapshot($fresh),
                'rule_manifest' => config('khums_rules'), 'backup_sha256' => $backupHash,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $snapshotHash = hash('sha256', $snapshot);
            DB::table('khums_period_closures')->where('id', $closureId)->where('active', true)->update(['active' => false]);
            $newId = DB::table('khums_period_closures')->insertGetId([
                'start_year' => $old->start_year, 'revision' => (int) $old->revision + 1,
                'review_id' => $reviewId, 'previous_closure_id' => $closureId,
                'depends_on_closure_id' => $old->depends_on_closure_id,
                'input_hash' => $fresh['input_hash'], 'snapshot_hash' => $snapshotHash,
                'snapshot_json' => $snapshot, 'due_exact_rial' => $fresh['total_due_exact_rial'],
                'backup_sha256' => $backupHash, 'active' => true, 'reason' => $reason,
                'approved_by' => auth()->id(), 'approved_at' => now(),
            ]);
            $oldAssessment = DB::table('khums_assessments')->where('closure_id', $closureId)->first();
            $hasPayment = (int) $oldAssessment->settled_rial > 0;
            DB::table('khums_assessments')->where('id', $oldAssessment->id)->update([
                'status' => $hasPayment ? 'superseded_reconciliation' : 'superseded',
            ]);
            DB::table('khums_assessments')->insert([
                'closure_id' => $newId, 'due_exact_rial' => $fresh['total_due_exact_rial'],
                'settled_rial' => 0,
                'status' => $hasPayment ? 'reconciliation_required' : (BigDecimal::of($fresh['total_due_exact_rial'])->isZero() ? 'settled' : 'unpaid'),
                'created_at' => now(),
            ]);
            foreach ($fresh['items'] as $item) {
                if (BigDecimal::of($item['eligible_rial'])->isZero()) {
                    continue;
                }
                DB::table('khums_basis_carryovers')->insert([
                    'closure_id' => $newId,
                    'account_id' => (int) substr($item['component_id'], strlen('account:')),
                    'status' => 'assessed_unsettled',
                    'eligible_rial' => $item['eligible_rial'], 'rule_id' => $item['rule_id'],
                ]);
            }
            DB::table('khums_period_closures')->where('depends_on_closure_id', $closureId)
                ->update(['dependency_stale' => true]);
            DB::table('audit_events')->insert([
                'actor_id' => auth()->id(), 'action' => 'khums_period_amended',
                'facts_hash' => $snapshotHash, 'recorded_at' => now(),
            ]);

            return $newId;
        });
    }

    private function existingAmendment(int $closureId, int $reviewId, string $seenDiffHash, string $reason): ?int
    {
        $row = DB::table('khums_period_closures')->where('previous_closure_id', $closureId)
            ->where('review_id', $reviewId)->first();
        if ($row === null) {
            return null;
        }
        $snapshot = app(KhumsPeriodClosure::class)->read((int) $row->id);
        if ($row->reason !== $reason || ! hash_equals($snapshot['amendment_diff']['diff_hash'] ?? '', $seenDiffHash)) {
            throw new FinanceInputException('The amendment was already saved with different details.');
        }

        return (int) $row->id;
    }
}
