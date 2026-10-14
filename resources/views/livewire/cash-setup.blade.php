<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">راه‌اندازی · مراحل نقدی و دارایی مقداری</p>
        <h1 class="text-3xl font-bold text-slate-950">موجودی ۲۰ مرداد ۱۴۰۵</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">حساب‌های ریالی، اصل بدهی/طلب ریالی و دارایی‌های مقداری را جدا ثبت کنید. تعهد ارزی و ساعت مرز سال خمسی هنوز در این مرحله قطعی نیستند.</p>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-900">نسخه فعلی برای بررسی با داده ساختگی است. برای افتتاحیه کامل و استفاده روزمره با اطلاعات واقعی تا پایان فاز ۵ صبر کنید.</p>
    </section>

    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif

    @if ($setup->status === 'confirmed')
        <section class="panel p-6"><h2 class="text-xl font-bold">افتتاحیه نقدی تأیید شده است</h2><p class="mt-2 text-sm text-slate-600">ثبت دوباره افتتاحیه ممکن نیست. برای مشاهده حساب‌ها به فضای نقدی بروید.</p><a class="button-primary mt-5 inline-flex" href="{{ route('cash.workspace') }}">مشاهده حساب‌ها</a></section>
    @else
        <section class="panel space-y-5 p-6" aria-labelledby="settings-title">
            <h2 id="settings-title" class="text-xl font-bold text-slate-900">۱. تنظیمات آغاز</h2>
            <p class="text-sm text-slate-600">تاریخ افتتاحیه ثابت است؛ تأیید ساعت شرعی در مرحله مربوط انجام می‌شود.</p>
            <label class="flex items-start gap-3 text-sm leading-7"><input wire:model="previousKhumsSettled" type="checkbox" class="mt-2 accent-teal-700"><span>اعلام می‌کنم خمس سال ۱۴۰۵ تسویه شده است. این اعلام به‌تنهایی همه اقلام را مخمّس نمی‌کند.</span></label>
            <button wire:click="saveSettings" type="button" class="button-secondary">ذخیره تنظیمات</button>
        </section>

        <section class="panel space-y-5 p-6" aria-labelledby="items-title">
            <h2 id="items-title" class="text-xl font-bold text-slate-900">۲. بانک، نقد، طلب و بدهی ریالی</h2>
            <form wire:submit="addItem" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>نوع قلم</span><select wire:model="kind" class="field w-full"><option value="bank">حساب بانکی</option><option value="cash">نقد</option><option value="broker_cash">نقد کارگزاری</option><option value="wallet_cash">نقد کیف پول</option><option value="receivable">طلب ریالی</option><option value="payable">بدهی ریالی</option></select></label>
                <label class="space-y-1 text-sm"><span>نام کوتاه</span><input wire:model="name" class="field w-full" maxlength="120" required autocomplete="off"></label>
                <label class="space-y-1 text-sm"><span>مانده در افتتاحیه (تومان)</span><input wire:model="amountToman" class="field w-full" inputmode="decimal" dir="ltr" required autocomplete="off"></label>
                <label class="space-y-1 text-sm"><span>بخش مخمّسِ تأییدشده (تومان)</span><input wire:model="protectedToman" class="field w-full" inputmode="decimal" dir="ltr" required autocomplete="off"></label>
                <label class="space-y-1 text-sm md:col-span-2"><span>مبنای اعلام</span><select wire:model="evidence" class="field w-full"><option value="unknown">نامعلوم / بدون تأیید مخمّس</option><option value="user_declared">اظهار صریح خودم برای همین قلم و مبلغ</option></select></label>
                @error('amountToman') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                @error('name') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button type="submit" class="button-primary md:col-span-2">ذخیره قلم</button>
            </form>
        </section>

        <section class="panel space-y-5 p-6" aria-labelledby="instrument-title">
            <h2 id="instrument-title" class="text-xl font-bold text-slate-900">۳. تعریف ابزار دارایی</h2>
            <p class="text-sm text-slate-600">برای هر سهم، صندوق، فلز، ارز یا رمزارز یک ابزار با واحد و دقت ثابت تعریف کنید. عیار فقط برای فلز است.</p>
            <form wire:submit="createInstrument" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>شناسه یکتا، لاتین</span><input wire:model="instrumentCode" class="field w-full" maxlength="64" required dir="ltr"></label>
                <label class="space-y-1 text-sm"><span>نام ابزار</span><input wire:model="instrumentName" class="field w-full" maxlength="120" required></label>
                <label class="space-y-1 text-sm"><span>نوع</span><select wire:model="instrumentType" class="field w-full"><option value="stock">سهام</option><option value="fund">صندوق</option><option value="gold">طلا</option><option value="silver">نقره</option><option value="currency">ارز</option><option value="crypto">رمزارز</option><option value="other">سایر</option></select></label>
                <label class="space-y-1 text-sm"><span>تعداد رقم اعشار مقدار (۰ تا ۱۸)</span><input wire:model="instrumentScale" type="number" min="0" max="18" class="field w-full" required dir="ltr"></label>
                <label class="space-y-1 text-sm"><span>عیار از ۱۰۰۰۰، فقط برای فلز (اختیاری)</span><input wire:model="instrumentFineness" type="number" min="1" max="10000" class="field w-full" dir="ltr"></label>
                @error('instrument') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button type="submit" class="button-secondary md:col-span-2">افزودن ابزار</button>
            </form>
        </section>

        <section class="panel space-y-5 p-6" aria-labelledby="asset-title">
            <h2 id="asset-title" class="text-xl font-bold text-slate-900">۴. موجودی مقداری در افتتاحیه</h2>
            <form wire:submit="addAssetItem" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>ابزار</span><select wire:model="assetInstrumentId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($instruments as $instrument)<option value="{{ $instrument->id }}">{{ $instrument->name }} ({{ $instrument->code }})</option>@endforeach</select></label>
                <label class="space-y-1 text-sm"><span>محل/نام دارایی</span><input wire:model="assetName" class="field w-full" maxlength="120" required></label>
                <label class="space-y-1 text-sm"><span>مقدار</span><input wire:model="assetQuantity" class="field w-full" inputmode="decimal" dir="ltr" required></label>
                <label class="space-y-1 text-sm"><span>ارزش افتتاحیه (تومان)</span><input wire:model="assetValueToman" class="field w-full" inputmode="decimal" dir="ltr" required></label>
                <label class="space-y-1 text-sm"><span>مقدار مخمّس اظهارشده</span><input wire:model="assetProtectedQuantity" class="field w-full" inputmode="decimal" dir="ltr" required></label>
                <label class="space-y-1 text-sm"><span>پایه ریالی مخمّس همین بخش (تومان، در صورت اظهار)</span><input wire:model="assetProtectedBasisToman" class="field w-full" inputmode="decimal" dir="ltr"></label>
                <label class="space-y-1 text-sm md:col-span-2"><span>مبنای وضعیت</span><select wire:model="assetEvidence" class="field w-full"><option value="unknown">نامعلوم</option><option value="user_declared">اظهار صریح من</option></select></label>
                @error('assetItem') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button type="submit" class="button-secondary md:col-span-2">ذخیره دارایی</button>
            </form>
            @if ($assetItems !== [])
                <ul class="divide-y divide-slate-100">@foreach ($assetItems as $assetItem)
                    <li wire:key="asset-opening-{{ $assetItem['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><div><strong>{{ $assetItem['name'] }}</strong><p class="text-slate-600">{{ $assetItem['instrument'] }} · مقدار <bdi>{{ $assetItem['quantity'] }}</bdi> · مخمّس اظهارشده <bdi>{{ $assetItem['protected_quantity'] }}</bdi> · ارزش <bdi>{{ $assetItem['value_toman'] }}</bdi> تومان · پایه مخمّس {{ $assetItem['basis_toman'] ?? 'نامعلوم' }} تومان</p></div><button wire:click="removeAssetItem({{ $assetItem['id'] }})" type="button" class="text-red-700 underline">حذف پیش‌نویس</button></li>
                @endforeach</ul>
            @endif
        </section>

        <section class="panel space-y-5 p-6" aria-labelledby="foreign-title">
            <h2 id="foreign-title" class="text-xl font-bold text-slate-900">۵. بدهی و طلب ارزی افتتاحیه</h2>
            <p class="text-sm leading-7 text-slate-600">اصل ارز و ارزش ریالی افتتاحیه جدا ثبت می‌شوند. این ارزش، نرخ معامله گذشته یا بهای تاریخی نیست. پرداخت و وصول ارزی هنوز فعال نیست.</p>
            <form wire:submit="addForeignObligation" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span>نوع</span><select wire:model="foreignKind" class="field w-full"><option value="receivable">طلب ارزی</option><option value="payable">بدهی ارزی</option></select></label>
                <label class="space-y-1 text-sm"><span>ارز</span><select wire:model="foreignInstrumentId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($instruments as $instrument) @if ($instrument->type === 'currency') <option value="{{ $instrument->id }}">{{ $instrument->name }}</option> @endif @endforeach</select></label>
                <label class="space-y-1 text-sm"><span>نام/طرف حساب</span><input wire:model="foreignName" class="field w-full" maxlength="120" required></label>
                <label class="space-y-1 text-sm"><span>اصل ارز</span><input wire:model="foreignPrincipal" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                <label class="space-y-1 text-sm"><span>ارزش افتتاحیه (تومان)</span><input wire:model="foreignValueToman" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                @error('foreign') <p role="alert" class="text-sm text-red-700 md:col-span-2">{{ $message }}</p> @enderror
                <button type="submit" class="button-secondary md:col-span-2">ذخیره تعهد</button>
            </form>
            @if ($foreignItems !== []) <ul class="divide-y divide-slate-100">@foreach ($foreignItems as $foreignItem)
                <li wire:key="foreign-opening-{{ $foreignItem['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><div><strong>{{ $foreignItem['name'] }}</strong><p class="text-slate-600">{{ $foreignItem['kind'] === 'payable' ? 'بدهی' : 'طلب' }} · {{ $foreignItem['principal'] }} {{ $foreignItem['instrument'] }} · ارزش {{ $foreignItem['value_toman'] }} تومان</p></div><button wire:click="removeForeignObligation({{ $foreignItem['id'] }})" type="button" class="text-red-700 underline">حذف پیش‌نویس</button></li>
            @endforeach</ul> @endif
        </section>

        <section class="panel p-6" aria-labelledby="review-title">
            <h2 id="review-title" class="text-xl font-bold text-slate-900">مرور پیش‌نویس</h2>
            @if ($items === [] && $assetItems === [] && $foreignItems === [])
                <p class="mt-3 text-sm text-slate-600">هنوز قلمی ثبت نشده است. صفر یا «موردی ندارم» باید آگاهانه تأیید شود.</p>
                <label class="mt-3 flex items-start gap-3 text-sm leading-7"><input wire:model="confirmNoItems" type="checkbox" class="mt-2 accent-teal-700"><span>تأیید می‌کنم هیچ مانده نقدی، دارایی مقداری یا اصل بدهی/طلب ریالی برای این مرحله ندارم.</span></label>
            @else
                <ul class="mt-5 divide-y divide-slate-100">
                    @foreach ($items as $item)
                        <li wire:key="opening-item-{{ $item['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div><strong>{{ $item['name'] }}</strong><p class="mt-1 text-slate-600">مانده: <bdi>{{ $item['amount_toman'] }}</bdi> تومان · مخمّس اظهارشده: <bdi>{{ $item['protected_toman'] }}</bdi> تومان</p></div>
                            <button wire:click="removeItem({{ $item['id'] }})" type="button" class="text-red-700 underline">حذف پیش‌نویس</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3"><p>ارزش دارایی افتتاحیه: <bdi class="font-bold">{{ $summary['assets_toman'] }}</bdi> تومان</p><p>ارزش بدهی افتتاحیه: <bdi class="font-bold">{{ $summary['liabilities_toman'] }}</bdi> تومان</p><p>خالص: <bdi class="font-bold">{{ $summary['net_toman'] }}</bdi> تومان</p></div>
            @error('confirm') <p role="alert" class="mt-4 text-sm text-red-700">{{ $message }}</p> @enderror
            <button wire:click="confirm" type="button" class="button-primary mt-5">تأیید افتتاحیه آزمایشی</button>
        </section>
    @endif
</div>
