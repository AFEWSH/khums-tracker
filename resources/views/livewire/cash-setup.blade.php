<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">راه‌اندازی · مرحله نقدی فاز ۳</p>
        <h1 class="text-3xl font-bold text-slate-950">موجودی ۲۰ مرداد ۱۴۰۵</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">این مرحله فقط حساب‌های ریالی و اصل بدهی/طلب ریالی را پوشش می‌دهد. دارایی‌های مقداری در مراحل بعد اضافه می‌شوند. ساعت مرز سال خمسی هنوز تأیید نشده است.</p>
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

        <section class="panel p-6" aria-labelledby="review-title">
            <h2 id="review-title" class="text-xl font-bold text-slate-900">مرور پیش‌نویس</h2>
            @if ($items === [])
                <p class="mt-3 text-sm text-slate-600">هنوز قلمی ثبت نشده است. صفر یا «موردی ندارم» باید آگاهانه تأیید شود.</p>
                <label class="mt-3 flex items-start gap-3 text-sm leading-7"><input wire:model="confirmNoItems" type="checkbox" class="mt-2 accent-teal-700"><span>تأیید می‌کنم هیچ مانده نقدی یا اصل بدهی/طلب ریالی برای این مرحله ندارم.</span></label>
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
            <div class="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-3"><p>دارایی ریالی: <bdi class="font-bold">{{ $summary['assets_toman'] }}</bdi> تومان</p><p>بدهی ریالی: <bdi class="font-bold">{{ $summary['liabilities_toman'] }}</bdi> تومان</p><p>خالص: <bdi class="font-bold">{{ $summary['net_toman'] }}</bdi> تومان</p></div>
            @error('confirm') <p role="alert" class="mt-4 text-sm text-red-700">{{ $message }}</p> @enderror
            <button wire:click="confirm" type="button" class="button-primary mt-5">تأیید افتتاحیه نقدی آزمایشی</button>
        </section>
    @endif
</div>
