<?php

namespace App\Livewire;

use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAmendmentReconciliation;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsDueDrafts;
use App\Actions\Finance\KhumsOriginPlan;
use App\Actions\Finance\KhumsPeriodAmendment;
use App\Actions\Finance\KhumsPeriodCalendar;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsProceedsAllocation;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\KhumsSettlementTerms;
use App\Actions\Finance\KhumsSimulation;
use App\Support\Dates\PersianDate;
use App\Support\Khums\KhumsYearSchedule;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\DecimalInput;
use Brick\Math\BigRational;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('پیش‌نویس خمس')]
class KhumsWorkspace extends Component
{
    public string $asOfDate = '';

    public ?array $report = null;

    public ?array $periodReport = null;

    public ?int $savedPeriodReviewId = null;

    public int $componentAccountId = 0;

    public string $componentEligibleRial = '';

    public string $componentRuleId = '';

    public array $componentFacts = [];

    public string $componentDecisionKey = '';

    public bool $closureApproved = false;

    public int $proceedsAccountId = 0;

    public string $proceedsProtectedRial = '';

    public array $proceedsFacts = [];

    public bool $proceedsApproved = false;

    public ?array $originPreview = null;

    public array $originPlan = [];

    public array $originFacts = [];

    public bool $originApproved = false;

    public bool $originRevisionApproved = false;

    public string $originRevisionReason = '';

    public ?int $closedClosureId = null;

    public ?array $closedReport = null;

    public string $paymentAccountId = '';

    public string $paymentDate = '';

    public string $paymentAmountRial = '';

    public string $paymentMethod = 'from_same_asset';

    public string $paymentEvidence = '';

    public string $paymentKey = '';

    public bool $paymentApproved = false;

    public ?array $termsPreview = null;

    public string $termsRuleId = '';

    public string $termsPayableRial = '';

    public string $termsCaseReference = '';

    public array $termsFacts = [];

    public bool $termsApproved = false;

    public bool $termsRevisionApproved = false;

    public string $termsRevisionReason = '';

    public string $basisAccountId = '';

    public array $basisFacts = [];

    public bool $basisApproved = false;

    public string $amendmentReason = '';

    public ?array $amendmentDiff = null;

    public bool $amendmentApproved = false;

    public ?array $reconciliationPreview = null;

    public string $reconciliationReason = '';

    public bool $reconciliationApproved = false;

    public string $scheduleStartYear = '';

    public string $scheduleEndMonth = '5';

    public string $scheduleEndDay = '20';

    public string $scheduleEndClock = '00:00';

    public string $scheduleReason = '';

    public ?array $schedulePreview = null;

    public bool $scheduleApproved = false;

    public function mount(): void
    {
        Gate::authorize('access-app');
        $this->asOfDate = PersianDate::fromGregorian(now()->toDateString());
        $this->paymentDate = $this->asOfDate;
        $this->componentDecisionKey = Str::random(24);
        $this->paymentKey = Str::random(24);
        app(KhumsDueDrafts::class)->ensure();
    }

    public function simulate(KhumsSimulation $simulation): void
    {
        Gate::authorize('access-app');
        $this->report = null;
        try {
            $date = PersianDate::parseInput($this->asOfDate);
            $this->report = $simulation->asOf($date);
            $this->resetErrorBag();
        } catch (InvalidArgumentException $error) {
            $this->addError('asOfDate', 'تاریخ باید از ۲۰ مرداد ۱۴۰۵ تا امروز باشد.');
        }
    }

    public function reviewPeriod(int $startYear, KhumsPeriodReport $reports): void
    {
        Gate::authorize('access-app');
        $this->periodReport = $reports->forYear($startYear);
        $this->savedPeriodReviewId = null;
        $this->amendmentDiff = null;
        $this->originPreview = null;
        $this->originApproved = false;
    }

    public function savePeriodReview(KhumsPeriodReview $reviews): void
    {
        Gate::authorize('access-app');
        if ($this->periodReport === null) {
            throw new FinanceInputException('Review the period first.');
        }
        $year = (int) substr($this->periodReport['period']['start_persian'], 0, 4);
        $this->savedPeriodReviewId = $reviews->save($year, $this->periodReport['input_hash']);
    }

