<?php

namespace App\Livewire;

use App\Actions\Finance\CsvCashImport;
use App\Actions\Finance\FinanceInputException;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
#[Title('ورود گروهی CSV')]
class CashCsvImport extends Component
{
    use WithFileUploads;

    public $csvFile;

    public string $unit = 'toman';

    public string $calendar = 'persian';

    public array $mapping = [
        'reference' => 'reference', 'type' => 'type', 'date' => 'date', 'amount' => 'amount',
        'source' => 'source', 'target' => 'target', 'category' => 'category',
        'reason' => 'reason', 'after_opening' => 'after_opening',
    ];

    public string $previewDigest = '';

    public bool $warningsAcknowledged = false;

    public function mount(): void
    {
        Gate::authorize('access-app');
    }

    public function preview(CsvCashImport $import): void
    {
        Gate::authorize('access-app');
        session()->forget('cash_csv_plan');
        $this->previewDigest = '';
        $this->warningsAcknowledged = false;
        $this->validate([
            'csvFile' => 'required|file|max:256|extensions:csv,txt',
            'unit' => 'required|in:toman,rial', 'calendar' => 'required|in:persian,gregorian',
            'mapping.*' => 'nullable|string|max:120',
        ]);
        try {
            $bytes = file_get_contents($this->csvFile->getRealPath());
            if ($bytes === false) {
                throw new InvalidArgumentException('Cannot read uploaded CSV.');
            }
            $plan = $import->preview($bytes, $this->mapping, $this->unit, $this->calendar);
            session()->put('cash_csv_plan', $plan);
            $this->previewDigest = $plan['digest'];
            $this->warningsAcknowledged = false;
            session()->flash('success', 'پیش‌نمایش آماده شد؛ هنوز هیچ عملیاتی ثبت نشده است.');
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('csvFile', $error->getMessage());
        }
    }

    public function confirm(CsvCashImport $import): void
    {
        Gate::authorize('access-app');
        $plan = session()->get('cash_csv_plan');
        if (! is_array($plan) || $this->previewDigest === '' || ! hash_equals($plan['digest'] ?? '', $this->previewDigest)) {
            $this->addError('csvFile', 'پیش‌نمایش معتبر نیست؛ فایل را دوباره بررسی کنید.');

            return;
        }
        try {
            $result = $import->commit($plan, $this->warningsAcknowledged);
            session()->forget('cash_csv_plan');
            $this->previewDigest = '';
            $this->csvFile = null;
            $this->warningsAcknowledged = false;
            session()->flash('success', $result['posted'].' عملیات ثبت شد؛ '.$result['repeated'].' مرجع تکراریِ یکسان بی‌اثر ماند.');
        } catch (InvalidArgumentException|FinanceInputException|MathException $error) {
            $this->addError('csvFile', $error->getMessage());
        }
    }

    public function render(): View
    {
        Gate::authorize('access-app');
        $plan = session()->get('cash_csv_plan');

        return view('livewire.cash-csv-import', [
            'plan' => is_array($plan) && ($plan['digest'] ?? '') === $this->previewDigest ? $plan : null,
        ]);
    }
}
