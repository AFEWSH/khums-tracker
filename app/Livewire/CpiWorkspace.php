<?php

namespace App\Livewire;

use App\Actions\Finance\CpiCatalog;
use App\Actions\Finance\CpiCsvImport;
use App\Actions\Finance\EconomicPerformanceReport;
use App\Actions\Finance\FinanceInputException;
use App\Support\Dates\PersianDate;
use App\Support\Numbers\DecimalInput;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
#[Title('شاخص قیمت مصرف‌کننده')]
class CpiWorkspace extends Component
{
    use WithFileUploads;

    public $csvFile;

    public string $previewDigest = '';

    public string $coverage = 'national_all_households';

    public string $baseYear = '1400';

    public string $methodologyVersion = '';

    public string $seriesSourceUrl = '';

    public string $seriesId = '';

    public string $year = '';

    public string $month = '';

    public string $level = '';

    public string $pointSourceUrl = '';

    public string $publicationDate = '';

    public string $revisionReason = '';

    public string $reportStart = '1405-05-20';

    public string $reportEnd = '';

    public string $reportSeriesId = '';

    public string $reportScope = 'personal_net_worth';

    public array $portfolioAccountIds = [];

    public ?array $economicReport = null;

    public function mount(): void
    {
        Gate::authorize('access-app');
        $this->reportEnd = PersianDate::fromGregorian(now()->addDay()->toDateString());
    }

    public function addSeries(CpiCatalog $catalog): void
    {
        Gate::authorize('access-app');
        try {
            $id = $catalog->createSeries($this->coverage, $this->integer($this->baseYear), $this->methodologyVersion, $this->seriesSourceUrl);
            $this->seriesId = (string) $id;
            $this->reset('methodologyVersion', 'seriesSourceUrl');
            session()->flash('success', 'سری شاخص به‌صورت تأییدنشده ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('series', $error->getMessage());
        }
    }

    public function addPoint(CpiCatalog $catalog): void
    {
        Gate::authorize('access-app');
        try {
            $catalog->record((int) $this->seriesId, $this->integer($this->year), $this->integer($this->month),
                $this->level, $this->pointSourceUrl, $this->publicationDate ?: null, $this->revisionReason ?: null);
            $this->reset('level', 'pointSourceUrl', 'publicationDate', 'revisionReason');
            session()->flash('success', 'مشاهدهٔ ماهانه نسخه‌دار ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('point', $error->getMessage());
        }
    }

    public function render(CpiCatalog $catalog): View
    {
        Gate::authorize('access-app');

        return view('livewire.cpi-workspace', [
            'series' => $catalog->series(),
            'portfolioAccounts' => DB::table('accounts')->where('class', 'asset')
                ->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash', 'holding'])
                ->orderBy('name')->get(['id', 'name', 'subtype']),
            'points' => ctype_digit($this->seriesId) && (int) $this->seriesId > 0
                ? $catalog->latestPoints((int) $this->seriesId) : [],
            'csvPlan' => ($plan = session()->get('cpi_csv_plan')) && ($plan['digest'] ?? '') === $this->previewDigest ? $plan : null,
        ]);
    }

    public function previewCsv(CpiCsvImport $import): void
    {
        Gate::authorize('access-app');
        session()->forget('cpi_csv_plan');
        $this->previewDigest = '';
        $this->validate(['csvFile' => 'required|file|max:256|extensions:csv,txt']);
        try {
            $bytes = file_get_contents($this->csvFile->getRealPath());
            if ($bytes === false) {
                throw new InvalidArgumentException('Cannot read CPI CSV.');
            }
            $plan = $import->preview($bytes, (int) $this->seriesId);
            session()->put('cpi_csv_plan', $plan);
            $this->previewDigest = $plan['digest'];
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('csvFile', $error->getMessage());
        }
    }

    public function confirmCsv(CpiCsvImport $import): void
    {
        Gate::authorize('access-app');
        $plan = session()->get('cpi_csv_plan');
        if (! is_array($plan) || $this->previewDigest === '' || ! hash_equals($plan['digest'] ?? '', $this->previewDigest)) {
            $this->addError('csvFile', 'پیش‌نمایش معتبر نیست.');

            return;
        }
        try {
            $count = $import->commit($plan);
            session()->forget('cpi_csv_plan');
            $this->previewDigest = '';
            $this->csvFile = null;
            session()->flash('success', $count.' ردیف شاخص ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException|MathException|\RuntimeException $error) {
            $this->addError('csvFile', $error->getMessage());
        }
    }

    public function runReport(EconomicPerformanceReport $report): void
    {
        Gate::authorize('access-app');
        $this->economicReport = null;
        try {
            if (! in_array($this->reportScope, ['personal_net_worth', 'investment_portfolio'], true)) {
                throw new InvalidArgumentException('دامنه گزارش معتبر نیست.');
            }
            $accountIds = $this->reportScope === 'investment_portfolio'
                ? array_map(fn ($id): int => $this->integer((string) $id), $this->portfolioAccountIds) : null;
            $this->economicReport = $report->between(
                PersianDate::parseInput($this->reportStart),
                PersianDate::parseInput($this->reportEnd),
                $this->integer($this->reportSeriesId),
                $accountIds,
            );
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('report', $error->getMessage());
        }
    }

    private function integer(string $input): int
    {
        $normal = DecimalInput::normalize($input);
        if (! preg_match('/^[0-9]{1,4}$/D', $normal)) {
            throw new InvalidArgumentException('سال و ماه باید عدد صحیح باشند.');
        }

        return (int) $normal;
    }
}