    public function loadPeriodReview(int $id, KhumsPeriodReview $reviews): void
    {
        Gate::authorize('access-app');
        $this->periodReport = $reviews->read($id);
        $this->savedPeriodReviewId = $id;
        $this->amendmentDiff = null;
    }

    public function confirmComponent(KhumsComponentDecision $decisions, RuleCatalog $catalog, KhumsPeriodReport $reports): void
    {
        Gate::authorize('access-app');
        if ($this->periodReport === null) {
            throw new FinanceInputException('Review a period first.');
        }
        $rule = $catalog->requireActive($this->componentRuleId);
        $facts = [];
        foreach ($rule['required_facts'] as $index => $label) {
            $facts[$label] = $this->componentFacts[$index] ?? '';
        }
        $year = (int) substr($this->periodReport['period']['start_persian'], 0, 4);
        $decisions->confirm($year, $this->componentAccountId, $this->periodReport['component_input_hash'],
            $this->componentEligibleRial, $this->componentRuleId, $facts, $this->componentDecisionKey);
        $this->periodReport = $reports->forYear($year);
        $this->savedPeriodReviewId = null;
        $this->componentDecisionKey = Str::random(24);
        $this->componentFacts = [];
        $this->componentEligibleRial = '';
    }

    public function allocateProceeds(KhumsProceedsAllocation $allocations, RuleCatalog $catalog, KhumsPeriodReport $reports): void
    {
        Gate::authorize('access-app');
        if ($this->periodReport === null) {
            $this->addError('proceeds', 'ابتدا گزارش تازهٔ دوره را باز کنید.');

            return;
        }
        try {
            $rule = $catalog->requireActive('K12');
            $facts = [];
            foreach ($rule['required_facts'] as $index => $label) {
                $facts[$label] = $this->proceedsFacts[$index] ?? '';
            }
            $year = (int) substr($this->periodReport['period']['start_persian'], 0, 4);
            $allocations->confirm($this->periodReport['as_of_date'], $this->proceedsAccountId,
                $this->periodReport['asset_basis_trace']['raw_hash'], DecimalInput::normalize($this->proceedsProtectedRial),
                $facts, $this->proceedsApproved);
            $this->periodReport = $reports->forYear($year);
            $this->savedPeriodReviewId = null;
            $this->proceedsApproved = false;
            $this->proceedsFacts = [];
            $this->proceedsProtectedRial = '';
            $this->resetErrorBag('proceeds');
        } catch (FinanceInputException|InvalidArgumentException|\LogicException $error) {
            $this->addError('proceeds', 'تخصیص ثبت نشد؛ قاعده، شواهد، مبلغ و تازگی گزارش را بررسی کنید.');
        }
    }

    public function prepareOriginPlan(KhumsOriginPlan $plans): void
    {
        Gate::authorize('access-app');
        try {
            $facts = $plans->factLabels();
            if ($this->periodReport === null) {
                throw new FinanceInputException('Review a period first.');
            }
            $preview = $plans->preview($this->periodReport['as_of_date']);
            if ($preview['issues'] !== []) {
                $this->originPreview = null;
                $this->addError('origin', implode(' ', $preview['issue_labels']));

                return;
            }
            $this->originPlan = [];
            $available = [];
            foreach ($preview['operations'] as &$operation) {
                foreach ($operation['cash_lines'] as $line) {
                    if ($line['side'] === 'credit') {
                        $this->originPlan[$operation['id']]['cash_spends'][$line['account_id']] = '';
                    }
                }
                if ($operation['type'] === 'asset_buy') {
                    $this->originPlan[$operation['id']]['acquisition_basis_rial'] = '';
                    $this->originPlan[$operation['id']]['purchase_fee_rial'] = $operation['zero_fee_known'] ? '0' : '';
                }
                if ($operation['exit'] !== null) {
                    $operation['available_lots'] = array_values(array_filter($available,
                        fn ($lot) => $lot['account_id'] === $operation['exit']['account_id']));
                    foreach ($operation['available_lots'] as $lot) {
                        $this->originPlan[$operation['id']]['lots'][$lot['lot_id']] = ['atoms' => '0', 'net_proceeds_rial' => '0'];
                    }
                }
                $available = [...$available, ...$operation['new_lots']];
            }
            unset($operation);
            $this->originPlan = array_replace_recursive($this->originPlan, $preview['existing_display_plan'] ?? []);
            $preview['fact_labels'] = $facts;
            $this->originPreview = $preview;
            $this->originFacts = array_values($preview['existing_facts'] ?? []);
            $this->originApproved = false;
            $this->originRevisionApproved = false;
            $this->originRevisionReason = '';
            $this->resetErrorBag('origin');
        } catch (FinanceInputException|\LogicException $error) {
            $this->originPreview = null;
            $this->addError('origin', 'گزارش تازه و قواعد معتبر لازم‌اند؛ شواهد منشأ و وضعیت افتتاحیه را بررسی کنید.');
        }
    }

