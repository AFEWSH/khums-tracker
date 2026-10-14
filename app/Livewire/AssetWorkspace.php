<?php

namespace App\Livewire;

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\AssetOverview;
use App\Actions\Finance\BackdatedPosting;
use App\Actions\Finance\FinanceInputException;
use App\Support\Dates\PersianDate;
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
#[Title('دارایی‌ها')]
class AssetWorkspace extends Component
{
    public string $instrumentCode = '';

    public string $instrumentName = '';

    public string $instrumentType = 'stock';

    public int $instrumentScale = 0;

    public ?int $instrumentFineness = null;

    public string $holdingInstrumentId = '';

    public string $holdingName = '';

    public string $buyHoldingId = '';

    public string $buyCashId = '';

    public string $buyDate = '';

    public string $buyQuantity = '';

    public string $buyPriceToman = '';

    public string $buyFeeToman = '0';

    public bool $buyAfterOpening = false;

    public string $buyKey = '';

    public string $priceInstrumentId = '';

    public string $priceDate = '';

    public string $priceRialPerUnit = '';

    public string $priceSource = '';

    public string $reportDate = '';

    public string $exitSourceId = '';

    public string $exitTargetId = '';

    public string $exitCashId = '';

    public string $exitDate = '';

    public string $exitQuantity = '';

    public string $exitTargetQuantity = '';

    public string $exitGrossToman = '';

    public string $exitFeeToman = '0';

    public bool $exitAfterOpening = false;

    public string $exitKey = '';

    public function mount(): void
    {
        Gate::authorize('access-app');
        $this->buyKey = (string) Str::uuid();
        $this->buyDate = PersianDate::fromGregorian(now()->toDateString());
        $this->priceDate = $this->buyDate;
        $this->reportDate = $this->buyDate;
        $this->exitDate = $this->buyDate;
        $this->exitKey = (string) Str::uuid();
    }

    public function createHolding(AssetCatalog $catalog): void
    {
        try {
            $catalog->createHolding((int) $this->holdingInstrumentId, $this->holdingName);
            $this->holdingName = '';
            session()->flash('success', 'محل نگهداری ساخته شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('holding', $error->getMessage());
        }
    }

    public function createInstrument(AssetCatalog $catalog): void
    {
        try {
            $catalog->createInstrument($this->instrumentCode, $this->instrumentName, $this->instrumentType, $this->instrumentScale, $this->instrumentFineness);
            $this->reset('instrumentCode', 'instrumentName');
            session()->flash('success', 'ابزار دارایی ایجاد شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('instrument', $error->getMessage());
        }
    }

    public function buy(BackdatedPosting $posting): void
    {
        try {
            $posting->buy([
                'account_id' => (int) $this->buyHoldingId, 'cash_account_id' => (int) $this->buyCashId,
                'date' => PersianDate::parseInput($this->buyDate), 'quantity' => $this->buyQuantity,
                'price_toman' => $this->buyPriceToman, 'fee_toman' => $this->buyFeeToman,
                'after_opening' => $this->buyAfterOpening, 'idempotency_key' => $this->buyKey,
            ]);
            $this->buyKey = (string) Str::uuid();
            $this->reset('buyQuantity', 'buyPriceToman');
            $this->buyFeeToman = '0';
            session()->flash('success', 'خرید ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('buy', $error->getMessage());
        } catch (MathException) {
            $this->addError('buy', 'مقدار یا مبلغ با دقت مجاز سازگار نیست.');
        }
    }

    public function recordPrice(AssetCatalog $catalog): void
    {
        try {
            $catalog->recordPrice((int) $this->priceInstrumentId, PersianDate::parseInput($this->priceDate), $this->priceRialPerUnit, $this->priceSource);
            $this->reset('priceRialPerUnit', 'priceSource');
            session()->flash('success', 'قیمت نسخه‌دار ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('price', $error->getMessage());
        } catch (MathException) {
            $this->addError('price', 'قیمت با دقت مجاز سازگار نیست.');
        }
    }

    public function sell(BackdatedPosting $posting): void
    {
        $this->postExit($posting, false);
    }

    public function convert(BackdatedPosting $posting): void
    {
        $this->postExit($posting, true);
    }

    private function postExit(BackdatedPosting $posting, bool $conversion): void
    {
        try {
            $input = [
                'source_id' => (int) $this->exitSourceId, 'cash_id' => $this->exitCashId === '' ? null : (int) $this->exitCashId,
                'date' => PersianDate::parseInput($this->exitDate), 'quantity' => $this->exitQuantity,
                'gross_toman' => $this->exitGrossToman, 'fee_toman' => $this->exitFeeToman,
                'after_opening' => $this->exitAfterOpening, 'idempotency_key' => $this->exitKey,
            ];
            if ($conversion) {
                $input['target_id'] = (int) $this->exitTargetId;
                $input['target_quantity'] = $this->exitTargetQuantity;
                $posting->convert($input);
            } else {
                $posting->sell($input);
            }
            $this->exitKey = (string) Str::uuid();
            $this->reset('exitQuantity', 'exitTargetQuantity', 'exitGrossToman');
            $this->exitFeeToman = '0';
            session()->flash('success', $conversion ? 'تبدیل ثبت شد.' : 'فروش ثبت شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('exit', $error->getMessage());
        } catch (MathException) {
            $this->addError('exit', 'مقدار یا مبلغ با دقت مجاز سازگار نیست.');
        }
    }

    public function render(AssetCatalog $catalog, AssetOverview $overview): View
    {
        Gate::authorize('access-app');
        $asOf = null;
        try {
            $asOf = PersianDate::parseInput($this->reportDate);
        } catch (InvalidArgumentException) {
            $this->addError('reportDate', 'تاریخ گزارش شمسی معتبر وارد کنید.');
        }

        return view('livewire.asset-workspace', [
            'openingConfirmed' => DB::table('financial_state')->where('id', 1)->value('opening_confirmed_at') !== null,
            'instruments' => $catalog->instruments(),
            'holdings' => $asOf === null ? [] : $overview->holdings($asOf),
            'realized' => $asOf === null ? null : $overview->realizedResult($asOf),
            'cashAccounts' => DB::table('accounts')->where('class', 'asset')
                ->whereIn('subtype', ['bank', 'cash', 'broker_cash', 'wallet_cash'])->whereNull('archived_at')->orderBy('name')->get(),
        ]);
    }
}
