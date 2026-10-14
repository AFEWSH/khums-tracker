<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">فضای نقدی · ثبت سریع</p>
        <h1 class="text-3xl font-bold text-slate-950">حساب‌ها و گردش ریالی</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">مانده‌ها و درآمد/هزینه از ثبت‌های ریالی محاسبه می‌شوند. وضعیت مخمّس بودن پول مخلوط تا تعیین سهم مصرف‌شده قطعی نیست.</p>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-7 text-amber-900">ثبت تاریخ گذشته از همان اعتبارسنجی و بازسازی اتمیک می‌گذرد؛ اگر عملیات بعدی را ناسازگار کند، کل ثبت رد می‌شود. پوشش داده و مانده‌ها را پس از تکمیل ورود بررسی و صریح تأیید کنید.</p>
    </section>
    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif
    @unless ($openingConfirmed)
        <section class="panel p-6"><h2 class="text-xl font-bold">ابتدا افتتاحیه نقدی را ثبت کنید</h2><p class="mt-2 text-sm text-slate-600">پیش‌نویس را می‌توانید ذخیره کنید و بعداً ادامه دهید.</p><a class="button-primary mt-5 inline-flex" href="{{ route('cash.setup') }}">رفتن به راه‌انداز</a></section>
    @else
        <section class="grid gap-4 sm:grid-cols-2" aria-label="خلاصه درآمد و هزینه">
            <div class="panel p-5"><p class="text-sm text-slate-600">درآمد ثبت‌شده</p><p class="mt-2 text-2xl font-bold"><bdi>{{ $totals['income_toman'] }}</bdi> <span class="text-base">تومان</span></p></div>
            <div class="panel p-5"><p class="text-sm text-slate-600">هزینه ثبت‌شده</p><p class="mt-2 text-2xl font-bold"><bdi>{{ $totals['expense_toman'] }}</bdi> <span class="text-base">تومان</span></p></div>
        </section>

        <section class="panel p-6" aria-labelledby="accounts-title">
            <h2 id="accounts-title" class="text-xl font-bold">حساب‌ها و اصل بدهی/طلب</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($accounts as $account)
                    <div class="rounded-xl border border-slate-200 p-4" wire:key="cash-account-{{ $account['id'] }}">
                        <p class="font-bold">{{ $account['name'] }}</p>
                        <p class="mt-1 text-sm text-slate-700">مانده: <bdi>{{ $account['balance_toman'] }}</bdi> تومان</p>
                        @if ($account['subtype'] !== 'payable')
                            <p class="mt-2 text-xs leading-6 text-slate-600">حداقل مخمّسِ قابل اثبات: <bdi>{{ $account['protected_min_toman'] }}</bdi> تومان @unless($account['protected_exact']) · سهم نهایی نیازمند بررسی @endunless</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <form wire:submit="createAccount" class="mt-6 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <label class="space-y-1 text-sm"><span>نام حساب جدید</span><input wire:model="newAccountName" class="field w-full" required maxlength="120"></label>
                <label class="space-y-1 text-sm"><span>نوع</span><select wire:model="newAccountSubtype" class="field w-full"><option value="bank">بانک</option><option value="cash">نقد</option><option value="broker_cash">نقد کارگزاری</option><option value="wallet_cash">نقد کیف پول</option><option value="receivable">طلب</option><option value="payable">بدهی</option></select></label>
                <button class="button-secondary" type="submit">ساخت حساب صفر</button>
                @error('newAccountName') <p role="alert" class="text-sm text-red-700 sm:col-span-3">{{ $message }}</p> @enderror
            </form>
        </section>

        <section class="panel p-6" aria-labelledby="operation-title">
            <h2 id="operation-title" class="text-xl font-bold">ثبت عملیات ریالی</h2>
            <form wire:submit="createCategory" class="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <label class="space-y-1 text-sm"><span>دسته جدید</span><input wire:model="newCategoryName" class="field w-full" maxlength="80"></label>
                <label class="space-y-1 text-sm"><span>برای</span><select wire:model="newCategoryKind" class="field w-full"><option value="expense">هزینه</option><option value="income">درآمد</option></select></label>
                <button class="button-secondary" type="submit">ساخت دسته</button>
                @error('newCategoryName') <p role="alert" class="text-sm text-red-700 sm:col-span-3">{{ $message }}</p> @enderror
            </form>
            <form wire:submit="post" class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm"><span>نوع عملیات</span><select wire:model.live="type" class="field w-full"><option value="expense">هزینه</option><option value="income">درآمد</option><option value="accrue_income">شناسایی طلب درآمد</option><option value="transfer">انتقال</option><option value="lend">قرض دادن</option><option value="collect">وصول اصل طلب</option><option value="borrow">دریافت قرض</option><option value="repay">بازپرداخت اصل قرض</option></select></label>
                <label class="space-y-1 text-sm"><span>تاریخ شمسی (سال/ماه/روز)</span><input wire:model="date" class="field w-full" dir="ltr" inputmode="numeric" placeholder="۱۴۰۵/۰۵/۲۱" required></label>
                <label class="space-y-1 text-sm"><span>مبلغ (تومان)</span><input wire:model="amountToman" class="field w-full" dir="ltr" inputmode="decimal" required></label>
                @if (in_array($type, ['income', 'accrue_income', 'expense'], true))
                    <label class="space-y-1 text-sm"><span>دسته (اختیاری)</span><select wire:model="categoryId" class="field w-full"><option value="">بدون دسته</option>@foreach ($categoryOptions as $category)<option value="{{ $category['id'] }}">{{ $category['name'] }}</option>@endforeach</select></label>
                @endif
                @if (in_array($type, ['expense', 'transfer', 'lend', 'collect', 'repay'], true))
                    <label class="space-y-1 text-sm"><span>از حساب</span><select wire:model="sourceId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($sourceOptions as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach</select></label>
                @elseif ($type === 'borrow')
                    <label class="space-y-1 text-sm"><span>بدهی</span><select wire:model="sourceId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($sourceOptions as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach</select></label>
                @endif
                @if (in_array($type, ['income', 'accrue_income', 'transfer', 'lend', 'collect', 'borrow', 'repay'], true))
                    <label class="space-y-1 text-sm"><span>به حساب / اصل تعهد</span><select wire:model="targetId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($targetOptions as $account)<option value="{{ $account['id'] }}">{{ $account['name'] }}</option>@endforeach</select></label>
                @endif
                <label class="space-y-1 text-sm sm:col-span-2"><span>توضیح (اختیاری)</span><input wire:model="reason" class="field w-full" maxlength="240"></label>
                <label class="flex items-start gap-3 text-sm leading-7 sm:col-span-2"><input wire:model="afterOpening" type="checkbox" class="mt-2 accent-teal-700"><span>اگر تاریخ عملیات ۲۰ مرداد ۱۴۰۵ است، تأیید می‌کنم این عملیات بعد از موجودی افتتاحیه رخ داده است.</span></label>
                @error('amountToman') <p role="alert" class="text-sm text-red-700 sm:col-span-2">{{ $message }}</p> @enderror
                @error('date') <p role="alert" class="text-sm text-red-700 sm:col-span-2">{{ $message }}</p> @enderror
                <button type="submit" class="button-primary sm:col-span-2">ثبت عملیات</button>
            </form>
        </section>

        <section class="panel p-6"><h2 class="text-xl font-bold">آخرین عملیات</h2><ul class="mt-4 divide-y divide-slate-100">@forelse ($operations as $operation)<li class="flex flex-wrap gap-3 py-3 text-sm"><time>{{ $operation['date'] }}</time><strong>{{ $operation['type'] }}</strong><span>{{ $operation['reason'] }}</span></li>@empty<li class="py-3 text-sm text-slate-600">هنوز عملیاتی ثبت نشده است.</li>@endforelse</ul></section>
    @endunless
</div>