    public function confirmOriginPlan(KhumsOriginPlan $plans, KhumsPeriodReport $reports): void
    {
        Gate::authorize('access-app');
        $this->validate([
            'originPlan' => ['array'], 'originPlan.*' => ['array'],
            'originPlan.*.cash_spends' => ['sometimes', 'array'],
            'originPlan.*.cash_spends.*' => ['string', 'max:160'],
            'originPlan.*.acquisition_basis_rial' => ['sometimes', 'string', 'max:160'],
            'originPlan.*.purchase_fee_rial' => ['sometimes', 'string', 'max:80'],
            'originPlan.*.lots' => ['sometimes', 'array'], 'originPlan.*.lots.*' => ['array'],
            'originPlan.*.lots.*.atoms' => ['required', 'string', 'max:80'],
            'originPlan.*.lots.*.net_proceeds_rial' => ['required', 'string', 'max:80'],
        ]);
        try {
            if ($this->originPreview === null || $this->periodReport === null) {
                throw new FinanceInputException('Preview the origin history first.');
            }
            $plan = $this->originPlan;
            foreach ($plan as &$choice) {
                foreach ($choice['cash_spends'] ?? [] as $account => $amount) {
                    $choice['cash_spends'][$account] = $this->originNumber($amount);
                }
                if (isset($choice['acquisition_basis_rial'])) {
                    $choice['acquisition_basis_rial'] = $this->originNumber($choice['acquisition_basis_rial']);
                }
                if (isset($choice['purchase_fee_rial'])) {
                    $choice['purchase_fee_rial'] = DecimalInput::normalize($choice['purchase_fee_rial']);
                }
                foreach ($choice['lots'] ?? [] as $id => $lot) {
                    $atoms = $plans->quantityAtoms((int) $id, $lot['atoms']);
                    $net = DecimalInput::normalize($lot['net_proceeds_rial']);
                    if ($atoms === '0' && $net === '0') {
                        unset($choice['lots'][$id]);
                    } else {
                        $choice['lots'][$id] = ['atoms' => $atoms, 'net_proceeds_rial' => $net];
                    }
                }
            }
            unset($choice);
            $facts = [];
            foreach ($plans->factLabels() as $index => $label) {
                $facts[$label] = $this->originFacts[$index] ?? '';
            }
            $plans->confirm($this->originPreview['date'], $this->originPreview['hash'], $plan, $facts, $this->originApproved,
                $this->originRevisionApproved ? $this->originPreview['existing_decision_id'] : null, $this->originRevisionReason);
            $year = (int) substr($this->periodReport['period']['start_persian'], 0, 4);
            $this->periodReport = $reports->forYear($year);
            $this->savedPeriodReviewId = null;
            $this->originPreview = null;
            $this->originApproved = false;
            $this->originFacts = [];
            $this->originRevisionApproved = false;
            $this->originRevisionReason = '';
            $this->resetErrorBag('origin');
        } catch (FinanceInputException|\LogicException $error) {
            $this->addError('origin', 'تخصیص ثبت نشد؛ تازگی گزارش، شواهد، مجموع مقدار خروج، عوض و بقای اصل مخمّس را بررسی کنید.');
        }
    }

