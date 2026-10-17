<div class="space-y-8">
    <section class="space-y-3">
        <p class="text-sm font-bold text-teal-800">فاز ۶ · شاخص‌های اقتصادی</p>
        <h1 class="text-3xl font-bold text-slate-950">شاخص قیمت مصرف‌کننده</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">مبنای انتخاب‌شده، شاخص کل خانوارهای کشور با سال پایهٔ پیشنهادی ۱۴۰۰ و شاخص ماه وقوع هر جریان است. درگاه رسمی مرکز آمار در این محیط قابل بررسی مستقیم نبود؛ ثبت لینک یا عدد به‌تنهایی اصالت داده را تأیید نمی‌کند. گزارش اقتصادیِ دادهٔ تأییدنشده موقت است و حکم خمس نیست.</p>
        <p class="text-sm text-slate-600">درگاه ناشر: <a class="text-teal-800 underline" href="https://amar.org.ir/" target="_blank" rel="noopener noreferrer">مرکز آمار ایران</a>؛ برای هر سری و ماه، لینک مستقیم همان انتشار را ثبت کنید. بازشدن لینک برای استفادهٔ آفلاین برنامه لازم نیست.</p>
    </section>
    @if (session('success')) <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p> @endif
    <section class="panel space-y-5 p-6">
        <h2 class="text-xl font-bold">تعریف سری</h2>
        <form wire:submit="addSeries" class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>پوشش شاخص</span><select wire:model="coverage" class="field w-full" required><option value="">انتخاب کنید</option><option value="national_all_households">کل خانوارهای کشور</option><option value="urban_households">خانوارهای شهری</option><option value="rural_households">خانوارهای روستایی</option></select></label>
            <label class="space-y-1 text-sm"><span>سال پایه شمسی</span><input wire:model="baseYear" class="field w-full" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>شناسه روش یا نسخه انتشار</span><input wire:model="methodologyVersion" class="field w-full" maxlength="80" required></label>
            <label class="space-y-1 text-sm"><span>لینک مستقیم منبع HTTPS</span><input wire:model="seriesSourceUrl" class="field w-full" dir="ltr" type="url" maxlength="500" required></label>
            @error('series') <p role="alert" class="text-sm text-red-700 sm:col-span-2">{{ $message }}</p> @enderror
            <div class="sm:col-span-2"><button class="button-secondary" type="submit">ثبت سری تأییدنشده</button></div>
        </form>
    </section>
    <section class="panel space-y-5 p-6">
        <h2 class="text-xl font-bold">ثبت شاخص یک ماه</h2>
        <p class="text-sm leading-7 text-slate-600">مقدار سطح شاخص را وارد کنید، نه درصد تورم. اصلاح مقدار یا منبع قبلی فقط با دلیل انجام می‌شود و نسخه پیشین حفظ می‌ماند. تاریخ انتشار، میلادی ISO است و اگر معلوم نیست خالی بماند.</p>
        <form wire:submit="addPoint" class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span>سری</span><select wire:model.live="seriesId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($series as $item)<option value="{{ $item->id }}">{{ $item->coverage }} · پایه {{ $item->base_year }} · {{ $item->methodology_version }} · تأییدنشده</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span>سال شمسی</span><input wire:model="year" class="field w-full" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>ماه شمسی، ۱ تا ۱۲</span><input wire:model="month" class="field w-full" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>سطح شاخص</span><input wire:model="level" class="field w-full" dir="ltr" inputmode="decimal" required></label>
            <label class="space-y-1 text-sm"><span>لینک مستقیم منبع این عدد</span><input wire:model="pointSourceUrl" class="field w-full" dir="ltr" type="url" maxlength="500" required></label>
            <label class="space-y-1 text-sm"><span>تاریخ انتشار میلادی، در صورت اطلاع</span><input wire:model="publicationDate" class="field w-full" dir="ltr" placeholder="YYYY-MM-DD"></label>
            <label class="space-y-1 text-sm sm:col-span-2"><span>دلیل اصلاح مشاهده قبلی</span><input wire:model="revisionReason" class="field w-full" maxlength="240"></label>
            @error('point') <p role="alert" class="text-sm text-red-700 sm:col-span-2">{{ $message }}</p> @enderror
            <div class="sm:col-span-2"><button class="button-primary" type="submit">ثبت مشاهده</button></div>
        </form>
    </section>
    @if ($points)
        <section class="panel space-y-4 p-6"><h2 class="text-xl font-bold">مشاهدات سری انتخابی</h2><div class="overflow-x-auto"><table class="min-w-full text-right text-sm"><thead><tr class="border-b"><th class="p-2">ماه</th><th class="p-2">سطح شاخص</th><th class="p-2">نسخه</th><th class="p-2">منبع ثبت‌شده</th></tr></thead><tbody>@foreach ($points as $point)<tr class="border-b border-slate-100"><td class="p-2">{{ $point['year'] }}/{{ str_pad((string) $point['month'], 2, '0', STR_PAD_LEFT) }}</td><td class="p-2" dir="ltr">{{ $point['level'] }}</td><td class="p-2">{{ $point['revision'] }}</td><td class="p-2"><a class="text-teal-800 underline" href="{{ $point['source_url'] }}" rel="noopener noreferrer" target="_blank">لینک منبع</a></td></tr>@endforeach</tbody></table></div></section>
    @endif
    <section class="panel space-y-5 p-6">
        <h2 class="text-xl font-bold">ورود گروهی شاخص از CSV</h2>
        <p class="text-sm leading-7 text-slate-600">سری را در فرم بالا انتخاب کنید. فایل UTF-8 با حداکثر ۲۰۰ مشاهده و سرستون دقیق <code dir="ltr">year,month,level,source_url,publication_date,reason</code> پذیرفته می‌شود. تاریخ انتشار و دلیل برای مشاهده نخست می‌توانند خالی باشند؛ برای اصلاح، دلیل لازم است. ابتدا پیش‌نمایش بگیرید، سپس همهٔ ردیف‌ها اتمیک ثبت می‌شوند.</p>
        <form wire:submit="previewCsv" class="flex flex-wrap items-end gap-4">
            <label class="space-y-1 text-sm"><span>فایل CSV</span><input wire:model="csvFile" type="file" accept=".csv,.txt,text/csv,text/plain" class="field w-full" required></label>
            <button class="button-secondary" type="submit">پیش‌نمایش فایل</button>
        </form>
        @error('csvFile') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        @if ($csvPlan)
            <div class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                <p>سری {{ $csvPlan['series_id'] }} · {{ count($csvPlan['rows']) }} ردیف · هنوز ثبت نشده‌اند.</p>
                <div class="overflow-x-auto"><table class="min-w-full text-right"><thead><tr><th class="p-2">سطر</th><th class="p-2">ماه</th><th class="p-2">سطح شاخص</th></tr></thead><tbody>@foreach ($csvPlan['rows'] as $row)<tr><td class="p-2">{{ $row['line'] }}</td><td class="p-2">{{ $row['year'] }}/{{ $row['month'] }}</td><td class="p-2" dir="ltr">{{ $row['level'] }}</td></tr>@endforeach</tbody></table></div>
                <button wire:click="confirmCsv" type="button" class="button-primary">تأیید و ثبت همهٔ ردیف‌ها</button>
            </div>
        @endif
    </section>
    <section class="panel space-y-5 p-6">
        <h2 class="text-xl font-bold">عملکرد اقتصادی</h2>
        <p class="text-sm leading-7 text-slate-600">بازه نیمه‌باز است؛ تاریخ پایان در محاسبه وارد نمی‌شود. در نمای ارزش خالص، افتتاحیه مانده آغاز است، درآمد و هزینه جریان خارجی‌اند و انتقال/قرض/تبدیل داخلی جریان تازه نیستند. در سبد انتخابی، انتقال از بیرون سبد ورود سرمایه است؛ معامله یا درآمد با مرز مبهم، نتیجه را ناقص می‌گذارد. این گزارش مالی، مبنای خمس نیست.</p>
        <form wire:submit="runReport" class="grid gap-4 sm:grid-cols-3">
            <label class="space-y-1 text-sm"><span>دامنه گزارش</span><select wire:model.live="reportScope" class="field w-full"><option value="personal_net_worth">ارزش خالص کل دارایی</option><option value="investment_portfolio">سبد انتخابی حساب‌ها و دارایی‌ها</option></select></label>
            <label class="space-y-1 text-sm"><span>شروع شمسی</span><input wire:model="reportStart" class="field w-full" dir="ltr" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>پایان شمسی، غیرشامل</span><input wire:model="reportEnd" class="field w-full" dir="ltr" inputmode="numeric" required></label>
            <label class="space-y-1 text-sm"><span>سری کل خانوارهای کشور</span><select wire:model="reportSeriesId" class="field w-full" required><option value="">انتخاب کنید</option>@foreach ($series as $item)@if ($item->coverage === 'national_all_households')<option value="{{ $item->id }}">پایه {{ $item->base_year }} · {{ $item->methodology_version }} · {{ $item->verification_status === 'verified' ? 'تأییدشده' : 'تأییدنشده' }}</option>@endif @endforeach</select></label>
            @if ($reportScope === 'investment_portfolio')
                <fieldset class="space-y-2 rounded-xl border border-slate-200 p-3 text-sm sm:col-span-3"><legend class="font-bold">حساب‌ها و دارایی‌های عضو سبد</legend><div class="grid gap-2 sm:grid-cols-2">@foreach ($portfolioAccounts as $account)<label class="flex items-center gap-2"><input wire:model="portfolioAccountIds" type="checkbox" value="{{ $account->id }}"><span>{{ $account->name }} ({{ $account->subtype }})</span></label>@endforeach</div></fieldset>
            @endif
            @error('report') <p role="alert" class="text-sm text-red-700 sm:col-span-3">{{ $message }}</p> @enderror
            <div class="sm:col-span-3"><button class="button-primary" type="submit">محاسبه عملکرد</button></div>
        </form>
        @if ($economicReport)
            <div class="space-y-3 rounded-xl bg-slate-50 p-4 text-sm leading-7">
                <p class="font-bold {{ $economicReport['status'] === 'complete' ? 'text-emerald-800' : 'text-amber-800' }}">{{ $economicReport['scope'] === 'personal_net_worth' ? 'ارزش خالص کل دارایی' : 'سبد انتخابی سرمایه‌گذاری' }} · وضعیت: {{ match ($economicReport['status']) {'complete' => 'کامل طبق روش ماهانه', 'provisional' => 'موقت؛ منبع شاخص تأیید نشده', default => 'ناقص؛ داده لازم موجود نیست'} }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <p>ارزش آغاز: <bdi>{{ $economicReport['opening_value_rial'] === null ? 'نامعلوم' : \App\Support\Numbers\MoneyRial::fromRial($economicReport['opening_value_rial'])->toman() }}</bdi> تومان</p>
                    <p>ارزش پایان: <bdi>{{ $economicReport['closing_value_rial'] === null ? 'نامعلوم' : \App\Support\Numbers\MoneyRial::fromRial($economicReport['closing_value_rial'])->toman() }}</bdi> تومان</p>
                    <p>سود اسمی: <bdi>{{ $economicReport['nominal_gain_rial'] === null ? 'نامعلوم' : \App\Support\Numbers\MoneyRial::fromRial($economicReport['nominal_gain_rial'])->toman() }}</bdi> تومان</p>
                    <p>سود واقعی: <bdi>{{ $economicReport['real_gain_rial'] === null ? 'نامعلوم' : \App\Support\Numbers\MoneyRial::fromRial($economicReport['real_gain_rial'])->toman() }}</bdi> تومان</p>
                    <p>تفاوت تورمی: <bdi>{{ $economicReport['inflation_difference_rial'] === null ? 'نامعلوم' : \App\Support\Numbers\MoneyRial::fromRial($economicReport['inflation_difference_rial'])->toman() }}</bdi> تومان</p>
                    <p>جریان خارجی: {{ count($economicReport['flows']) }} مورد</p>
                </div>
                @if ($economicReport['missing_labels'])<p class="text-amber-900">دلایل نقص: {{ implode('، ', $economicReport['missing_labels']) }}</p>@endif
                <p class="text-xs text-slate-600">سری {{ $economicReport['series_id'] }} با پایه {{ $economicReport['series_base_year'] }} · نسخه داده مالی {{ $economicReport['financial_revision'] }} · نسخه‌های شاخص: {{ implode('، ', $economicReport['cpi_revision_ids']) }}</p>
            </div>
        @endif
    </section>
</div>
