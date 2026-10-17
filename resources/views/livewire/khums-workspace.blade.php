<div class="space-y-6">
    <header class="space-y-3">
        <p class="text-sm font-bold text-teal-800">بررسی شرعی · فاز ۷</p>
        <h1 class="text-3xl font-bold text-slate-950">پیش‌نویس و شبیه‌سازی خمس</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">اگر سال خمسی در تاریخ انتخابی پایان می‌یافت، اقلام زیر نیاز به چه بررسی‌هایی داشتند؟ این پیش‌نمایش فقط خواندنی است و هیچ سال، تعهد یا پرداختی ثبت نمی‌کند.</p>
    </header>

    <form wire:submit="simulate" class="panel flex flex-wrap items-end gap-4 p-5">
        <label class="block text-sm font-bold text-slate-800">تاریخ فرضی پایان سال
            <input wire:model="asOfDate" type="text" inputmode="numeric" dir="ltr" class="mt-2 block w-44 rounded-xl border border-slate-300 px-3 py-2 text-left" placeholder="۱۴۰۵-۰۸-۲۰">
        </label>
        <button type="submit" class="button-primary">ساخت پیش‌نویس</button>
        @error('asOfDate') <p class="w-full text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
    </form>

    @if ($report)
        <section class="panel space-y-4 p-5" aria-live="polite">
            <h2 class="text-xl font-bold text-slate-900">نتیجهٔ بررسی در {{ \App\Support\Dates\PersianDate::fromGregorian($report['as_of_date']) }}</h2>
            <p class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-950">مبلغ خمس قابل اعلام نیست: ساعت مرز سال خمسی و مبنای شرعی اقلام هنوز تأیید نشده‌اند. هیچ قاعدهٔ محاسباتی این نسخه فعال نیست. صفر، به معنی معافیت نیست.</p>
            <p class="text-xs text-slate-500">نسخه قواعد: <bdi>{{ $report['rule_version'] }}</bdi> · نسخه داده مالی: <bdi>{{ $report['financial_revision'] }}</bdi> · شناسه ورودی: <bdi dir="ltr">{{ $report['input_hash'] }}</bdi></p>
            <h3 class="font-bold text-slate-900">اقلام بررسی‌شده</h3>
            @forelse ($report['positions'] as $position)
                <div class="border-t border-slate-100 py-3 text-sm leading-7">
                    <span class="font-bold text-slate-900">{{ $position['name'] }}</span>
                    <span class="mr-2 text-slate-600">ارزش دفتری: <bdi dir="ltr">{{ $position['book_toman'] }}</bdi> تومان</span>
                    @if ($position['market_toman'] !== null)
                        <span class="mr-2 text-slate-600">ارزش بازار: <bdi dir="ltr">{{ $position['market_toman'] }}</bdi> تومان · قیمت تا {{ $position['price_date'] }}، نسخه {{ $position['price_revision'] }}</span>
                    @endif
                    <span class="block text-amber-800">وضعیت: {{ $position['issue_label'] }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-600">هنوز قلم مالی ثبت‌شده‌ای برای این تاریخ وجود ندارد.</p>
            @endforelse
            <p class="text-xs leading-7 text-slate-600">موارد باز: {{ implode('؛ ', $report['issue_labels']) }}</p>
        </section>
    @endif
</div>
