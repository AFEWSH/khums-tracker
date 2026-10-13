<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'ورود' }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh">
    <a href="#main-content" class="sr-only z-50 rounded-xl bg-teal-900 p-3 text-white focus:not-sr-only focus:fixed focus:right-4 focus:top-4">رفتن به محتوای اصلی</a>
    <header class="mx-auto max-w-6xl px-5 py-6 sm:px-8">
        <x-brand />
    </header>
    <main id="main-content" class="mx-auto grid min-h-[75dvh] max-w-6xl items-center gap-12 px-5 pb-12 sm:px-8 lg:grid-cols-2 lg:gap-20">
        <section class="hidden lg:block" aria-labelledby="welcome-title">
            <span class="inline-flex rounded-full bg-teal-100 px-4 py-2 text-xs font-bold text-teal-900">ساده، شخصی، خصوصی</span>
            <h1 id="welcome-title" class="mt-7 text-4xl leading-[1.8] font-bold tracking-tight text-slate-950">فضایی برای نظم<br>در امور مالی شما</h1>
            <p class="mt-5 max-w-md text-base leading-9 text-slate-600">اطلاعات شخصی شما در فضای خصوصی برنامه نگهداری می‌شود. برای ادامه، با حساب مالک وارد شوید.</p>
            <div class="mt-10 flex items-center gap-3 text-sm text-slate-500">
                <span class="size-2 rounded-full bg-teal-600" aria-hidden="true"></span>
                <span>استفاده روزمره بدون نیاز به اینترنت</span>
            </div>
        </section>
        <div class="w-full max-w-md justify-self-center lg:justify-self-end">
            {{ $slot }}
        </div>
    </main>
    @livewireScripts
</body>
</html>