    private function originNumber(string $value): string
    {
        $parts = explode('/', $value);
        if (count($parts) > 2) {
            throw new InvalidArgumentException('Invalid exact fraction.');
        }
        $number = BigRational::of(DecimalInput::normalize($parts[0]));
        if (count($parts) === 2) {
            $denominator = BigRational::of(DecimalInput::normalize($parts[1]));
            if ($denominator->isZero() || $denominator->isNegative()) {
                throw new InvalidArgumentException('Fraction denominator must be positive.');
            }
            $number = $number->dividedBy($denominator);
        }

        return (string) $number;
    }

    public function closeYear(KhumsPeriodClosure $closures): void
    {
        Gate::authorize('access-app');
        if ($this->savedPeriodReviewId === null || $this->periodReport === null) {
            throw new FinanceInputException('Save a complete period review first.');
        }
        $this->closedClosureId = $closures->closeWithLocalBackup(
            $this->savedPeriodReviewId, $this->periodReport['input_hash'], $this->closureApproved
        );
        $this->closedReport = $closures->read($this->closedClosureId)['report'];
        $this->closureApproved = false;
    }

    public function showClosedPeriod(int $id, KhumsPeriodClosure $closures): void
    {
        Gate::authorize('access-app');
        $this->closedReport = $closures->read($id)['report'];
        $this->closedClosureId = $id;
        $this->amendmentDiff = null;
        $this->reconciliationPreview = null;
        $this->paymentApproved = false;
        $this->basisApproved = false;
        $this->termsPreview = null;
        $this->termsApproved = false;
        $this->termsRevisionApproved = false;
    }

    public function updatedTermsRuleId(): void
    {
        $this->termsFacts = [];
        $this->termsApproved = false;
    }

    public function payKhums(KhumsSettlement $settlements): void
    {
        Gate::authorize('access-app');
        $closure = DB::table('khums_period_closures')->where('id', $this->closedClosureId)->where('active', true)->first();
        if ($closure === null) {
            throw new FinanceInputException('Select an active closed period.');
        }
        $assessmentId = (int) DB::table('khums_assessments')->where('closure_id', $closure->id)->value('id');
        $settlements->pay($assessmentId, (int) $this->paymentAccountId,
            PersianDate::parseInput($this->paymentDate), $this->paymentAmountRial,
            $this->paymentMethod, $this->paymentEvidence, $this->paymentKey, $this->paymentApproved);
        $this->paymentApproved = false;
        $this->paymentKey = Str::random(24);
        $this->paymentAmountRial = '';
        $this->paymentEvidence = '';
    }

    public function prepareSettlementTerms(KhumsSettlementTerms $terms): void
    {
        Gate::authorize('access-app');
        try {
            $assessment = DB::table('khums_assessments')->where('closure_id', $this->closedClosureId)->value('id');
            if ($assessment === null) {
                throw new FinanceInputException('Select an assessment first.');
            }
            $this->termsPreview = $terms->preview((int) $assessment);
            $current = $this->termsPreview['terms'];
            $this->termsPayableRial = $current['decision_id'] === null ? '' : $current['payable_due_exact_rial'];
            $this->termsRuleId = $current['rule_id'] ?? '';
            $this->termsCaseReference = $current['case_reference'] ?? '';
            $this->termsFacts = array_values($current['facts']);
            $this->termsApproved = false;
            $this->termsRevisionApproved = false;
            $this->termsRevisionReason = '';
            $this->resetErrorBag('settlementTerms');
        } catch (FinanceInputException|\LogicException $error) {
            $this->addError('settlementTerms', 'تعهد فعال و مستند سازگار لازم است؛ ابتدا وضعیت سال را بررسی کنید.');
        }
    }

