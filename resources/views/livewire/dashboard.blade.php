<div class="space-y-8">
    <section aria-labelledby="dashboard-title">
        <p class="text-sm font-bold text-teal-800">فضای شخصی شما</p>
        <h1 id="dashboard-title" class="mt-3 text-3xl leading-relaxed font-bold text-slate-950">خوش آمدید</h1>
        <p class="mt-3 max-w-2xl text-sm leading-8 text-slate-600">ورود شما انجام شد. نسخه آزمایشی راه‌اندازی، حساب‌ها و دارایی‌های مقداری برای بررسی فاز ۴ آماده است. برای استفاده از داده واقعی تا تکمیل فاز ۵ صبر کنید.</p>
        <div class="mt-5 flex flex-wrap gap-3"><a class="button-primary" href="{{ route('cash.setup') }}">راه‌اندازی</a><a class="button-secondary" href="{{ route('cash.workspace') }}">حساب‌ها و ثبت</a><a class="button-secondary" href="{{ route('assets.workspace') }}">دارایی‌ها</a><a class="button-secondary" href="{{ route('finance.review') }}">مرور و گزارش</a></div>
    </section>

    <section class="panel overflow-hidden" aria-labelledby="workspace-title">
        <div class="grid gap-8 p-6 sm:p-8 md:grid-cols-[1fr_auto] md:items-center">
            <div>
                <span class="inline-flex items-center gap-2 rounded-full bg-teal-50 px-3 py-1.5 text-xs font-bold text-teal-800">
                    <span class="size-1.5 rounded-full bg-teal-600" aria-hidden="true"></span>
                    وارد حساب شده‌اید
                </span>
                <h2 id="workspace-title" class="mt-5 text-xl font-bold text-slate-900">حساب مالک</h2>
                <p class="mt-3 text-sm leading-8 text-slate-600">نام کاربری: <bdi class="font-bold text-slate-900">{{ auth()->user()->login }}</bdi></p>
            </div>
            <div class="flex size-24 items-center justify-center rounded-3xl bg-slate-50 text-teal-800" aria-hidden="true">
                <svg class="size-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                    <path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z" stroke-linejoin="round" />
                    <path d="m8.5 12 2.2 2.2 4.8-4.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
        </div>
        <div class="border-t border-slate-100 bg-slate-50/50 px-6 py-4 text-xs leading-7 text-slate-500 sm:px-8">پس از پایان کار، از حساب خارج شوید؛ به‌ویژه اگر دستگاه در اختیار دیگران قرار می‌گیرد.</div>
    </section>
</div>
