<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'خانه' }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh">
    <a href="#main-content" class="sr-only z-50 rounded-xl bg-teal-900 p-3 text-white focus:not-sr-only focus:fixed focus:right-4 focus:top-4">رفتن به محتوای اصلی</a>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <x-brand />
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button-secondary gap-2">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M10 4H4v16h6M14 8l4 4-4 4m-5-4h9" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    خروج از حساب
                </button>
            </form>
        </div>
        <nav aria-label="منوی اصلی" class="mx-auto max-w-6xl px-5 sm:px-8">
            <a href="{{ route('dashboard') }}" aria-current="page" class="inline-flex min-h-12 items-center border-b-2 border-teal-700 px-2 text-sm font-bold text-teal-900">خانه</a>
            <a href="{{ route('cash.setup') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">راه‌اندازی</a>
            <a href="{{ route('cash.workspace') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">حساب‌ها</a>
            <a href="{{ route('cash.import') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">ورود CSV</a>
            <a href="{{ route('assets.workspace') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">دارایی‌ها</a>
            <a href="{{ route('finance.review') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">مرور و گزارش</a>
            <a href="{{ route('cpi.workspace') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">شاخص تورم</a>
            <a href="{{ route('rulings.index') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">احکام</a>
            <a href="{{ route('khums.workspace') }}" class="inline-flex min-h-12 items-center px-3 text-sm font-bold text-slate-700 hover:text-teal-900">پیش‌نویس خمس</a>
        </nav>
    </header>
    <main id="main-content" class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
        {{ $slot }}
    </main>
    <footer class="mx-auto max-w-6xl px-5 pb-6 text-xs leading-7 text-slate-500 sm:px-8">مدیریت مالی شخصی · دسترسی خصوصی</footer>
    @livewireScripts
</body>
</html>
