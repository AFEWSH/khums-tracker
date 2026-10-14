<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">دارایی‌ها · فاز ۴</p>
        <h1 class="text-3xl font-bold text-slate-950">دارایی‌های مقداری</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">مقدار، بهای دفتری و قیمت بازار جدا هستند. قیمت مفقود، ارزش صفر نیست؛ پایه مخمّس و بهای تاریخی افتتاحیه نیز از ارزش بازار استنتاج نمی‌شوند.</p>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-900">این صفحه برای بررسی با داده ساختگی است. فروش و تبدیل با FIFO اقتصادی محاسبه می‌شوند؛ این روش حکم شرعی مصرف سرمایه نیست. ورود داده واقعی تا تکمیل replay فاز ۵ توصیه نمی‌شود.</p>
    </section>
    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">ابزار جدید</h2>
        <form wire:submit="createInstrument" class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1 text-sm"><span>شناسه یکتا لاتین</span><input wire:model="instrumentCode" class="field w-full" dir="ltr" maxlength="64" required></label>
            <label class="space-y-1 text-sm"><span>نام ابزار</span><input wire:model="instrumentName" class="field w-full" maxlength="120" required></label>
            <label class="space-y-1 text-sm"><span>نوع</span><select wire:model="instrumentType" class="field w-full"><option value="stock">سهام</option><option value="fund">صندوق</option><option value="gold">طلا</option><option value="silver">نقره</option><option value="currency">ارز</option><option value="crypto">رمزارز</option><option value="other">سایر</option></select></label>
            <label class="space-y-1 text-sm"><span>رقم اعشار مقدار</span><input wire:model="instrumentScale" type="number" min="0" max="18" class="field w-full" required dir="ltr"></label>
            <label class="space-y-1 text-sm"><span>عیار از ۱۰۰۰۰، فقط برای فلز</span><input wire:model="instrumentFineness" type="number" min="1" max="10000" class="field w-full" dir="ltr"></label>
            @error('instrument') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
            <button class="button-secondary md:col-span-2" type="submit">ایجاد ابزار</button>
        </form>
    </section>
    @if (! $openingConfirmed)
        <section class="panel p-6"><p class="text-sm leading-7">برای ثبت خرید، ابتدا افتتاحیه آزمایشی را تأیید کنید.</p><a class="button-primary mt-4 inline-flex" href="{{ route('cash.setup') }}">راه‌اندازی</a></section>
    @else
        <section class="panel space-y-4 p-6">
            <h2 class="text-xl font-bold">محل نگهداری جدید</h2>
            <form wire:submit="createHolding" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>ابزار</span><select wire:model="holdingInstrumentId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($instruments as $instrument)<option value="{{ $instrument->id }}">{{ $instrument->name }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>نام محل نگهداری</span><input wire:model="holdingName" class="field w-full" maxlength="120" required></label>
                @error('holding') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button class="button-secondary md:col-span-2" type="submit">ایجاد محل</button>
            </form>
        </section>
        <section class="panel space-y-4 p-6">
            <h2 class="text-xl font-bold">ثبت خرید</h2>
            <form wire:submit="buy" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>دارایی و محل</span><select wire:model="buyHoldingId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($holdings as $holding)<option value="{{ $holding['account_id'] }}">{{ $holding['name'] }} · {{ $holding['instrument'] }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>حساب پرداخت</span><select wire:model="buyCashId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($cashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}">{{ $cashAccount->name }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>تاریخ شمسی</span><input wire:model="buyDate" class="field w-full" dir="ltr" inputmode="numeric" required></label>
                <label class="space-y-1 text-sm"><span>مقدار خرید</span><input wire:model="buyQuantity" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                <label class="space-y-1 text-sm"><span>بهای معامله (تومان، بدون کارمزد)</span><input wire:model="buyPriceToman" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                <label class="space-y-1 text-sm"><span>کارمزد مستقیم (تومان)</span><input wire:model="buyFeeToman" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                <label class="flex items-center gap-3 text-sm md:col-span-2"><input wire:model="buyAfterOpening" type="checkbox"><span>اگر معامله در روز افتتاحیه است، پس از لحظه افتتاحیه انجام شده است.</span></label>
                @error('buy') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button class="button-primary md:col-span-2" type="submit">ثبت خرید آزمایشی</button>
            </form>
        </section>
        <section class="panel space-y-4 p-6">
            <h2 class="text-xl font-bold">فروش یا تبدیل دارایی</h2>
            <p class="text-sm leading-7 text-slate-600">بهای اقتصادی خروج با FIFO محاسبه می‌شود. برای تبدیل، ارزش ریالی مورد توافق معامله و مقدار دارایی دریافتی را صریح وارد کنید؛ قیمت روز خودکار جای مبلغ قرارداد نمی‌نشیند. کارمزد تبدیل از حساب نقدی پرداخت و به بهای دارایی جدید افزوده می‌شود.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>دارایی خروجی</span><select wire:model="exitSourceId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($holdings as $holding)<option value="{{ $holding['account_id'] }}">{{ $holding['name'] }} · {{ $holding['instrument'] }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>تاریخ شمسی</span><input wire:model="exitDate" class="field w-full" dir="ltr" inputmode="numeric"></label>
                <label class="space-y-1 text-sm"><span>مقدار خروجی</span><input wire:model="exitQuantity" class="field w-full" dir="ltr" inputmode="decimal"></label>
                <label class="space-y-1 text-sm"><span>عوض ناخالص / ارزش قرارداد (تومان)</span><input wire:model="exitGrossToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
                <label class="space-y-1 text-sm"><span>کارمزد (تومان)</span><input wire:model="exitFeeToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
                <label class="space-y-1 text-sm"><span>حساب نقدی دریافت وجه فروش یا پرداخت کارمزد تبدیل</span><select wire:model="exitCashId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($cashAccounts as $cashAccount)<option value="{{ $cashAccount->id }}">{{ $cashAccount->name }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>دارایی دریافتی فقط برای تبدیل</span><select wire:model="exitTargetId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($holdings as $holding)<option value="{{ $holding['account_id'] }}">{{ $holding['name'] }} · {{ $holding['instrument'] }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>مقدار دریافتی فقط برای تبدیل</span><input wire:model="exitTargetQuantity" class="field w-full" dir="ltr" inputmode="decimal"></label>
                <label class="flex items-center gap-3 text-sm md:col-span-2"><input wire:model="exitAfterOpening" type="checkbox"><span>اگر در روز افتتاحیه است، عملیات بعد از افتتاحیه انجام شده است.</span></label>
                @error('exit') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-3 md:col-span-2"><button wire:click="sell" type="button" class="button-primary">ثبت فروش</button><button wire:click="convert" type="button" class="button-secondary">ثبت تبدیل</button></div>
            </div>
        </section>
    @endif
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">ثبت قیمت تاریخی</h2>
        <p class="text-sm text-slate-600">قیمت هر یک واحد ابزار، به ریال؛ منبع دستی و تاریخ لازم است. اصلاح همان روز نسخه جدید می‌سازد.</p>
        <form wire:submit="recordPrice" class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1 text-sm"><span>ابزار</span><select wire:model="priceInstrumentId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($instruments as $instrument)<option value="{{ $instrument->id }}">{{ $instrument->name }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>تاریخ شمسی</span><input wire:model="priceDate" class="field w-full" dir="ltr" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>ریال به‌ازای یک واحد</span><input wire:model="priceRialPerUnit" class="field w-full" dir="ltr" inputmode="decimal" required></label>
            <label class="space-y-1 text-sm"><span>منبع/یادداشت</span><input wire:model="priceSource" class="field w-full" maxlength="240" required></label>
            @error('price') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
            <button class="button-secondary md:col-span-2" type="submit">ذخیره قیمت</button>
        </form>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">نمای دارایی‌ها</h2>
        <label class="block max-w-sm space-y-1 text-sm"><span>تاریخ گزارش شمسی</span><input wire:model.live.debounce.500ms="reportDate" class="field w-full" dir="ltr" inputmode="numeric"></label>
        @error('reportDate') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        @if ($realized !== null)<p class="rounded-xl bg-slate-50 p-4 text-sm leading-7">سود تحقق‌یافته اقتصادی: <bdi>{{ $realized['gain_toman'] }}</bdi> تومان · زیان: <bdi>{{ $realized['loss_toman'] }}</bdi> تومان · کارمزد فروش: <bdi>{{ $realized['sale_fees_toman'] }}</bdi> تومان · خالص: <bdi class="font-bold">{{ $realized['net_toman'] }}</bdi> تومان</p>@endif
        @if ($holdings === []) <p class="text-sm text-slate-600">هنوز دارایی مقداری ثبت نشده است.</p> @endif
        <ul class="divide-y divide-slate-100">
            @foreach ($holdings as $holding)
                <li wire:key="holding-{{ $holding['account_id'] }}" class="py-4 text-sm leading-7"><strong>{{ $holding['name'] }} · {{ $holding['instrument'] }}</strong><p class="text-slate-600">مقدار: <bdi>{{ $holding['quantity'] }}</bdi> · بهای دفتری: <bdi>{{ $holding['book_toman'] }}</bdi> تومان · ارزش با قیمت ثبت‌شده: <bdi>{{ $holding['market_toman'] ?? 'نامعلوم' }}</bdi> تومان @if ($holding['price_date']) · تاریخ قیمت: {{ \App\Support\Dates\PersianDate::fromGregorian($holding['price_date']) }} @endif</p></li>
            @endforeach
        </ul>
    </section>
</div>
