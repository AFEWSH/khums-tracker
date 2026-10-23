<div class="space-y-6">
    <header class="space-y-3">
        <p class="text-sm font-bold text-teal-800">بررسی سال خمسی</p>
        <h1 class="text-3xl font-bold text-slate-950">پیش‌نویس و شبیه‌سازی خمس</h1>
        <p class="max-w-3xl text-sm leading-8 text-slate-600">شبیه‌سازی روزانه فقط خواندنی است. گزارش دورهٔ سررسیده را می‌توان برای بازبینی ذخیره کرد؛ ذخیرهٔ پیش‌نویس، سال را نمی‌بندد و تعهد یا پرداختی ثبت نمی‌کند.</p>
    </header>

    <section class="panel space-y-2 p-5" aria-label="وضعیت اولین سال خمسی">
        <h2 class="text-lg font-bold text-slate-900">اولین سال خمسی</h2>
        <p class="text-sm leading-7 text-slate-700">از {{ $firstPeriod['start_persian'] }} تا {{ $firstPeriod['end_exclusive_persian'] }}، به وقت تهران.</p>
        <p class="text-sm leading-7 text-slate-700">مرز تأییدشدهٔ برنامه: ساعت <bdi dir="ltr">۰۰:۰۰</bdi> بامداد ۲۰ مرداد به وقت تهران. در این لحظه سال پیش پایان می‌یابد و سال بعد آغاز می‌شود.</p>
        <p class="text-sm leading-7 text-amber-800">وضعیت اولین سررسید: {{ $dueStatus === 'not_due' ? 'هنوز نرسیده' : 'سررسید رسیده؛ بررسی و تأیید گزارش لازم است' }}. تعیین ساعت به‌تنهایی به معنی محاسبه یا بستن سال نیست.</p>
        @if ($pendingPeriods !== [])
            <div class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-950">
                <p class="font-bold">دوره‌های نیازمند بررسی، به‌ترتیب:</p>
                <ol class="list-inside list-decimal">
                    @foreach ($pendingPeriods as $candidate)
                        <li>
                            {{ $candidate['period']['start_persian'] }} تا {{ $candidate['period']['end_exclusive_persian'] }} — سررسید رسیده؛ نیازمند بررسی
                            <button type="button" wire:click="reviewPeriod({{ (int) substr($candidate['period']['start_persian'], 0, 4) }})" class="mr-2 rounded-lg border border-amber-700 px-3 py-1 font-bold">گزارش دوره</button>
                        </li>
                    @endforeach
                </ol>
                <p>این فهرست فقط برای پیگیری است و هیچ سالی را خودکار نمی‌بندد.</p>
            </div>
        @endif
    </section>

    <section class="panel space-y-3 p-5" aria-label="تغییر آینده سال خمسی">
        <h2 class="text-lg font-bold text-slate-900">تغییر تاریخ سررسید برای سال‌های آینده</h2>
        <p class="text-sm leading-7 text-slate-700">تغییر فقط از سال آیندهٔ انتخابی و با دورهٔ گذار صریح اعمال می‌شود. گزارش سال‌های گذشته بازنویسی نمی‌شود.</p>
        <div class="grid gap-3 sm:grid-cols-4">
            <label class="text-sm">سال شروع اثر <input wire:model="scheduleStartYear" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2" placeholder="1406"></label>
            <label class="text-sm">ماه پایان <input wire:model="scheduleEndMonth" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2"></label>
            <label class="text-sm">روز پایان <input wire:model="scheduleEndDay" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2"></label>
            <label class="text-sm">ساعت دقیق مرز <input wire:model="scheduleEndClock" dir="ltr" class="mt-1 block w-full rounded-lg border p-2" placeholder="00:00"></label>
        </div>
        <button type="button" wire:click="previewScheduleChange" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold">بررسی دورهٔ گذار</button>
        @if ($schedulePreview)
            <div class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-950">
                <p>سال {{ $schedulePreview['effective_start_year'] }} از مرز قبلی <bdi dir="ltr">{{ $schedulePreview['new_start_at_utc'] }}</bdi> آغاز و در {{ $schedulePreview['new_end_persian'] }} ساعت {{ $schedulePreview['new_clock'] }} پایان می‌یابد. پایان قبلی همان دوره <bdi dir="ltr">{{ $schedulePreview['old_end_at_utc'] }}</bdi> بود.</p>
                @if ($schedulePreview['new_clock'] !== '00:00')
                    <p class="font-bold">در مرز غیرنیمه‌شب، داده‌های فقط‌تاریخ برای گزارش قطعی کافی نیستند و بستن سال تا ثبت ساعت مؤثر عملیات مسدود می‌ماند.</p>
                @endif
                <form wire:submit="approveScheduleChange" class="mt-3 space-y-2">
                    <label class="block">دلیل تغییر <input wire:model="scheduleReason" class="mt-1 block w-full rounded-lg border p-2"></label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="scheduleApproved">دورهٔ گذار و تاریخ تازه را تأیید می‌کنم.</label>
                    <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت نسخهٔ تازهٔ تقویم</button>
                </form>
            </div>
        @endif
    </section>

    @if ($periodReport)
        <section class="panel space-y-3 p-5" aria-live="polite">
            <h2 class="text-xl font-bold text-slate-900">گزارش بررسی دورهٔ {{ $periodReport['period']['start_persian'] }} تا {{ $periodReport['period']['end_exclusive_persian'] }}</h2>
            <p class="text-sm leading-7 text-amber-900">این گزارش در پایان روز پیش از مرز ۰۰:۰۰ تهیه می‌شود. مبلغ فقط با شواهد صریح هر قلم، پوشش کامل دوره و قواعد فعال و اعتبارسنجی‌شده قابل تأیید است.</p>
            <p class="text-sm text-slate-700">اقلام بررسی‌شده: {{ count($periodReport['positions']) }} · موارد نیازمند بررسی: {{ count($periodReport['issues']) }}</p>
            <p class="text-sm font-bold text-slate-900">وضعیت: {{ $periodReport['status'] === 'computed' ? 'محاسبه‌شده، نیازمند تأیید نهایی' : 'ناقص، بستن سال ممنوع' }} · مبلغ: <bdi dir="ltr">{{ $periodReport['total_due_exact_rial'] ?? 'نامعلوم' }}</bdi> ریال</p>
            <div class="space-y-2" aria-label="جزئیات اقلام گزارش دوره">
                @forelse ($periodReport['positions'] as $position)
                    <div class="rounded-xl border border-slate-200 p-3 text-sm leading-7">
                        <p class="font-bold text-slate-900">{{ $position['name'] }}</p>
                        <p class="text-slate-700">ارزش دفتری: <bdi dir="ltr">{{ $position['book_toman'] }}</bdi> تومان
                            @if ($position['market_toman'] !== null)
                                · ارزش بازار: <bdi dir="ltr">{{ $position['market_toman'] }}</bdi> تومان
                                · تاریخ قیمت: {{ $position['price_date'] }}، نسخه {{ $position['price_revision'] }}
                            @endif
                        </p>
                        <p class="text-amber-800">{{ $position['issue_label'] }}</p>
                    </div>
                @empty
                    <p class="text-sm text-amber-800">هیچ قلم مالی برای این دوره ثبت نشده است؛ مبلغ صفر قابل استنتاج نیست.</p>
                @endforelse
            </div>
            @if ($periodReport['issue_labels'] !== [])
                <div class="rounded-xl bg-amber-50 p-3 text-sm leading-7 text-amber-950">
                    <p class="font-bold">موارد لازم برای تکمیل گزارش</p>
                    <ul class="list-inside list-disc">
                        @foreach ($periodReport['issue_labels'] as $issueLabel)
                            <li>{{ $issueLabel }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (($periodReport['asset_basis_trace']['sales'] ?? []) !== [])
                <details class="rounded-xl border border-slate-200 p-3 text-sm leading-7">
                    <summary class="cursor-pointer font-bold">منشأ وجه فروش و پایهٔ مخمّس</summary>
                    <p class="mt-2 text-amber-900">اعداد زیر ردیابی پایه‌اند؛ افزایشِ نشان‌داده‌شده به‌تنهایی مبلغ مشمول خمس نیست و به قاعده و شواهد تأییدشده نیاز دارد.</p>
                    @foreach ($periodReport['asset_basis_trace']['sales'] as $sale)
                        <div class="mt-3 border-t border-slate-100 pt-2">
                            <p>عملیات {{ ($sale['type'] ?? '') === 'asset_convert' ? 'تبدیل' : 'فروش' }} {{ $sale['operation_id'] }} در {{ \App\Support\Dates\PersianDate::fromGregorian($sale['effective_date']) }}</p>
                            <p>عوض خالصِ این بخش: <bdi dir="ltr">{{ $sale['net_proceeds_rial'] }}</bdi> ریال · {{ ($sale['economic_cost_scope'] ?? '') === 'whole_operation' ? 'بهای اقتصادی کل عملیات' : 'بهای اقتصادی' }}: <bdi dir="ltr">{{ $sale['economic_cost_rial'] }}</bdi> ریال</p>
                            <p>پایهٔ مخمّس تخصیص‌یافته: <bdi dir="ltr">{{ $sale['allocated_basis_exact_rial'] }}</bdi> ریال · افزایش نیازمند بررسی: <bdi dir="ltr">{{ $sale['increase_candidate_exact_rial'] }}</bdi> ریال</p>
                        </div>
                    @endforeach
                </details>
            @endif
            <p class="break-all text-xs text-slate-500" dir="ltr">{{ $periodReport['input_hash'] }}</p>
            @if (collect($activeRules)->contains('id', 'K12') && collect($activeRules)->contains('id', 'K01'))
                <details class="space-y-3 rounded-xl border border-slate-200 p-4">
                    <summary class="cursor-pointer font-bold">بررسی منشأ چندمرحله‌ای و بخش‌های خرید</summary>
                    <p class="text-sm leading-7">این بررسی پیشرفته فقط برای منشأهای معلوم است. بخش واقعیِ فروخته یا تبدیل‌شده را مشخص کنید؛ ترتیب حسابداری FIFO به‌جای آن انتخاب نمی‌شود. مبلغ‌ها به ریال و مقدار با همان واحد معمول هر دارایی وارد می‌شود.</p>
                    <button type="button" wire:click="prepareOriginPlan" wire:loading.attr="disabled" class="button-primary">آماده‌سازی گردش برای بررسی منشأ</button>
                    @error('origin')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    @if ($errors->has('originPlan.*'))<p role="alert" class="text-sm text-red-700">مقادیر تخصیص باید متن عددی معتبر باشند.</p>@endif
                    @if ($originPreview)
                        <form wire:submit="confirmOriginPlan" class="space-y-4">
                            @foreach ($originPreview['operations'] as $operation)
                                @if (isset($originPlan[$operation['id']]))
                                    <fieldset wire:key="origin-operation-{{ $operation['id'] }}" class="space-y-3 rounded-lg border p-3">
                                        <legend class="text-sm font-bold">عملیات {{ $operation['id'] }} · {{ \App\Support\Dates\PersianDate::fromGregorian($operation['date']) }}</legend>
                                        @foreach ($operation['cash_lines'] as $line)
                                            @if ($line['side'] === 'credit')
                                                <label class="block text-sm">سهم مخمّس مصرف‌شده از {{ collect($originPreview['accounts'])->firstWhere('id', $line['account_id'])['name'] ?? 'حساب' }}؛ از کل {{ $line['amount_rial'] }} ریال
                                                    <input wire:model="originPlan.{{ $operation['id'] }}.cash_spends.{{ $line['account_id'] }}" dir="ltr" class="mt-1 block w-full rounded-lg border p-2" placeholder="مبلغ دقیق؛ مانند ۱۰۰ یا ۱/۳">
                                                </label>
                                            @endif
                                        @endforeach
                                        @if ($operation['type'] === 'asset_buy')
                                            <label class="block text-sm">کارمزد ثبت‌شدهٔ همین خرید، به ریال؛ بدون تطبیق با ثبت اصلی پذیرفته نمی‌شود
                                                <input wire:model="originPlan.{{ $operation['id'] }}.purchase_fee_rial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                                            </label>
                                            <label class="block text-sm">پایهٔ مخمّسِ منتقل‌شده به خرید پس از بررسی کارمزد و شواهد، به ریال
                                                <input wire:model="originPlan.{{ $operation['id'] }}.acquisition_basis_rial" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                                            </label>
                                        @endif
                                        @if ($operation['exit'] !== null)
                                            <p class="text-sm">مقدار خروج: {{ $operation['exit']['quantity'] }} · عوض خالص: {{ $operation['exit']['net_proceeds_rial'] }} ریال. برای بخشِ انتخاب‌نشده، هر دو مقدار صفر بماند.</p>
                                            @foreach ($operation['available_lots'] as $lot)
                                                <div class="space-y-2 rounded-lg bg-slate-50 p-3">
                                                    <p class="text-sm font-bold">بخش خرید شمارهٔ {{ $lot['lot_id'] }} · مقدار اولیه {{ $lot['quantity'] }}</p>
                                                    <label class="block text-sm">مقدار خروج از این بخش
                                                        <input wire:model="originPlan.{{ $operation['id'] }}.lots.{{ $lot['lot_id'] }}.atoms" inputmode="decimal" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                                                    </label>
                                                    <label class="block text-sm">عوض خالص منتسب به این بخش، به ریال
                                                        <input wire:model="originPlan.{{ $operation['id'] }}.lots.{{ $lot['lot_id'] }}.net_proceeds_rial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                                                    </label>
                                                </div>
                                            @endforeach
                                        @endif
                                    </fieldset>
                                @endif
                            @endforeach
                            @foreach ($originPreview['fact_labels'] as $index => $label)
                                <label class="block text-sm">{{ $label }}<textarea wire:model="originFacts.{{ $index }}" rows="2" class="mt-1 block w-full rounded-lg border p-2"></textarea></label>
                            @endforeach
                            @if ($originPreview['existing_decision_id'] !== null)
                                <div class="space-y-2 rounded-lg bg-amber-50 p-3 text-sm">
                                    <p>این گردش، تخصیص تأییدشدهٔ شمارهٔ {{ $originPreview['existing_decision_id'] }} را دارد. تغییر آن فقط با اصلاحیه ثبت می‌شود؛ سابقه و گزارش‌های بسته‌شده حفظ می‌شوند.</p>
                                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="originRevisionApproved">اصلاح تخصیص قبلی را مستقلاً تأیید می‌کنم.</label>
                                    <label class="block">دلیل اصلاح<textarea wire:model="originRevisionReason" rows="2" class="mt-1 block w-full rounded-lg border p-2"></textarea></label>
                                </div>
                            @endif
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="originApproved">شواهد، انتخاب واقعی بخش‌ها و بقای اصل مخمّس را بررسی کردم.</label>
                            <button type="submit" wire:loading.attr="disabled" class="button-primary">تأیید تخصیص منشأ بدون تغییر دفترکل</button>
                        </form>
                    @endif
                </details>
            @endif
            @if (collect($activeRules)->contains('id', 'K12') && collect($activeRules)->contains('id', 'K01'))
                <form wire:submit="allocateProceeds" class="space-y-3 rounded-xl border border-amber-200 p-4">
                    <h3 class="font-bold">تخصیص مستند وجه فروش پس از خرج‌کرد</h3>
                    <p class="text-sm leading-7">فقط سهم باقی‌مانده از اصل مخمّسِ فروش‌های ردیابی‌شده را وارد کنید. روش مصرف پول مخلوط خودکار فرض نمی‌شود؛ منشأ ناقص یا تبدیل پیچیده با این فرم رفع نمی‌شود.</p>
                    <label class="block text-sm">حساب دریافت وجه
                        <select wire:model="proceedsAccountId" class="mt-1 block w-full rounded-lg border p-2">
                            <option value="0">انتخاب کنید</option>
                            @foreach ($periodReport['asset_basis_trace']['cash_positions'] as $cashPosition)
                                @if (in_array('protected_proceeds_spending_requires_allocation', $cashPosition['issues'], true))
                                    <option value="{{ $cashPosition['account_id'] }}">{{ collect($periodReport['positions'])->firstWhere('account_id', $cashPosition['account_id'])['name'] ?? 'حساب شمارهٔ '.$cashPosition['account_id'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">اصل مخمّس باقی‌مانده از فروش، به ریال
                        <input wire:model="proceedsProtectedRial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                    </label>
                    @foreach ($activeRules as $rule)
                        @if ($rule['id'] === 'K12')
                            @foreach ($rule['required_facts'] as $index => $fact)
                                <label class="block text-sm">{{ $fact }}<textarea wire:model="proceedsFacts.{{ $index }}" class="mt-1 block w-full rounded-lg border p-2" rows="2"></textarea></label>
                            @endforeach
                        @endif
                    @endforeach
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="proceedsApproved">روش تخصیص و شواهد این مبلغ را تأیید می‌کنم.</label>
                    @error('proceeds')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                    <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت تخصیص بررسی‌شده</button>
                </form>
            @endif
            @if ($savedPeriodReviewId === null)
                <button type="button" wire:click="savePeriodReview" class="button-primary">ذخیرهٔ نسخهٔ پیش‌نویس برای بازبینی</button>
            @endif
            @if ($savedPeriodReviewId !== null)
                <p class="text-sm text-teal-800">پیش‌نویس شمارهٔ {{ $savedPeriodReviewId }} ذخیره شد؛ این کار بستن سال یا ثبت تعهد نیست.</p>
            @endif
            @if ($activeRules !== [] && $periodReport['status'] === 'incomplete')
                <form wire:submit="confirmComponent" class="space-y-3 rounded-xl border border-teal-200 p-4">
                    <h3 class="font-bold text-slate-900">تأیید مبنای یک قلم با شواهد صریح</h3>
                    <label class="block text-sm">قلم
                        <select wire:model="componentAccountId" class="mt-1 block w-full rounded-lg border p-2">
                            <option value="0">انتخاب کنید</option>
                            @foreach ($periodReport['positions'] as $position)
                                @if ($position['class'] === 'asset')
                                    <option value="{{ $position['account_id'] }}">{{ $position['name'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">مبلغ مشمول تأییدشده به ریال
                        <input wire:model="componentEligibleRial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2">
                    </label>
                    <label class="block text-sm">قاعدهٔ اعتبارسنجی‌شده
                        <select wire:model.live="componentRuleId" class="mt-1 block w-full rounded-lg border p-2">
                            <option value="">انتخاب کنید</option>
                            @foreach ($activeRules as $rule)
                                <option value="{{ $rule['id'] }}">{{ $rule['title'] ?? $rule['id'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    @foreach ($activeRules as $rule)
                        @if ($rule['id'] === $componentRuleId)
                            @foreach ($rule['required_facts'] as $index => $fact)
                                <label class="block text-sm">{{ $fact }}
                                    <textarea wire:model="componentFacts.{{ $index }}" class="mt-1 block w-full rounded-lg border p-2" rows="2"></textarea>
                                </label>
                            @endforeach
                        @endif
                    @endforeach
                    <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت تصمیم مستند این قلم</button>
                </form>
            @endif
            @if ($savedPeriodReviewId !== null && $periodReport['status'] === 'computed' && $closedClosureId === null)
                <form wire:submit="closeYear" class="space-y-3 rounded-xl border border-teal-200 p-4">
                    <p class="text-sm leading-7">پیش از بستن، برنامه پشتیبان رمزدار می‌سازد و بازیابی آن را می‌آزماید. کلید بازیابی در فضای خصوصی جدا ثبت می‌شود.</p>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="closureApproved">جزئیات و مبلغ همین نسخه را بررسی کردم و بستن این سال را تأیید می‌کنم.</label>
                    <button type="submit" wire:loading.attr="disabled" class="button-primary">بستن سال و ثبت تعهد، بدون پرداخت</button>
                </form>
            @endif
        </section>
    @endif

    @if ($savedReviews !== [])
        <section class="panel space-y-3 p-5" aria-label="نسخه‌های ذخیره‌شدهٔ بازبینی خمس">
            <h2 class="text-lg font-bold text-slate-900">نسخه‌های ذخیره‌شدهٔ بازبینی</h2>
            <ul class="space-y-2 text-sm text-slate-700">
                @foreach ($savedReviews as $saved)
                    <li class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                        <span>سال {{ $saved['start_year'] }} · نسخهٔ {{ $saved['id'] }} · {{ $saved['status'] === 'computed' ? 'محاسبه‌شده' : 'ناقص' }}</span>
                        <button type="button" wire:click="loadPeriodReview({{ $saved['id'] }})" class="rounded-lg border border-slate-300 px-3 py-1 font-bold">مشاهدهٔ نسخه</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($closedPeriods !== [])
        <section class="panel space-y-4 p-5" aria-label="سال‌های بسته‌شده">
            <h2 class="text-lg font-bold text-slate-900">سال‌های بسته‌شده و پرداخت مستقل</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($closedPeriods as $closed)
                    <button type="button" wire:click="showClosedPeriod({{ $closed['id'] }})" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">سال {{ $closed['start_year'] }} · نسخه {{ $closed['revision'] }} · تعهد <bdi dir="ltr">{{ $closed['due_exact_rial'] }}</bdi> ریال{{ $closed['dependency_stale'] ? ' · وابستگی نیازمند بررسی' : '' }}</button>
                @endforeach
            </div>
            @if ($closedReport)
                <div class="space-y-2 rounded-xl bg-slate-50 p-4 text-sm leading-7" aria-label="نسخهٔ ثابت گزارش بسته‌شده">
                    <h3 class="font-bold">نسخهٔ ثابت گزارش بسته‌شده</h3>
                    <p>{{ $closedReport['period']['start_persian'] }} تا {{ $closedReport['period']['end_exclusive_persian'] }} · مبلغ ثبت‌شده: <bdi dir="ltr">{{ $closedReport['total_due_exact_rial'] }}</bdi> ریال</p>
                    <p>نسخهٔ قواعد: <bdi>{{ $closedReport['rule_version'] }}</bdi> · نسخهٔ داده: {{ $closedReport['financial_revision'] }}</p>
                    <p class="break-all text-xs text-slate-500" dir="ltr">{{ $closedReport['input_hash'] }}</p>
                    @foreach ($closedReport['items'] as $item)
                        <p>قلم <bdi dir="ltr">{{ $item['component_id'] }}</bdi> · مبنای تأییدشده: <bdi dir="ltr">{{ $item['eligible_rial'] }}</bdi> ریال · یک‌پنجم دقیق: <bdi dir="ltr">{{ $item['one_fifth_exact_rial'] }}</bdi> ریال · قاعده <bdi>{{ $item['rule_id'] }}</bdi></p>
                    @endforeach
                </div>
            @endif
            @if ($closedClosureId !== null && $settlementTermsIssue)
                <p role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">مستند مبلغ پرداختی با تعهد سازگار نیست؛ تا بررسی یا بازیابی مستند، ثبت پرداخت و تأیید پایه متوقف است.</p>
            @endif
            @if ($closedClosureId !== null && ! $settlementTermsIssue)
                @if ($settlementTerms && $settlementTerms['decision_id'] !== null)
                    <div class="space-y-2 rounded-xl border border-amber-200 p-4 text-sm leading-7" aria-label="مبلغ پرداختی مستند موردی">
                        <p>محاسبهٔ دقیقِ ثابت: <bdi dir="ltr">{{ $settlementTerms['computed_due_exact_rial'] }}</bdi> ریال · مبلغ پرداختیِ مورد تأیید پرونده: <bdi dir="ltr">{{ $settlementTerms['payable_due_exact_rial'] }}</bdi> ریال</p>
                        <p>مستند موردی شمارهٔ {{ $settlementTerms['decision_id'] }}: {{ $settlementTerms['case_reference'] }}</p>
                        <p>ثبت این مستند، پرداخت واقعی یا تأیید مخمّس‌شدن دارایی نیست.</p>
                    </div>
                @elseif ($settlementTerms && $settlementTerms['fractional'])
                    <p class="rounded-lg bg-amber-50 p-3 text-sm leading-7 text-amber-900">این تعهد کسری ریال دارد. برنامه بدون مستند موردی مبلغ را گرد نمی‌کند؛ بخش صحیح قابل پرداخت است و تعیین تکلیف باقی‌مانده مستقل خواهد بود.</p>
                @endif
                @if ($termsRules !== [] && $settlementTerms && $settlementTerms['fractional'])
                    <details class="space-y-3 rounded-xl border border-amber-200 p-4">
                        <summary class="cursor-pointer font-bold">ثبت مستند موردی مبلغ پرداختی برای کسری ریال</summary>
                        <p class="text-sm leading-7">فقط مبلغ و مجوز مربوط به همین تعهد را ثبت کنید. این مسیر حکم عمومی گردکردن نیست و محاسبهٔ ثبت‌شده را تغییر نمی‌دهد.</p>
                        <button type="button" wire:click="prepareSettlementTerms" wire:loading.attr="disabled" class="button-primary">بررسی تعهد و مستند مبلغ</button>
                        @error('settlementTerms')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                        @if ($termsPreview)
                            <form wire:submit="confirmSettlementTerms" class="space-y-3">
                                <p class="text-sm">محاسبهٔ دقیق: {{ $termsPreview['terms']['computed_due_exact_rial'] }} ریال؛ پرداخت ثبت‌شده: {{ $termsPreview['settled_rial'] }} ریال.</p>
                                <label class="block text-sm">قاعدهٔ اعتبارسنجی‌شده برای این نوع پرونده
                                    <select wire:model.live="termsRuleId" class="mt-1 block w-full rounded-lg border p-2">
                                        <option value="">انتخاب کنید</option>
                                        @foreach ($termsRules as $rule)<option value="{{ $rule['id'] }}">{{ $rule['title'] ?? $rule['id'] }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">مبلغ صحیحِ مورد تأیید پرونده، به ریال<input wire:model="termsPayableRial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2"></label>
                                <label class="block text-sm">مرجع و شناسه یا شرح مستند همین پرونده<textarea wire:model="termsCaseReference" rows="2" class="mt-1 block w-full rounded-lg border p-2"></textarea></label>
                                @foreach ($termsFactLabels as $index => $label)
                                    <label class="block text-sm">{{ $label }}<textarea wire:model="termsFacts.{{ $index }}" rows="2" class="mt-1 block w-full rounded-lg border p-2"></textarea></label>
                                @endforeach
                                @if ($termsPreview['terms']['decision_id'] !== null)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="termsRevisionApproved">اصلاح مستقل مستند قبلی با حفظ تاریخچه را تأیید می‌کنم.</label>
                                    <label class="block text-sm">دلیل اصلاح<textarea wire:model="termsRevisionReason" rows="2" class="mt-1 block w-full rounded-lg border p-2"></textarea></label>
                                @endif
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="termsApproved">وجود و دامنهٔ مجوز همین مبلغ برای همین تعهد را تأیید می‌کنم.</label>
                                <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت مستند بدون ایجاد پرداخت</button>
                            </form>
                        @endif
                    </details>
                @endif
                <form wire:submit="payKhums" class="space-y-3 rounded-xl border border-slate-200 p-4">
                    <h3 class="font-bold">ثبت پرداخت واقعی برای نسخهٔ انتخاب‌شده</h3>
                    <p class="text-sm text-amber-800">پرداخت، وضعیت مبنای مخمّس را بدون بررسی روش و شواهد تغییر قطعی نمی‌دهد.</p>
                    <label class="block text-sm">حساب پرداخت‌کننده
                        <select wire:model="paymentAccountId" class="mt-1 block w-full rounded-lg border p-2">
                            <option value="">انتخاب کنید</option>
                            @foreach ($paymentAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">تاریخ پرداخت شمسی <input wire:model="paymentDate" dir="ltr" class="mt-1 block w-full rounded-lg border p-2"></label>
                    <label class="block text-sm">مبلغ پرداخت به ریال <input wire:model="paymentAmountRial" inputmode="numeric" dir="ltr" class="mt-1 block w-full rounded-lg border p-2"></label>
                    <label class="block text-sm">روش اظهارشده
                        <select wire:model="paymentMethod" class="mt-1 block w-full rounded-lg border p-2">
                            <option value="from_same_asset">از خود مال</option>
                            <option value="from_other_protected">از مال مخمّس دیگر</option>
                            <option value="from_current_income">از درآمد جاری</option>
                        </select>
                    </label>
                    <label class="block text-sm">مدرک یا توضیح پرداخت <input wire:model="paymentEvidence" class="mt-1 block w-full rounded-lg border p-2"></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="paymentApproved">این پرداخت واقعاً انجام شده و ثبت آن را تأیید می‌کنم.</label>
                    <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت پرداخت و تخصیص به تعهد</button>
                </form>
                @if ($basisCarryovers !== [] && collect($activeRules)->contains('id', 'K01'))
                    <form wire:submit="resolveBasis" class="space-y-3 rounded-xl border border-teal-200 p-4">
                        <h3 class="font-bold">بررسی پایهٔ مخمّس پس از تسویهٔ کامل</h3>
                        <p class="text-sm text-slate-700">این مسیر فقط برای حسابی با گردش ساده و قاعدهٔ K01 اعتبارسنجی‌شده فعال است؛ گردش پیچیده نیازمند بررسی جدا می‌ماند.</p>
                        <label class="block text-sm">حساب
                            <select wire:model="basisAccountId" class="mt-1 block w-full rounded-lg border p-2">
                                <option value="">انتخاب کنید</option>
                                @foreach ($basisCarryovers as $carryover)
                                    <option value="{{ $carryover->account_id }}">{{ $carryover->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        @foreach ($activeRules as $rule)
                            @if ($rule['id'] === 'K01')
                                @foreach ($rule['required_facts'] as $index => $fact)
                                    <label class="block text-sm">{{ $fact }}<textarea wire:model="basisFacts.{{ $index }}" class="mt-1 block w-full rounded-lg border p-2" rows="2"></textarea></label>
                                @endforeach
                            @endif
                        @endforeach
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="basisApproved">شواهد و پایهٔ باقی‌مانده را بررسی و تأیید می‌کنم.</label>
                        <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت پایهٔ بررسی‌شده</button>
                    </form>
                @endif
                @if ($reconciliationNeeded)
                    <div class="space-y-3 rounded-xl border border-amber-300 p-4">
                        <h3 class="font-bold">تطبیق پرداخت‌های پیش از اصلاحیه</h3>
                        <p class="text-sm leading-7">پرداخت‌های واقعی قبلی حفظ شده‌اند. تخصیص آنها به تعهد جدید فقط پس از دیدن اختلاف و تأیید مستقل انجام می‌شود؛ اضافه‌پرداخت احتمالی جداگانه نیازمند بررسی می‌ماند.</p>
                        <button type="button" wire:click="previewReconciliation" class="rounded-lg border border-amber-700 px-3 py-2 text-sm font-bold">نمایش تخصیص پیشنهادی</button>
                        @if ($reconciliationPreview)
                            @if (($reconciliationPreview['settlement_terms_decision_id'] ?? null) !== null)
                                <p class="text-sm leading-7">محاسبهٔ دقیق ثبت‌شده {{ $reconciliationPreview['computed_due_exact_rial'] }} ریال است؛ تخصیص زیر از مبلغ پرداختیِ مستند شمارهٔ {{ $reconciliationPreview['settlement_terms_decision_id'] }} استفاده می‌کند.</p>
                            @endif
                            <p class="text-sm leading-7">تعهد جدید: <bdi dir="ltr">{{ $reconciliationPreview['due_exact_rial'] }}</bdi> ریال؛ پرداخت‌های قبلی: <bdi dir="ltr">{{ $reconciliationPreview['prior_paid_rial'] }}</bdi>؛ قابل تخصیص: <bdi dir="ltr">{{ $reconciliationPreview['allocated_rial'] }}</bdi>؛ باقیمانده: <bdi dir="ltr">{{ $reconciliationPreview['remaining_exact_rial'] }}</bdi>؛ اضافهٔ تخصیص‌نیافته: <bdi dir="ltr">{{ $reconciliationPreview['excess_unallocated_rial'] }}</bdi>.</p>
                            @if ($reconciliationPreview['excess_unallocated_rial'] !== '0')
                                <p class="text-sm leading-7 text-amber-900">این اضافه‌پرداخت خودکار از خمس سال بعد کم نمی‌شود. برای بررسی شرایط و مجوز موردی، <a href="https://www.sistani.org/persian/book/26576/6865/" target="_blank" rel="noopener noreferrer" class="underline">مسئلهٔ ۸۵۵ و پاورقی‌ها در منبع رسمی</a> را ببینید. تأیید تطبیق زیر، مجوز انتقال اعتبار یا ثبت استرداد نیست.</p>
                            @endif
                            <form wire:submit="approveReconciliation" class="space-y-2">
                                <label class="block text-sm">دلیل تطبیق <input wire:model="reconciliationReason" class="mt-1 block w-full rounded-lg border p-2"></label>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="reconciliationApproved">مبالغ و اضافه‌پرداخت احتمالی را بررسی کردم و این تخصیص را تأیید می‌کنم.</label>
                                <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت تطبیق بدون ایجاد پرداخت تازه</button>
                            </form>
                        @endif
                    </div>
                @endif
                @if ($savedPeriodReviewId !== null && $periodReport && $periodReport['status'] === 'computed')
                    <div class="space-y-3 rounded-xl border border-amber-200 p-4">
                        <h3 class="font-bold">اصلاح سال بسته‌شده</h3>
                        <button type="button" wire:click="previewAmendment" class="rounded-lg border border-amber-700 px-3 py-2 text-sm font-bold">نمایش اختلاف نسخهٔ تازه با بسته‌شده</button>
                        @if ($amendmentDiff)
                            <p class="text-sm">تعهد پیشین: <bdi dir="ltr">{{ $amendmentDiff['old_due_exact_rial'] }}</bdi>؛ تعهد تازه: <bdi dir="ltr">{{ $amendmentDiff['new_due_exact_rial'] }}</bdi>؛ اختلاف: <bdi dir="ltr">{{ $amendmentDiff['delta_exact_rial'] }}</bdi> ریال. پرداخت ثبت‌شدهٔ قبلی: <bdi dir="ltr">{{ $amendmentDiff['prior_settled_rial'] }}</bdi> ریال. سال‌های وابسته: {{ count($amendmentDiff['dependent_closure_ids']) }}.</p>
                            <form wire:submit="approveAmendment" class="space-y-2">
                                <label class="block text-sm">دلیل اصلاح <input wire:model="amendmentReason" class="mt-1 block w-full rounded-lg border p-2"></label>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="amendmentApproved">اختلاف و آثار نسخهٔ تازه را تأیید می‌کنم.</label>
                                <button type="submit" wire:loading.attr="disabled" class="button-primary">ثبت اصلاحیه با حفظ نسخه و پرداخت‌های قبلی</button>
                            </form>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endif

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
            <p class="rounded-xl bg-amber-50 p-4 text-sm leading-7 text-amber-950">مبلغ خمس قابل اعلام نیست: مبنای شرعی اقلام هنوز تأیید نشده و هیچ قاعدهٔ محاسباتی این نسخه فعال نیست. این شبیه‌سازی روزانه جای گزارش دقیق مرز سال را نمی‌گیرد. صفر، به معنی معافیت نیست.</p>
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
