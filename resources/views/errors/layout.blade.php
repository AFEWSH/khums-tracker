<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title') | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh">
    <header class="mx-auto max-w-6xl px-5 py-6 sm:px-8"><x-brand /></header>
    <main class="mx-auto max-w-lg px-5 py-16 text-center">
        <section class="panel p-8">
            <p class="text-sm font-bold text-teal-800">@yield('code')</p>
            <h1 class="mt-4 text-2xl leading-relaxed font-bold">@yield('title')</h1>
            <p class="mt-4 text-sm leading-8 text-slate-600">@yield('message')</p>
            <a href="{{ url('/') }}" class="button-primary mt-7">بازگشت به برنامه</a>
        </section>
    </main>
</body>
</html>
