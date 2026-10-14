<?php

namespace App\Livewire;

use App\Actions\Finance\AssetFacts;
use App\Actions\Finance\CashFlowReport;
use App\Actions\Finance\CoverageConfirmation;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\ForeignObligationSettlement;
use App\Actions\Finance\OpeningAmendment;
use App\Support\Dates\PersianDate;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('مرور و تکمیل داده')]
class FinanceReview extends Component
{
    public string $throughDate = '';

    public bool $reconciled = false;

    public string $coverageKey = '';

    public int $coverageSeenRevision = 0;

    public string $flowStart = '1405/05/20';

    public string $flowEnd = '';

    public string $openingItemId = '';

    public string $correctedToman = '';

    public string $correctionReason = '';

    public ?int $pendingCaseId = null;

    public string $pendingCaseKind = '';

    public string $assetOpeningItemId = '';

    public string $correctedAssetToman = '';

    public string $correctedAssetQuantity = '';

    public string $correctedProtectedQuantity = '0';

    public string $correctedProtectedBasisToman = '';

    public string $correctedOriginEvidence = 'unknown';

    public int $amendSeenRevision = 0;

    public string $amendKey = '';

    public string $factLotId = '';

    public string $factKind = 'acquisition_date';

    public string $factValue = '';

    public string $factValidFrom = '';

    public string $factEvidence = '';

    public string $fxObligationId = '';

    public string $fxCashId = '';

    public string $fxQuantity = '';

    public string $fxCashToman = '';

    public string $fxDate = '';

    public string $fxReason = '';

    public bool $fxAfterOpening = false;

    public string $fxKey = '';

    public function mount(): void
    {
        Gate::authorize('access-app');
        $today = PersianDate::fromGregorian(now('Asia/Tehran')->format('Y-m-d'));
        $this->throughDate = $today;
        $this->flowEnd = PersianDate::fromGregorian(now('Asia/Tehran')->addDay()->format('Y-m-d'));
        $this->coverageKey = (string) Str::uuid();
        $this->amendKey = (string) Str::uuid();
        $this->fxDate = $today;
        $this->fxKey = (string) Str::uuid();
        $this->coverageSeenRevision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
    }

