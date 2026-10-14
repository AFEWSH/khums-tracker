<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">مرور و تکمیل · فاز ۵</p>
        <h1 class="text-3xl font-bold text-slate-950">پوشش داده و گزارش جریان نقدی</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">ثبت معامله گذشته با بازسازی انجام می‌شود؛ تأیید پوشش یعنی شما ورود معاملات و تطبیق مانده‌ها را تا تاریخ مشخص بررسی کرده‌اید. این تأیید به معنی حل ابهام‌های شرعی یا بستن سال نیست.</p>
    </section>
    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">گزارش جریان نقدی</h2>
        <p class="text-sm leading-7 text-slate-600">بازه نیمه‌باز است؛ تاریخ پایان در گزارش وارد نمی‌شود. افتتاحیه مانده آغاز است و انتقال میان حساب‌های نقدی جریان خالص صفر دارد.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>شروع شمسی</span><input wire:model.live.debounce.500ms="flowStart" class="field w-full" dir="ltr" inputmode="numeric"></label>
            <label class="space-y-1 text-sm"><span>پایان شمسی، غیرشامل</span><input wire:model.live.debounce.500ms="flowEnd" class="field w-full" dir="ltr" inputmode="numeric"></label>
        </div>
        @if ($report !== null)
            <div class="grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-4"><p>مانده آغاز: <bdi>{{ $report['beginning_toman'] }}</bdi> تومان</p><p>ورودی: <bdi>{{ $report['inflow_toman'] }}</bdi> تومان</p><p>خروجی: <bdi>{{ $report['outflow_toman'] }}</bdi> تومان</p><p>مانده پایان: <bdi class="font-bold">{{ $report['ending_toman'] }}</bdi> تومان</p></div>
            <p class="text-sm {{ $report['coverage_complete'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $report['coverage_complete'] ? 'پوشش داده برای این بازه تأیید شده است.' : 'پوشش داده این بازه هنوز تأیید نشده؛ جمع‌ها موقت‌اند.' }}</p>
            <ul class="divide-y divide-slate-100">@foreach ($report['rows'] as $row)<li wire:key="flow-{{ $row['operation_id'] }}" class="flex flex-wrap justify-between gap-3 py-2 text-sm"><span>{{ \App\Support\Dates\PersianDate::fromGregorian($row['date']) }} · {{ $row['type'] }}</span><bdi>{{ $row['net_toman'] }} تومان</bdi></li>@endforeach</ul>
        @endif
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">تأیید پوشش داده</h2>
        @if ($latestCoverage)<p class="text-sm text-slate-600">آخرین پوشش تأییدشده: {{ \App\Support\Dates\PersianDate::fromGregorian($latestCoverage['through_date']) }}</p>@endif
        <label class="block max-w-sm space-y-1 text-sm"><span>تا تاریخ شمسی، شامل همان روز</span><input wire:model.live.debounce.500ms="throughDate" class="field w-full" dir="ltr" inputmode="numeric"></label>
        @if ($preview)<p class="text-sm text-slate-600">نسخه داده: {{ $preview['revision'] }} · تعداد عملیات تا این روز: {{ $preview['operation_count'] }}</p>@endif
        @if ($preview && $preview['revision'] !== $coverageSeenRevision)<p class="text-sm text-amber-800">داده‌ها از زمان ورود به این صفحه تغییر کرده‌اند؛ برای تأیید نسخه تازه صفحه را دوباره باز کنید.</p>@endif
        <label class="flex items-start gap-3 text-sm leading-7"><input wire:model="reconciled" type="checkbox" class="mt-2"><span>تأیید می‌کنم معاملات و مانده‌های دوره تا این تاریخ را بررسی و تطبیق کرده‌ام.</span></label>
        @error('coverage') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        <button wire:click="confirmCoverage" type="button" class="button-primary">ثبت تأیید پوشش</button>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">اصلاح مانده ریالی افتتاحیه</h2>
        <p class="text-sm leading-7 text-slate-600">اصلاحیه، سند افتتاحیه اصلی را تغییر نمی‌دهد. پیشنهاد ابتدا ذخیره می‌شود و فقط با تأیید جدا و بازسازی بدون ناسازگاری اثر می‌گذارد.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>قلم افتتاحیه</span><select wire:model="openingItemId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($openingItems as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>مانده صحیح (تومان)</span><input wire:model="correctedToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm sm:col-span-2"><span>دلیل اصلاح</span><input wire:model="correctionReason" class="field w-full" maxlength="240"></label>
        </div>
        @error('correction') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        <div class="flex flex-wrap gap-3"><button wire:click="proposeCorrection" type="button" class="button-secondary">ذخیره پیشنهاد اصلاح</button>@if ($pendingCaseId)<button wire:click="approveCorrection" type="button" class="button-primary">تأیید نهایی اصلاحیه شماره {{ $pendingCaseId }}</button>@endif</div>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">اصلاح ارزش ریالی دارایی افتتاحیه</h2>
        <p class="text-sm leading-7 text-slate-600">این اصلاح ارزش مبنای افتتاحیه را با سند و دلیل تازه ثبت می‌کند و اثر آن بر فروش‌های بعدی بازسازی می‌شود؛ تاریخ خرید یا بهای تاریخی ساخته نمی‌شود.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>دارایی افتتاحیه</span><select wire:model="assetOpeningItemId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($assetOpeningItems as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>ارزش صحیح در افتتاحیه (تومان)</span><input wire:model="correctedAssetToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
        </div>
        <p class="text-sm text-slate-600">دلیل اصلاح در کادر بالا وارد می‌شود.</p>
        <button wire:click="proposeAssetCorrection" type="button" class="button-secondary">ذخیره پیشنهاد اصلاح ارزش</button>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">اصلاح مقدار و اظهار مخمّس دارایی افتتاحیه</h2>
        <p class="text-sm leading-7 text-slate-600">دارایی انتخاب‌شده و دلیل اصلاح از بخش بالا خوانده می‌شوند. سهم مخمّس فقط با اظهار صریح شما ثبت می‌شود؛ باقی‌مانده نامشخص می‌ماند. تأیید اصلاح در صورت کسری معاملات بعدی رد می‌شود.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>مقدار صحیح کل</span><input wire:model="correctedAssetQuantity" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm"><span>مقدار مخمّس اظهارشده</span><input wire:model="correctedProtectedQuantity" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm"><span>پایه مخمّس (تومان؛ در صورت اظهار مقدار مخمّس لازم)</span><input wire:model="correctedProtectedBasisToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm"><span>وضعیت مدرک</span><select wire:model="correctedOriginEvidence" class="field w-full"><option value="unknown">نامشخص</option><option value="user_declared">اظهار و تأیید من</option></select></label>
        </div>
        <button wire:click="proposeAssetQuantityCorrection" type="button" class="button-secondary">ذخیره پیشنهاد اصلاح مقدار و منشأ</button>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">واقعیت تملک پیش از افتتاحیه</h2>
        <p class="text-sm leading-7 text-slate-600">این ثبت فقط واقعیت مستند را نگه می‌دارد و خرید، پرداخت یا بهای تاریخی ساختگی نمی‌سازد.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>lot افتتاحیه</span><select wire:model="factLotId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($openingLots as $lot)<option value="{{ $lot->id }}">شماره {{ $lot->id }} · ابزار {{ $lot->instrument_id }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>نوع واقعیت</span><select wire:model="factKind" class="field w-full"><option value="acquisition_date">تاریخ تملک</option><option value="acquisition_nature">نوع تملک</option><option value="trade_intent">قصد تجارت</option><option value="ownership_note">توضیح مالکیت</option></select></label>
            <label class="space-y-1 text-sm"><span>مقدار یا تاریخ تملک شمسی</span><input wire:model="factValue" class="field w-full" maxlength="160"></label>
            <label class="space-y-1 text-sm"><span>شروع اعتبار شمسی (اختیاری)</span><input wire:model="factValidFrom" class="field w-full" dir="ltr" inputmode="numeric"></label>
            <label class="space-y-1 text-sm sm:col-span-2"><span>مدرک یا شرح اظهار</span><input wire:model="factEvidence" class="field w-full" maxlength="240"></label>
        </div>
        @error('fact') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        <button wire:click="recordFact" type="button" class="button-secondary">ثبت واقعیت</button>
    </section>
    <section class="panel space-y-4 p-6">
        <h2 class="text-xl font-bold">وصول طلب یا بازپرداخت بدهی ارزی</h2>
        <p class="text-sm leading-7 text-slate-600">مقدار اصل ارز و مبلغ ریالی واقعاً دریافت یا پرداخت‌شده را وارد کنید. اختلاف با ارزش دفتریِ سهم تسویه‌شده جداگانه به‌عنوان سود/زیان تسعیر ثبت می‌شود؛ اصل تعهد درآمد یا هزینه تازه نیست. تسویهٔ تاریخ‌گذشته پیش از تسویهٔ بعدی همان تعهد برای بازبینی ترتیب رد می‌شود.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>تعهد ارزی</span><select wire:model="fxObligationId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($foreignObligations as $obligation)<option value="{{ $obligation['id'] }}">{{ $obligation['name'] }} · {{ $obligation['instrument'] }} · مانده اصل {{ $obligation['remaining'] }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>حساب ریالی دریافت/پرداخت</span><select wire:model="fxCashId" class="field w-full"><option value="">انتخاب کنید</option>@foreach ($cashAccounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>تاریخ شمسی</span><input wire:model="fxDate" class="field w-full" dir="ltr" inputmode="numeric"></label>
            <label class="space-y-1 text-sm"><span>مقدار اصل ارز</span><input wire:model="fxQuantity" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm"><span>وجه ریالی واقعی (تومان)</span><input wire:model="fxCashToman" class="field w-full" dir="ltr" inputmode="decimal"></label>
            <label class="space-y-1 text-sm"><span>توضیح (اختیاری)</span><input wire:model="fxReason" class="field w-full" maxlength="240"></label>
        </div>
        <label class="flex items-start gap-3 text-sm"><input wire:model="fxAfterOpening" type="checkbox" class="mt-1"><span>اگر تاریخ ۲۰ مرداد ۱۴۰۵ است، تأیید می‌کنم این تسویه پس از افتتاحیه انجام شده است.</span></label>
        @error('fx') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        <button wire:click="settleForeignObligation" type="button" class="button-primary">ثبت تسویه ارزی</button>
    </section>
</div>