    public function confirmSettlementTerms(KhumsSettlementTerms $terms): void
    {
        Gate::authorize('access-app');
        try {
            if ($this->termsPreview === null) {
                throw new FinanceInputException('Preview payment terms first.');
            }
            $facts = [];
            foreach ($terms->factLabels($this->termsRuleId) as $index => $label) {
                $facts[$label] = $this->termsFacts[$index] ?? '';
            }
            $assessment = DB::table('khums_assessments')->where('closure_id', $this->closedClosureId)->value('id');
            if ($assessment === null || (int) $assessment !== $this->termsPreview['assessment_id']) {
                throw new FinanceInputException('The selected assessment changed.');
            }
            $terms->confirm((int) $assessment, $this->termsPreview['hash'], DecimalInput::normalize($this->termsPayableRial),
                $this->termsRuleId, $facts, $this->termsCaseReference, $this->termsApproved,
                $this->termsRevisionApproved ? $this->termsPreview['terms']['decision_id'] : null, $this->termsRevisionReason);
            $this->termsPreview = null;
            $this->termsApproved = false;
            $this->termsRevisionApproved = false;
            $this->reconciliationPreview = null;
            $this->reconciliationApproved = false;
            $this->resetErrorBag('settlementTerms');
        } catch (FinanceInputException|\LogicException $error) {
            $this->addError('settlementTerms', 'توافق ثبت نشد؛ مبلغ موردی، قاعدهٔ معتبر، مستند، تأیید و تازگی پیش‌نمایش را بررسی کنید.');
        }
    }

    public function resolveBasis(KhumsBasisResolution $basis, RuleCatalog $catalog): void
    {
        Gate::authorize('access-app');
        if ($this->closedClosureId === null) {
            throw new FinanceInputException('Select a closed year.');
        }
        $rule = $catalog->requireActive('K01');
        $facts = [];
        foreach ($rule['required_facts'] as $index => $label) {
            $facts[$label] = $this->basisFacts[$index] ?? '';
        }
        $basis->confirm($this->closedClosureId, (int) $this->basisAccountId, 'K01', $facts, $this->basisApproved);
        $this->basisFacts = [];
        $this->basisApproved = false;
    }

    public function previewAmendment(KhumsPeriodAmendment $amendments): void
    {
        Gate::authorize('access-app');
        if ($this->closedClosureId === null || $this->savedPeriodReviewId === null) {
            throw new FinanceInputException('Select a closed period and a new saved review.');
        }
        $this->amendmentDiff = $amendments->preview($this->closedClosureId, $this->savedPeriodReviewId);
    }

    public function approveAmendment(KhumsPeriodAmendment $amendments): void
    {
        Gate::authorize('access-app');
        if ($this->amendmentDiff === null || $this->closedClosureId === null || $this->savedPeriodReviewId === null) {
            throw new FinanceInputException('Preview the amendment first.');
        }
        $this->closedClosureId = $amendments->approveWithLocalBackup(
            $this->closedClosureId, $this->savedPeriodReviewId, $this->amendmentDiff['diff_hash'],
            $this->amendmentReason, $this->amendmentApproved
        );
        $this->closedReport = app(KhumsPeriodClosure::class)->read($this->closedClosureId)['report'];
        $this->amendmentDiff = null;
        $this->amendmentApproved = false;
        $this->amendmentReason = '';
        $this->reconciliationPreview = null;
    }

    public function previewReconciliation(KhumsAmendmentReconciliation $reconciliation): void
    {
        Gate::authorize('access-app');
        if ($this->closedClosureId === null) {
            throw new FinanceInputException('Select an amended year.');
        }
        $this->reconciliationPreview = $reconciliation->preview($this->closedClosureId);
    }

    public function approveReconciliation(KhumsAmendmentReconciliation $reconciliation): void
    {
        Gate::authorize('access-app');
        if ($this->closedClosureId === null || $this->reconciliationPreview === null) {
            throw new FinanceInputException('Preview the payment reconciliation first.');
        }
        $reconciliation->approve($this->closedClosureId, $this->reconciliationPreview['hash'],
            $this->reconciliationReason, $this->reconciliationApproved);
        $this->reconciliationPreview = null;
        $this->reconciliationReason = '';
        $this->reconciliationApproved = false;
    }

    public function previewScheduleChange(KhumsPeriodCalendar $calendar): void
    {
        Gate::authorize('access-app');
        $this->schedulePreview = $calendar->previewChange($this->scheduleInteger($this->scheduleStartYear),
            $this->scheduleInteger($this->scheduleEndMonth), $this->scheduleInteger($this->scheduleEndDay), $this->scheduleClock());
    }

