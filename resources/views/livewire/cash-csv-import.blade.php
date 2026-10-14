<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">فاز ۵ب · ورود گروهی</p>
        <h1 class="text-3xl font-bold text-slate-950">ورود گروهی عملیات نقدی از CSV</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">فایل UTF-8 با جداکنندهٔ ویرگول، حداکثر ۲۰۰ سطر و ۲۵۶ کیلوبایت پذیرفته می‌شود. فقط درآمد، شناسایی طلب درآمد، هزینه، انتقال، قرض دادن/گرفتن و وصول/بازپرداخت اصل ریالی پشتیبانی می‌شوند. هر سطر باید مرجع پایدار و یکتا داشته باشد. ابتدا نگاشت و پیش‌نمایش را بررسی کنید؛ تأیید نهایی کل batch را یک‌جا ثبت می‌کند.</p>
    </section>
    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif
    <section class="panel space-y-5 p-6">
        <h2 class="text-xl font-bold">فایل و نگاشت ستون‌ها</h2>
        <p class="text-sm leading-7 text-slate-600">سرستون‌های پیش‌فرض: <code dir="ltr">reference,type,date,amount,source,target,category,reason,after_opening</code>. در صورت تفاوت نام ستون‌ها، نام دقیق هر سرستون را پایین وارد کنید. حساب و دسته با نام دقیق موجود در برنامه تطبیق داده می‌شوند؛ نام مبهم رد می‌شود. ستون‌های اختیاری را می‌توانید خالی بگذارید.</p>
        <p class="text-sm leading-7 text-slate-600">کدهای مجاز ستون نوع: <code dir="ltr">income, accrue_income, expense, transfer, lend, collect, borrow, repay</code>. فایل خرید/فروش دارایی یا قالب بانکی ناشناخته در این مسیر پذیرفته نمی‌شود.</p>
        <form wire:submit="preview" class="space-y-5">
            <label class="block max-w-xl space-y-1 text-sm"><span>فایل CSV یا TXT</span><input wire:model="csvFile" type="file" accept=".csv,.txt,text/csv,text/plain" class="field w-full" required></label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm"><span>واحد مبلغ فایل</span><select wire:model="unit" class="field w-full"><option value="toman">تومان</option><option value="rial">ریال</option></select></label>
                <label class="space-y-1 text-sm"><span>تقویم تاریخ فایل</span><select wire:model="calendar" class="field w-full"><option value="persian">شمسی</option><option value="gregorian">میلادی ISO</option></select></label>
                @foreach (['reference' => 'مرجع یکتا', 'type' => 'نوع عملیات', 'date' => 'تاریخ', 'amount' => 'مبلغ', 'source' => 'حساب مبدأ', 'target' => 'حساب مقصد', 'category' => 'دسته', 'reason' => 'توضیح', 'after_opening' => 'بعد از افتتاحیه'] as $column => $label)
                    <label class="space-y-1 text-sm"><span>ستون {{ $label }}</span><input wire:model="mapping.{{ $column }}" class="field w-full" dir="ltr" maxlength="120" @if (in_array($column, ['reference', 'type', 'date', 'amount'], true)) required @endif></label>
                @endforeach
            </div>
            <p class="text-xs leading-6 text-slate-600">مقدار ستون «بعد از افتتاحیه»: 1/0، true/false یا بله/خیر. برای روز ۲۰ مرداد ۱۴۰۵ باید صریحاً 1 باشد. فرمول، فایل Excel و نوع عملیات ناشناخته رد می‌شوند.</p>
            @error('csvFile') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            <button class="button-secondary" type="submit">ساخت پیش‌نمایش</button>
        </form>
    </section>
    @if ($plan)
        <section class="panel space-y-5 p-6">
            <h2 class="text-xl font-bold">پیش‌نمایش {{ count($plan['rows']) }} سطر</h2>
            <p class="text-sm text-slate-600">واحد: {{ $plan['unit'] === 'rial' ? 'ریال' : 'تومان' }} · تقویم: {{ $plan['calendar'] === 'persian' ? 'شمسی' : 'میلادی' }} · نسخه داده: {{ $plan['revision'] }}. مبالغ پایین برای نمایش به تومان تبدیل شده‌اند. تأیید نهایی ممکن است در صورت تغییر مانده یا تکرار متعارض رد شود.</p>
            @if ($plan['warnings'])
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"><p class="font-bold">نیازمند بررسی تکرار احتمالی</p><ul class="mt-2 list-disc space-y-1 pr-5">@foreach ($plan['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
            @endif
            <div class="overflow-x-auto"><table class="min-w-full text-right text-sm"><thead><tr class="border-b border-slate-200"><th class="p-2">سطر</th><th class="p-2">مرجع</th><th class="p-2">تاریخ</th><th class="p-2">نوع</th><th class="p-2">مبلغ (تومان)</th></tr></thead><tbody>@foreach ($plan['rows'] as $row)<tr class="border-b border-slate-100"><td class="p-2">{{ $row['line'] }}</td><td class="p-2"><bdi>{{ $row['reference'] }}</bdi></td><td class="p-2"><bdi>{{ \App\Support\Dates\PersianDate::fromGregorian($row['input']['date']) }}</bdi></td><td class="p-2"><bdi>{{ $row['input']['type'] }}</bdi></td><td class="p-2"><bdi>{{ $row['input']['amount_toman'] }}</bdi></td></tr>@endforeach</tbody></table></div>
            @if ($plan['warnings'])<label class="flex items-start gap-3 text-sm"><input wire:model="warningsAcknowledged" type="checkbox" class="mt-1"><span>شباهت‌ها و مرجع‌های قبلی را بررسی کردم؛ ثبت سطرهای با مرجع متفاوت را تأیید می‌کنم.</span></label>@endif
            <button wire:click="confirm" type="button" class="button-primary">تأیید و ثبت یک‌جای فایل</button>
        </section>
    @endif
</div>
