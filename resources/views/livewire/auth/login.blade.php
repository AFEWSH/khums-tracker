<section class="panel p-6 sm:p-9" aria-labelledby="login-title">
    <div class="mb-8">
        <h2 id="login-title" class="text-2xl font-bold text-slate-950">ورود به حساب</h2>
        <p class="mt-3 text-sm leading-7 text-slate-600">برای دسترسی به فضای شخصی خود وارد شوید.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" wire:submit="authenticate" class="space-y-6">
        @csrf
        <div>
            <label for="login" class="mb-2 block text-sm font-bold">نام کاربری</label>
            <input id="login" name="login" type="text" wire:model="login" dir="ltr" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="64" autofocus class="form-input text-left" aria-invalid="{{ $errors->has('login') ? 'true' : 'false' }}" aria-describedby="login-error">
            <p id="login-error" class="mt-2 text-sm leading-7 text-red-700" role="alert">@error('login'){{ $message }}@enderror</p>
        </div>

        <div>
            <label for="password" class="mb-2 block text-sm font-bold">رمز عبور</label>
            <input id="password" name="password" type="password" wire:model="password" dir="ltr" autocomplete="current-password" required maxlength="128" class="form-input text-left" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" aria-describedby="password-error">
            <p id="password-error" class="mt-2 text-sm leading-7 text-red-700" role="alert">@error('password'){{ $message }}@enderror</p>
        </div>

        <button type="submit" class="button-primary w-full" wire:loading.attr="disabled" wire:target="authenticate">
            <span wire:loading.remove wire:target="authenticate">ورود به برنامه</span>
            <span wire:loading wire:target="authenticate" role="status">در حال بررسی…</span>
        </button>

        <p wire:offline class="rounded-xl bg-amber-50 p-3 text-sm leading-7 text-amber-900" role="status">ارتباط با برنامه قطع شده است؛ اتصال محلی را بررسی کنید.</p>
    </form>

    <p class="mt-7 border-t border-slate-100 pt-5 text-xs leading-7 text-slate-500">این برنامه یک حساب مالک دارد. بازیابی رمز عبور از طریق مدیریت محلی برنامه انجام می‌شود.</p>
</section>