    public function confirmCoverage(CoverageConfirmation $coverage): void
    {
        try {
            $date = PersianDate::parseInput($this->throughDate);
            $coverage->confirm($date, $this->coverageSeenRevision, $this->coverageKey, $this->reconciled);
            $this->coverageKey = (string) Str::uuid();
            $this->coverageSeenRevision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            $this->reconciled = false;
            session()->flash('success', 'پوشش داده با تأیید صریح ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('coverage', $error->getMessage());
        }
    }

    public function proposeCorrection(OpeningAmendment $amendment): void
    {
        try {
            $this->pendingCaseId = $amendment->proposeCashAmount((int) $this->openingItemId, $this->correctedToman, $this->correctionReason);
            $this->pendingCaseKind = 'cash';
            $this->amendSeenRevision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            session()->flash('success', 'پیشنهاد اصلاح ثبت شد؛ برای اثرگذاری باید جداگانه تأیید شود.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('correction', $error->getMessage());
        } catch (MathException) {
            $this->addError('correction', 'مبلغ با دقت مجاز ریال سازگار نیست.');
        }
    }

    public function proposeAssetCorrection(OpeningAmendment $amendment): void
    {
        try {
            $this->pendingCaseId = $amendment->proposeAssetValue((int) $this->assetOpeningItemId, $this->correctedAssetToman, $this->correctionReason);
            $this->pendingCaseKind = 'asset';
            $this->amendSeenRevision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            session()->flash('success', 'پیشنهاد اصلاح ارزش دارایی ذخیره شد؛ تأیید جدا لازم است.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('correction', $error->getMessage());
        } catch (MathException) {
            $this->addError('correction', 'مبلغ با دقت مجاز ریال سازگار نیست.');
        }
    }

    public function proposeAssetQuantityCorrection(OpeningAmendment $amendment): void
    {
        try {
            $this->pendingCaseId = $amendment->proposeAssetQuantityOrigin(
                (int) $this->assetOpeningItemId, $this->correctedAssetQuantity,
                $this->correctedProtectedQuantity,
                $this->correctedProtectedBasisToman === '' ? null : $this->correctedProtectedBasisToman,
                $this->correctedOriginEvidence, $this->correctionReason,
            );
            $this->pendingCaseKind = 'asset_quantity_origin';
            $this->amendSeenRevision = (int) DB::table('financial_state')->where('id', 1)->value('revision');
            session()->flash('success', 'پیشنهاد اصلاح مقدار و منشأ ذخیره شد؛ تأیید جدا لازم است.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('correction', $error->getMessage());
        } catch (MathException) {
            $this->addError('correction', 'مقدار یا پایه با دقت مجاز سازگار نیست.');
        }
    }

    public function approveCorrection(OpeningAmendment $amendment): void
    {
        try {
            if ($this->pendingCaseId === null) {
                throw new InvalidArgumentException('پیشنهاد اصلاحی برای تأیید انتخاب نشده است.');
            }
            if ($this->pendingCaseKind === 'asset_quantity_origin') {
                $amendment->approveAssetQuantityOrigin($this->pendingCaseId, $this->amendSeenRevision, $this->amendKey);
            } elseif ($this->pendingCaseKind === 'asset') {
                $amendment->approveAssetValue($this->pendingCaseId, $this->amendSeenRevision, $this->amendKey);
            } else {
                $amendment->approve($this->pendingCaseId, $this->amendSeenRevision, $this->amendKey);
            }
            $this->amendKey = (string) Str::uuid();
            $this->pendingCaseId = null;
            $this->pendingCaseKind = '';
            session()->flash('success', 'اصلاحیه افتتاحیه ثبت و بازسازی شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('correction', $error->getMessage());
        }
    }

    public function recordFact(AssetFacts $facts): void
    {
        try {
            $value = $this->factKind === 'acquisition_date' ? PersianDate::parseInput($this->factValue) : $this->factValue;
            $facts->record((int) $this->factLotId, $this->factKind, $value,
                $this->factValidFrom === '' ? null : PersianDate::parseInput($this->factValidFrom), $this->factEvidence);
            $this->reset('factValue', 'factValidFrom', 'factEvidence');
            session()->flash('success', 'واقعیت پیش از افتتاحیه ثبت شد؛ هیچ معامله‌ای ساخته نشد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('fact', $error->getMessage());
        }
    }

    public function settleForeignObligation(ForeignObligationSettlement $settlement): void
    {
        try {
            $settlement->post([
                'obligation_id' => (int) $this->fxObligationId, 'cash_id' => (int) $this->fxCashId,
                'date' => PersianDate::parseInput($this->fxDate), 'quantity' => $this->fxQuantity,
                'cash_toman' => $this->fxCashToman, 'reason' => $this->fxReason,
                'after_opening' => $this->fxAfterOpening, 'idempotency_key' => $this->fxKey,
            ]);
            $this->fxKey = (string) Str::uuid();
            $this->reset('fxQuantity', 'fxCashToman', 'fxReason', 'fxAfterOpening');
            session()->flash('success', 'تسویه اصل تعهد ارزی و نتیجه تسعیر جداگانه ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('fx', $error->getMessage());
        } catch (MathException) {
            $this->addError('fx', 'مقدار یا مبلغ با دقت مجاز سازگار نیست.');
        }
    }

    public function render(CashFlowReport $flow, CoverageConfirmation $coverage): View
    {
        Gate::authorize('access-app');
        $report = null;
        $preview = null;
        try {
            $report = $flow->between(PersianDate::parseInput($this->flowStart), PersianDate::parseInput($this->flowEnd));
        } catch (InvalidArgumentException) {
            // Incomplete date input is displayed as a form error after submission.
        }
        try {
            $preview = $coverage->preview(PersianDate::parseInput($this->throughDate));
        } catch (InvalidArgumentException|FinanceInputException) {
            // Opening or date is not yet ready for confirmation.
        }

        $foreignObligations = DB::table('foreign_obligations')
            ->join('accounts', 'accounts.id', '=', 'foreign_obligations.account_id')
            ->join('instruments', 'instruments.id', '=', 'foreign_obligations.instrument_id')
            ->orderBy('accounts.id')->get(['accounts.id', 'accounts.name', 'accounts.subtype',
                'instruments.name as instrument_name', 'instruments.quantity_scale'])
            ->map(function ($row): array {
                $atoms = BigInteger::zero();
                foreach (DB::table('quantity_entries')->where('account_id', $row->id)->pluck('signed_atoms') as $entry) {
                    $atoms = $atoms->plus((string) $entry);
                }

                return ['id' => (int) $row->id, 'name' => $row->name, 'kind' => $row->subtype,
                    'instrument' => $row->instrument_name,
                    'remaining' => (string) BigDecimal::ofUnscaledValue($atoms, (int) $row->quantity_scale)->strippedOfTrailingZeros()];
            })->all();

        return view('livewire.finance-review', [
            'report' => $report, 'preview' => $preview, 'latestCoverage' => $coverage->latest(),
            'openingItems' => DB::table('opening_items')->orderBy('id')->get(['id', 'name', 'kind', 'amount_rial']),
            'assetOpeningItems' => DB::table('asset_opening_items')->orderBy('id')->get(['id', 'name']),
            'openingLots' => DB::table('lots')->where('acquisition_method', 'opening')->orderBy('id')->get(['id', 'instrument_id']),
            'foreignObligations' => $foreignObligations,
            'cashAccounts' => DB::table('accounts')->where('class', 'asset')
                ->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