    public function approveScheduleChange(KhumsPeriodCalendar $calendar): void
    {
        Gate::authorize('access-app');
        if ($this->schedulePreview === null) {
            throw new FinanceInputException('Preview the transition first.');
        }
        $calendar->approveChange($this->scheduleInteger($this->scheduleStartYear), $this->scheduleInteger($this->scheduleEndMonth),
            $this->scheduleInteger($this->scheduleEndDay), $this->scheduleClock(), $this->schedulePreview['hash'],
            $this->scheduleReason, $this->scheduleApproved);
        $this->schedulePreview = null;
        $this->scheduleApproved = false;
        $this->scheduleReason = '';
    }

    private function scheduleInteger(string $value): int
    {
        $normal = DecimalInput::normalize($value);
        if (! preg_match('/^[0-9]{1,4}$/D', $normal)) {
            throw new InvalidArgumentException('Expected a Persian or Latin whole number.');
        }

        return (int) $normal;
    }

    private function scheduleClock(): string
    {
        return strtr($this->scheduleEndClock, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public function render(): View
    {
        Gate::authorize('access-app');

        $calendar = app(KhumsPeriodCalendar::class);
        $firstPeriod = $calendar->forYear(1405);
        $dueStatus = KhumsYearSchedule::dueStatus($firstPeriod, now('UTC')->format('Y-m-d\TH:i:s.u\Z'));
        $pendingPeriods = $calendar->pendingPeriods(now('UTC')->format('Y-m-d\TH:i:s.u\Z'));
        $assessment = $this->closedClosureId === null ? null : DB::table('khums_assessments')->where('closure_id', $this->closedClosureId)->first();
        $settlementTerms = null;
        $termsIssue = false;
        $termsLabels = [];
        try {
            if ($assessment !== null) {
                $settlementTerms = app(KhumsSettlementTerms::class)->forAssessment((int) $assessment->id);
            }
            if ($this->termsRuleId !== '') {
                $termsLabels = app(KhumsSettlementTerms::class)->factLabels($this->termsRuleId);
            }
        } catch (FinanceInputException|\LogicException $error) {
            $termsIssue = $assessment !== null && $settlementTerms === null;
        }

        return view('livewire.khums-workspace', [
            'firstPeriod' => $firstPeriod, 'dueStatus' => $dueStatus,
            'pendingPeriods' => $pendingPeriods,
            'savedReviews' => app(KhumsPeriodReview::class)->history(),
            'activeRules' => app(RuleCatalog::class)->active(),
            'settlementTerms' => $settlementTerms, 'settlementTermsIssue' => $termsIssue,
            'termsFactLabels' => $termsLabels,
            'termsRules' => array_values(array_filter(app(RuleCatalog::class)->active(),
                fn ($rule) => in_array(KhumsSettlementTerms::CAPABILITY, $rule['capabilities'] ?? [], true))),
            'closedPeriods' => DB::table('khums_period_closures')->where('active', true)
                ->orderByDesc('start_year')->get(['id', 'start_year', 'revision', 'due_exact_rial', 'dependency_stale'])
                ->map(fn ($row): array => (array) $row)->all(),
            'paymentAccounts' => DB::table('accounts')->where('class', 'asset')
                ->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])
                ->orderBy('id')->get(['id', 'name']),
            'basisCarryovers' => $this->closedClosureId === null ? [] : DB::table('khums_basis_carryovers')
                ->join('accounts', 'accounts.id', '=', 'khums_basis_carryovers.account_id')
                ->where('khums_basis_carryovers.closure_id', $this->closedClosureId)
                ->where('khums_basis_carryovers.status', 'settled_basis_review')
                ->orderBy('khums_basis_carryovers.id')
                ->get(['khums_basis_carryovers.account_id', 'accounts.name'])->all(),
            'reconciliationNeeded' => $this->closedClosureId !== null && DB::table('khums_assessments')
                ->where('closure_id', $this->closedClosureId)->where('status', 'reconciliation_required')->exists(),
        ]);
    }
}
