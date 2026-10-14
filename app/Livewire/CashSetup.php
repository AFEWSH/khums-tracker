<?php

namespace App\Livewire;

use App\Actions\Finance\AssetCatalog;
use App\Actions\Finance\FinanceInputException;
use App\Actions\Finance\OpeningSetup;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('راه‌اندازی نقدی')]
class CashSetup extends Component
{
    public bool $previousKhumsSettled = false;

    public string $kind = 'bank';

    public string $name = '';

    public string $amountToman = '';

    public string $protectedToman = '0';

    public string $evidence = 'unknown';

    public int $seenRevision = 0;

    public string $confirmKey = '';

    public bool $confirmNoItems = false;

    public string $instrumentCode = '';

    public string $instrumentName = '';

    public string $instrumentType = 'stock';

    public int $instrumentScale = 0;

    public ?int $instrumentFineness = null;

    public string $assetInstrumentId = '';

    public string $assetName = '';

    public string $assetQuantity = '';

    public string $assetValueToman = '';

    public string $assetProtectedQuantity = '0';

    public string $assetProtectedBasisToman = '';

    public string $assetEvidence = 'unknown';

    public string $foreignInstrumentId = '';

    public string $foreignKind = 'receivable';

    public string $foreignName = '';

    public string $foreignPrincipal = '';

    public string $foreignValueToman = '';

    public function mount(OpeningSetup $setup): void
    {
        Gate::authorize('access-app');
        $session = $setup->session();
        $this->previousKhumsSettled = (bool) $session->previous_khums_settled;
        $this->seenRevision = (int) $session->revision;
        $this->confirmKey = (string) Str::uuid();
    }

    public function saveSettings(OpeningSetup $setup): void
    {
        $setup->saveSettings($this->previousKhumsSettled);
        $this->seenRevision = (int) $setup->session()->revision;
        session()->flash('success', 'تنظیمات راه‌اندازی ذخیره شد.');
    }

    public function addItem(OpeningSetup $setup): void
    {
        $this->validate([
            'kind' => 'required|in:bank,cash,broker_cash,wallet_cash,receivable,payable',
            'name' => 'required|string|max:120',
            'amountToman' => 'required|string|max:80',
            'protectedToman' => 'required|string|max:80',
            'evidence' => 'required|in:unknown,user_declared',
        ]);
        try {
            $setup->addItem($this->kind, $this->name, $this->amountToman, $this->protectedToman, $this->evidence);
            $this->seenRevision = (int) $setup->session()->revision;
            $this->reset('name', 'amountToman');
            $this->protectedToman = '0';
            $this->evidence = 'unknown';
            session()->flash('success', 'قلم افتتاحیه ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('amountToman', $error->getMessage());
        }
    }

    public function removeItem(int $id, OpeningSetup $setup): void
    {
        $setup->removeDraftItem($id);
        $this->seenRevision = (int) $setup->session()->revision;
    }

    public function createInstrument(AssetCatalog $catalog): void
    {
        try {
            $catalog->createInstrument($this->instrumentCode, $this->instrumentName, $this->instrumentType, $this->instrumentScale, $this->instrumentFineness);
            $this->reset('instrumentCode', 'instrumentName');
            session()->flash('success', 'ابزار دارایی ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('instrument', $error->getMessage());
        }
    }

    public function addAssetItem(AssetCatalog $catalog, OpeningSetup $setup): void
    {
        try {
            $catalog->addOpeningItem((int) $this->assetInstrumentId, $this->assetName, $this->assetQuantity,
                $this->assetValueToman, $this->assetProtectedQuantity,
                $this->assetProtectedBasisToman, $this->assetEvidence);
            $this->seenRevision = (int) $setup->session()->revision;
            $this->reset('assetName', 'assetQuantity', 'assetValueToman', 'assetProtectedBasisToman');
            $this->assetProtectedQuantity = '0';
            $this->assetEvidence = 'unknown';
            session()->flash('success', 'دارایی افتتاحیه ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('assetItem', $error->getMessage());
        } catch (MathException) {
            $this->addError('assetItem', 'مقدار یا مبلغ با دقت مجاز ابزار سازگار نیست.');
        }
    }

    public function removeAssetItem(int $id, AssetCatalog $catalog, OpeningSetup $setup): void
    {
        $catalog->removeOpeningItem($id);
        $this->seenRevision = (int) $setup->session()->revision;
    }

    public function addForeignObligation(AssetCatalog $catalog, OpeningSetup $setup): void
    {
        try {
            $catalog->addForeignObligationOpening((int) $this->foreignInstrumentId, $this->foreignKind,
                $this->foreignName, $this->foreignPrincipal, $this->foreignValueToman);
            $this->seenRevision = (int) $setup->session()->revision;
            $this->reset('foreignName', 'foreignPrincipal', 'foreignValueToman');
            session()->flash('success', 'تعهد ارزی افتتاحیه ذخیره شد.');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('foreign', $error->getMessage());
        } catch (MathException) {
            $this->addError('foreign', 'مقدار یا مبلغ با دقت مجاز ابزار سازگار نیست.');
        }
    }

    public function removeForeignObligation(int $id, AssetCatalog $catalog, OpeningSetup $setup): void
    {
        $catalog->removeForeignObligationOpening($id);
        $this->seenRevision = (int) $setup->session()->revision;
    }

    public function confirm(OpeningSetup $setup): void
    {
        try {
            $setup->confirm($this->seenRevision, (int) auth()->id(), $this->confirmKey, $this->confirmNoItems);
            $this->redirectRoute('cash.workspace');
        } catch (InvalidArgumentException|FinanceInputException $error) {
            $this->addError('confirm', $error->getMessage());
        }
    }

    public function render(OpeningSetup $setup, AssetCatalog $catalog): View
    {
        Gate::authorize('access-app');

        return view('livewire.cash-setup', [
            'setup' => $setup->session(),
            'items' => $setup->draftItems(),
            'summary' => $setup->draftSummary(),
            'instruments' => $catalog->instruments(),
            'assetItems' => $catalog->openingItems(),
            'foreignItems' => $catalog->foreignObligationItems(),
        ]);
    }
}
