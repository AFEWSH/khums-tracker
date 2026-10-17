<div class="space-y-6">
    <header class="space-y-3">
        <p class="text-sm font-bold text-teal-800">منابع شرعی</p>
        <h1 class="text-3xl font-bold text-slate-950">احکام و قواعد بررسی‌شده</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">این صفحه خلاصهٔ آفلاین منابع است، نه محاسبه یا تأیید حکم برای وضعیت مالی شما. همهٔ قواعد این نسخه غیرفعال‌اند و در محاسبهٔ خمس استفاده نمی‌شوند.</p>
        <p class="text-xs text-slate-500">نسخهٔ فهرست: <bdi>{{ $version }}</bdi></p>
    </header>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($rules as $rule)
            <article class="panel space-y-3 p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-lg font-bold text-slate-900"><bdi>{{ $rule['id'] }}</bdi> · {{ $rule['title'] }}</h2>
                    <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-900">غیرفعال</span>
                </div>
                <p class="text-sm leading-7 text-slate-700">{{ $rule['summary'] }}</p>
                <p class="text-xs leading-6 text-slate-600">نوع: {{ $rule['kind'] }} · بررسی: <bdi>{{ $rule['review'] }}</bdi> · منبع: <bdi>{{ $rule['source'] }}</bdi> · مسئله: {{ $rule['issue'] }}</p>
                <p class="text-xs leading-6 text-slate-600">داده‌های لازم: {{ implode('، ', $rule['required_facts']) }}</p>
                <a class="inline-block text-sm font-bold text-teal-800 underline underline-offset-4" href="{{ $rule['url'] }}" target="_blank" rel="noopener noreferrer">مشاهدهٔ متن رسمی در صورت دسترسی به اینترنت</a>
            </article>
        @endforeach
    </div>
</div>
