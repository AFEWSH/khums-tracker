<?php

namespace App\Livewire;

use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\KhumsAmendmentReconciliation;
use App\Actions\Finance\KhumsBasisResolution;
use App\Actions\Finance\KhumsComponentDecision;
use App\Actions\Finance\KhumsDueDrafts;
use App\Actions\Finance\KhumsPeriodAmendment;
use App\Actions\Finance\KhumsPeriodCalendar;
use App\Actions\Finance\KhumsPeriodClosure;
use App\Actions\Finance\KhumsPeriodReport;
use App\Actions\Finance\KhumsPeriodReview;
use App\Actions\Finance\KhumsSettlement;
use App\Actions\Finance\KhumsSimulation;
use App\Support\Dates\PersianDate;
use App\Support\Khums\KhumsYearSchedule;
use App\Support\Khums\RuleCatalog;
use App\Support\Numbers\DecimalInput;
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

    public ?int $closedClosureId = null;

    public ?array $closedReport = null;

    public string $paymentAccountId = '';

    public string $paymentDate = '';

    public string $paymentAmountRial = '';

    public string $paymentMethod = 'from_same_asset';

    public string $paymentEvidence = '';

    public string $paymentKey = '';

    public bool $paymentApproved = false;

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

        return view('livewire.khums-workspace', [
            'firstPeriod' => $firstPeriod, 'dueStatus' => $dueStatus,
            'pendingPeriods' => $pendingPeriods,
            'savedReviews' => app(KhumsPeriodReview::class)->history(),
            'activeRules' => app(RuleCatalog::class)->active(),
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
