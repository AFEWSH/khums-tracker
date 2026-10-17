<?php

namespace App\Actions\Finance;

use App\Support\Dates\EffectiveTime;
use App\Support\Khums\KhumsDraft;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\MoneyRial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Read-only date-level preview. No period, assessment, payment, or protected basis is created. */
final class KhumsSimulation
{
    public function asOf(string $date): array
    {
        Gate::authorize('access-app');
        EffectiveTime::dateOnly($date);
        if ($date < OpeningSetup::DATE || $date > now()->toDateString()) {
            throw new InvalidArgumentException('Simulation date is outside the recorded period.');
        }

        return DB::transaction(function () use ($date): array {
            $holdings = [];
            foreach (app(AssetOverview::class)->holdings($date) as $holding) {
                $holdings[$holding['account_id']] = $holding;
            }
            $accounts = DB::table('accounts')->whereIn('class', ['asset', 'liability'])
                ->orderBy('id')->get(['id', 'name', 'class', 'subtype']);
            $components = [];
            $positions = [];
            foreach ($accounts as $account) {
                $book = MoneyRial::fromRial('0');
                $lines = DB::table('journal_lines')->join('operations', 'operations.id', '=', 'journal_lines.operation_id')
                    ->where('journal_lines.account_id', $account->id)->where('operations.effective_date', '<=', $date)
                    ->get(['journal_lines.side', 'journal_lines.amount_rial']);
                foreach ($lines as $line) {
                    $amount = MoneyRial::fromRial((string) $line->amount_rial);
                    $increase = $line->side === ($account->class === 'asset' ? 'debit' : 'credit');
                    $book = $increase ? $book->add($amount) : $book->subtract($amount);
                }
                if ($book->rial() === '0' && $lines->isEmpty()) {
                    continue;
                }
                $value = $account->class === 'liability' ? null : $book->rial();
                $issue = $account->class === 'liability' ? 'liability_deduction_requires_review' : 'religious_basis_requires_review';
                if (str_starts_with($book->rial(), '-')) {
                    $value = null;
                    $issue = 'negative_position_requires_review';
                }
                $market = null;
                $priceDate = null;
                $priceRevision = null;
                if ($account->subtype === 'holding') {
                    $holding = $holdings[(int) $account->id] ?? null;
                    $market = ($holding['market_toman'] ?? null) === null ? null : MoneyRial::fromToman($holding['market_toman']);
                    $priceDate = $holding['price_date'] ?? null;
                    $priceRevision = $holding['price_revision'] ?? null;
                    $value = $market?->rial();
                    $issue = $market === null ? 'market_valuation_requires_review' : 'religious_basis_requires_review';
                }
                if (in_array($account->subtype, ['receivable_fx', 'payable_fx'], true)) {
                    $value = null;
                    $issue = 'market_valuation_requires_review';
                }
                $id = 'account:'.$account->id;
                $positions[] = [
                    'component_id' => $id, 'account_id' => (int) $account->id,
                    'name' => $account->name, 'class' => $account->class,
                    'book_rial' => $book->rial(), 'book_toman' => $book->toman(),
                    'market_rial' => $market?->rial(), 'market_toman' => $market?->toman(),
                    'price_date' => $priceDate, 'price_revision' => $priceRevision,
                    'issue' => $issue, 'issue_label' => $this->label($issue),
                ];
                $components[] = [
                    'component_id' => $id, 'value_rial' => $value,
                    'eligible_rial' => null, 'rule_id' => null, 'evidence_status' => 'unknown',
                ];
            }
            $catalog = app(RuleCatalog::class);
            $draft = KhumsDraft::evaluate($components, array_column($catalog->active(), 'id'), false);
            $state = DB::table('financial_state')->where('id', 1)->first();
            $issues = $draft['issues'];
            if ($state->opening_confirmed_at === null) {
                $issues[] = 'opening_not_confirmed';
            }
            $coverage = app(CoverageConfirmation::class)->latest();
            if ($coverage === null || $coverage['through_date'] < $date
                || $coverage['input_hash'] !== app(CoverageConfirmation::class)->preview($coverage['through_date'])['input_hash']) {
                $issues[] = 'coverage_not_confirmed';
            }

            return [
                'purpose' => 'simulation', 'as_of_date' => $date,
                'period_start_date' => OpeningSetup::DATE, 'boundary_status' => 'unconfirmed',
                'rule_version' => $catalog->version(), 'rule_manifest_hash' => $catalog->manifestHash(),
                'financial_revision' => (int) $state->revision,
                'positions' => $positions, 'items' => $draft['items'],
                'issues' => array_values(array_unique($issues)),
                'issue_labels' => array_values(array_unique(array_map(fn (string $issue): string => $this->label(explode(':', $issue, 2)[0]), $issues))),
                'status' => 'incomplete',
                'known_subtotal_exact_rial' => $draft['known_subtotal_exact_rial'],
                'total_due_exact_rial' => null, 'payment_amount_rial' => null,
                'input_hash' => hash('sha256', json_encode([$date, $state->revision, $catalog->manifestHash(), $positions], JSON_THROW_ON_ERROR)),
            ];
        });
    }

    private function label(string $code): string
    {
        return match ($code) {
            'boundary_time_unconfirmed' => 'ساعت مرز سال خمسی تأیید نشده است',
            'opening_not_confirmed' => 'افتتاحیه هنوز تأیید نشده است',
            'coverage_not_confirmed' => 'پوشش ثبت تراکنش‌ها تا این تاریخ تأیید نشده است',
            'no_components' => 'هیچ قلم مالی برای ارزیابی وجود ندارد',
            'value_missing', 'market_valuation_requires_review' => 'ارزش معتبر برخی اقلام برای این تاریخ موجود نیست',
            'eligible_basis_missing', 'religious_basis_requires_review' => 'منشأ و مبلغ مشمول خمس برخی اقلام نیازمند بررسی است',
            'liability_deduction_requires_review' => 'کسر بدهی نیازمند بررسی شرایط شرعی است',
            'negative_position_requires_review' => 'ماندهٔ منفی نیازمند بررسی و اصلاح است',
            'evidence_unconfirmed' => 'شواهد منشأ برخی اقلام تأیید نشده است',
            'rule_not_active' => 'قاعدهٔ معتبر و فعال برای برخی اقلام موجود نیست',
            default => 'وضعیت نیازمند بررسی است',
        };
    }
}
