# نصب و اجرای زیرساخت فاز ۱

## تغییر schema فاز ۶

migration پنجم CPI پس از backup رمزدار و restore drill روی پایگاه محلی اعمال شد. هیچ داده شاخص واقعی seed نشده است. برای داده موجود، پیش از migration در دستگاه دیگر هم backup سازگار و بازیابی آزمایشی لازم است. مسیر `/cpi` فقط پس از ورود مالک قابل دسترسی است.


## فاز ۵ب — 2026-10-14

فاز ۵ب migration و dependency تازه ندارد. صفحه خصوصی `/cash/import` فایل CSV UTF-8 تا ۲۰۰ سطر/۲۵۶ KiB را از طریق Livewire روی disk خصوصی local دریافت می‌کند؛ session رمزگذاری‌شده برای preview استفاده می‌شود. CSV نمونه را فقط با داده ساختگی بسازید. ستون‌های پیش‌فرض و کدهای نوع عملیات در صفحه آمده‌اند؛ نام حساب/دسته باید دقیق باشد. `composer test`, `composer format:check`, `php artisan view:cache` و `npm run build` بررسی‌های این مرحله‌اند.


## تکمیل اجرایی فاز ۵ — 2026-10-14

تکمیل فاز ۵ migration تازه‌ای نداشت. صفحه `/review` برای اصلاحیهٔ مقدار/اظهار مخمّس و تسویهٔ اصل تعهد ارزی گسترش یافت. برای مرور بصری با داده ساختگی، صفحه‌های `/setup/cash`, `/cash`, `/assets`, `/review` را در عرض موبایل و دسکتاپ، با صفحه‌کلید و ارقام فارسی/لاتین بررسی کنید. Chrome headless در sandbox این محیط با خطای مجوز `setsockopt` اجرا نشد؛ نتیجهٔ مرور بصری موفق اعلام نمی‌شود.


## بررسی فاز ۵ — 2026-10-14

پیش از migration چهارم، از SQLite فعالِ بدون عملیات مالی backup رمزدار گرفته و verify/restore آزمایشی انجام شد. فایل‌ها در `storage/app/private/backups/before-phase5-*.khfb` و `storage/app/private/recovery-before-phase5-*.key` با مجوز خصوصی‌اند؛ کلید را جدا از دستگاه نگه دارید. schema چهارم اعمال و یک مالک/صفر عملیات مالی، integrity و FK بررسی شد. فرم‌های `/assets` و `/review` فقط با داده ساختگی بررسی شوند؛ اصلاح مقدار/وضعیت مخمّس افتتاحیه و تعهد ارزی بعد از افتتاحیه هنوز آماده نیست. `composer test -- --compact`, `composer format:check`, `npm run build` فرمان‌های بررسی‌اند.


## بررسی فاز ۴ — 2026-10-14

پایگاه محلی پس از backup رمزدار و restore drill به migration سوم ارتقا یافت؛ یک مالک حفظ شد و جداول عملیات/دارایی/قیمت خالی‌اند. پشتیبان پیش از فاز ۴ در `storage/app/private/backups/before-phase4-*.khfb` و کلید بازیابی در `storage/app/private/recovery-before-phase4-*.key` با مجوز 0600 و خارج Git هستند. کلید باید جدا از دستگاه/backup نگهداری شود؛ محتوا را در گزارش یا Git نگذارید. برای بررسی فرم‌ها از داده ساختگی استفاده کنید. `composer test`, `composer format:check`, `npm run build` فرمان‌های تأیید هستند. ورود مالی واقعی و فروش/تبدیل فعلاً فعال/توصیه نشده‌اند.

## اجرای فاز ۳ در همین محیط — 2026-10-14

کاربر فاز ۲ را تأیید و فاز ۳ را مجاز کرد. migration نقدی ریالی روی SQLite محلی اجرا شد؛ یک مالک موجود حفظ شد و جدول‌های مالی تازه خالی‌اند. پیش از migration، `backup:create` معادل با رمز تصادفی اجرا و `verify`/`restore-empty` روی فایل موقت انجام شد. bundle در `storage/app/private/backups/before-phase3-*.khfb` و رمز بازیابی در `storage/app/private/recovery-before-phase3-*.key` با مجوز 0600 است؛ **محتوای کلید را چاپ یا وارد Git نکنید** و یک نسخه امن جدا از دستگاه نگه دارید. این رمز مستقل از `APP_KEY` است. اگر بعداً migration تازه‌ای لازم شد، دوباره backup/restore drill انجام دهید.

مسیرهای خصوصی `/setup/cash` و `/cash` برای بررسی با **داده ساختگی** آماده‌اند. راه‌انداز فعلاً فقط ریال نقد/بانک/کارگزاری/کیف پول و اصل بدهی/طلب را دارد. روی پایگاه شخصی افتتاحیه آزمایشی را تأیید نکنید؛ چون دارایی‌های مقداری و ورود گذشته با replay تا فازهای ۴–۵ تکمیل نمی‌شوند. برای آزمون end-to-end از پایگاه SQLite جداگانه و نسخه پشتیبان استفاده کنید. عملیات ثبت‌شده تاریخ‌گذشته قبل از آخرین سند را رد می‌کند؛ ساعت مرز سال خمسی تأیید نشده است.

فرمان‌های بررسی: `composer test -- --compact`، `composer format:check`، `npm run build` و `php artisan route:list --except-vendor`. نتایج و شمار آزمون‌ها در [PROGRESS](PROGRESS.md) ثبت‌اند. پشتیبان نسخه ۲ schema تازه و نسخه ۱ پیشین را پشتیبانی می‌کند؛ هیچ داده مالی واقعی در آزمون‌ها استفاده نشد.

## افزوده فاز ۲ — 2026-10-14

برای اعداد/تقویم/پشتیبان فاز ۲، PHP 8.4 با `ext-intl` و `ext-sodium` نیز لازم است؛ `composer check:environment` و `composer check-platform-reqs` آنها را بررسی می‌کنند. بدون نصب package جدید، `brick/math` موجود به‌کار می‌رود. ساختار DB موجود تغییر نکرده است.

پشتیبان‌گیری اختیاری فقط از خط فرمان محلی انجام می‌شود. گذرواژه پشتیبان دست‌کم ۱۶ بایت باشد و مستقل از رمز ورود/`APP_KEY`، در محل امن و جدا نگهداری شود. فرمان‌ها گذرواژه را مخفی می‌خوانند؛ آن را به‌صورت آرگومان shell ننویسید:

```bash
php artisan backup:create
php artisan backup:verify storage/app/private/backups/NAME.khfb
php artisan backup:restore-empty storage/app/private/backups/NAME.khfb
```

نام فایل را از خروجی `backup:create` بردارید. بازیابی به `storage/app/private/restores/` و **فایل جدید** انجام می‌شود؛ DB فعال دست‌نخورده است. برای اتکا به پشتیبان، فرمان verify و دست‌کم یک restore آزمایشی لازم‌اند. هیچ جایگزینی خودکار DB فعال یا برنامه نگهداری دوره‌ای در فاز ۲ وجود ندارد. snapshot موقت هنگام کار در پوشه موقت سیستم با مجوز 0600 ساخته و بعد حذف می‌شود؛ رمز/کلید در bundle ذخیره نمی‌شود. پس از افزودن migration در فازهای بعد، پشتیبان نسخه ۱ تا ارتقای صریح schema سازگار نیست. فایل `.khfb` در Git نادیده گرفته می‌شود.

2026-10-13 — این راهنما برای کد آماده‌شده فاز ۱ است. اجرای backend Laravel و ۳۷ تست موفق است؛ مرور بصری در محیط کاربر باقی است؛ جزئیات در [PROGRESS](PROGRESS.md).

## وضعیت نسخه‌ها و سازگاری

| جزء | نسخه/قید | وضعیت واقعی این تحویل |
|---|---|---|
| PHP | 8.4.26 در محیط؛ قید پروژه `~8.4.0` | موجود؛ Argon2id و pdo_sqlite فعال؛ preflight موفق |
| Composer | 2.9.5 | وابستگی‌ها توسط کاربر نصب؛ validate و platform requirements موفق |
| Laravel | 13.35.0؛ قید `^13.0` | نصب و ثبت در composer.lock، runtime موفق |
| Livewire | 4.4.7؛ قید `^4.4.7` | نصب و تست‌های component/update موفق؛ browser review باقی است |
| Pest / Laravel plugin | 4.7.8 / 4.1.0 | نصب و ۳۷ تست/۲۱۶ assertion موفق |
| Pint | 1.32.1؛ قید `^1.32.1` | نصب در vendor؛ composer format و format:check موفق |
| Node / npm | 22.22.3 / 12.2.0 | موجود؛ با نیاز Node 22.12+ ابزارهای انتخابی سازگار |
| Vite / laravel-vite-plugin | 8.0.16 / 3.1.0 | نصب از کَش، ثبت در package-lock و Build موفق |
| Tailwind / @tailwindcss/vite | 4.3.0 / 4.3.0 | نصب از کَش و Build موفق؛ تنظیم Vite مخصوص Tailwind 4 |
| Alpine | همراه Livewire 4 | dependency مستقل ندارد؛ اجرای مرورگری پس از نصب Livewire باید بررسی شود |
| SQLite | اتصال PDO با foreign keys و busy timeout | PDO SQLite 3.45.1؛ migration جدید و تست‌های FK موفق |
| فونت | Noto Sans Arabic معمولی و ضخیم، محلی | فایل‌ها و مجوز OFL در resources/fonts؛ در Build بسته‌بندی شدند |

وابستگی‌های اصلی انتخابی MIT هستند؛ مجوزهای transitive از composer licenses و فایل‌های vendor بررسی شدند؛ گزینه BSD در مجوز چندگانه Nette انتخاب شد. از جابه‌جایی نسخه اصلی برای رفع نبود کَش یا نادیده‌گرفتن platform requirements استفاده نشده است.

منابع بررسی: [Laravel 13](https://laravel.com/framework/docs/13.x/releases)، [manifest اسکلت رسمی](https://github.com/laravel/laravel/blob/master/composer.json)، [manifest Livewire 4.4.7](https://github.com/livewire/livewire/blob/v4.4.7/composer.json)، [Pest](https://pestphp.com/docs/installation)، [manifest افزونه Laravel برای Pest](https://github.com/pestphp/pest-plugin-laravel/blob/4.x/composer.json)، [Tailwind و Vite](https://tailwindcss.com/docs/installation/using-vite). اطلاعات package.json نصب‌شده Vite/Tailwind نیز برای نسخه، engines و MIT خوانده شد. این بررسی جای اجرای runtime هدف را نمی‌گیرد.

## پیش‌نیازهای محیط

در Ubuntu، بسته سازگار `php8.4-sqlite3` باید برای همان PHP CLI و در صورت استفاده، PHP-FPM فعال باشد. اسکریپت `php scripts/check-environment.php` افزونه‌های لازم و Argon2id را بدون اتصال به داده شخصی بررسی می‌کند. بسته سیستم را کاربر نصب کرد؛ ابزار فقط فعال‌بودن driver را بررسی کرد.

نصب اولیه Composer و npm به دسترسی به مخازن بسته‌ها یا کَش کامل همان نسخه‌ها نیاز دارد. استفاده معمول برنامه پس از نصب و Build به اینترنت یا API خارجی وابسته نیست. کاربر نصب PHP را در ترمینال Ubuntu انجام داد و composer.lock تولید شد؛ در نشست ابزار هنوز محدودیت شبکه/پورت برقرار است، ولی برای تست‌های نصب‌شده نیاز به دانلود دوباره نداریم.

## راه‌اندازی جدید

فرمان‌های زیر پس از رفع پیش‌نیازها، برای یک پایگاه جدید و بدون داده شخصی هستند. در محیط فعلی migration هویت روی DB جدید اجرا شده و .env/کلید خصوصی آماده‌اند. برای پایگاه موجود، قبل از migration پشتیبان سازگار و بازیابی آزموده لازم است؛ آن روند در فاز ۲ توسعه می‌یابد.

```bash
php scripts/check-environment.php
cp -n .env.example .env
chmod 600 .env
composer install
php artisan key:generate
mkdir -p storage/app/private
chmod 700 storage/app/private
touch storage/app/private/database.sqlite
chmod 600 storage/app/private/database.sqlite
php artisan migrate
php artisan owner:create
npm ci
npm run build
php artisan serve --host=127.0.0.1
```

برنامه در `http://127.0.0.1:8000` باز می‌شود. owner:create نام کاربری و رمز/تکرار مخفی را دریافت می‌کند؛ رمز در آرگومان فرمان یا فایل نمونه قرار نمی‌گیرد. نام کاربری ۳ تا ۶۴ نویسه لاتین/رقم/نقطه/خط تیره/زیرخط، و رمز حداقل ۱۲ و حداکثر ۱۲۸ نویسه است؛ عبارت طولانی مجاز است. مالک دوم ساخته نمی‌شود. پایگاه محلی این تحویل بدون مالک است؛ داده نمونه فقط در تست‌های memory/tmp بود.

composer.lock اکنون با resolve واقعی تولید شده و باید در Git نگهداری شود؛ نسخه‌های ثبت‌شده آن مبنای نصب تکرارپذیرند. package-lock.json موجود است و `npm ci` مسیر تکرارپذیر frontend خواهد بود.

## ورود، نشست و بازیابی دسترسی

GET /login فرم Livewire را نشان می‌دهد؛ POST /login مسیر عادی بدون JavaScript با همان Action ورود است. هر دو اعتبارسنجی سرور و محدودسازی مشترک دارند. فرم POST و CSRF دارد تا نبود JavaScript باعث ارسال رمز در URL نشود. پس از ورود تنها به dashboard هدایت می‌شود؛ redirect خارجی از ورودی کاربر پذیرفته نمی‌شود.

در یک دقیقه، پنج تلاش برای ترکیب نام کاربری نرمال‌شده/IP و بیست تلاش کل برای IP مجاز است؛ محدودکننده file cache در اجرای معمول بین درخواست‌ها پایدار می‌ماند. پیام نام کاربری ناشناخته، رمز غلط یا مالک غیرفعال یکسان است. رفتار سقف پنج تلاش، تغییر نام کاربری برای سقف IP و cooldown در Pest موفق‌اند؛ این نتیجه ضمانت سقف مطلق در تمام همزمانی‌های distributed نیست و برنامه محلی است.

نشست database در فاز ۱ ثابت است تا ابطال تمام نشست‌های مالک در بازیابی رمز معنی مشخص داشته باشد. payload با APP_KEY رمز می‌شود؛ lifetime سی دقیقه، انقضا با بستن مرورگر، HttpOnly و SameSite=strict است. ورود session ID را می‌چرخاند؛ خروج POST، session و CSRF را باطل می‌کند. middleware `auth.session` تغییر رمز را نیز بررسی می‌کند. middleware مالک و نشست روی updateهای Livewire دوباره اجرا می‌شوند.

```bash
php artisan owner:reset-password
```

این فرمان با رمز مخفی، رمز مالک را عوض و نشست‌های database او را در همان تراکنش حذف می‌کند؛ مالک غیرفعال را خودکار فعال نمی‌کند. دسترسی به shell/APP_KEY/پایگاه فقط برای مالک دستگاه باشد. حساب و password_hash در log یا خروجی فرمان چاپ نمی‌شوند.

## فایل‌های خصوصی و اجرای آینده روی سرور

SQLite پیش‌فرض در `storage/app/private/database.sqlite` است؛ DB_DATABASE می‌تواند مسیر خصوصی مطلق تعیین کند. local disk قابل serve نیست و لینک public/storage نداریم. `.env`، SQLite/WAL، cache نشست، log، backup، receipt و export در ignore قرار دارند؛ اطلاعات محرمانه در `.env.example` نیست.

اجرای توسعه فقط روی loopback است. برای استقرار آینده، public تنها web root و HTTPS الزامی است؛ APP_ENV=production باعث اجبار Secure cookie و غیرفعال‌شدن debug در provider می‌شود. تنظیم APP_URL، دسترسی فایل‌های خصوصی و کاربر PHP-FPM باید متناسب همان میزبان بررسی شود. این فاز استقرار سرور انجام نداده است.

## بررسی‌های قابل تکرار

```bash
composer validate --strict --no-check-publish
composer check:environment
composer test
composer format
composer format:check
npm ci
npm run build
git diff --check
```

Pest با PHPUnit config اجباریِ testing و SQLite in-memory اجرا می‌شود؛ TestCase پیش از RefreshDatabase، اتصال غیرآزمایشی یا config cache واقعی را رد می‌کند. Factory فقط داده ساختگی دارد؛ هیچ seed خودکار مالک وجود ندارد. CSRF در دو تست عمداً از bypass پیش‌فرض testing خارج می‌شود، ولی اتصال همان SQLite آزمایشی باقی می‌ماند.

تست‌های backend و HTTP آفلاین موفق‌اند؛ پذیرش بصری موبایل/دسکتاپ، صفحه‌کلید و مرورگر واقعی در محیط کاربر باقی است. فاز ۲ تا تحویل موفق فاز ۱ و تأیید جدا شروع نمی‌شود.

## اجرای سریع همین محیط و پذیرش رابط

.env و جداول هویت تازه آماده‌اند و کلید قبلی باید حفظ شود. برای شروع روی همین سیستم، فقط owner:create (اگر هنوز مالک وجود ندارد) و serve لازم‌اند:

```bash
cd /home/irantech/Projects/khums-tracker
php artisan owner:create
php artisan serve --host=127.0.0.1
```

در مرورگر Ubuntu به http://127.0.0.1:8000 بروید. ورود با رمز درست/غلط، پیام فارسی، Tab/Enter و خروج را بررسی کنید؛ با عرض ۳۶۰ و دسکتاپ خوانایی/عدم overflow را ببینید. با سرور محلی روشن و اینترنت قطع، صفحات و فونت باید بارگیری شوند. نتیجه این بررسی انسانی برای تأیید نهایی فاز ۱ ثبت خواهد شد.

Chrome این نشست به permission در setsockopt و Firefox به محدودیت snap برخورد کردند؛ سرور TCP هم مجاز نبود. این محدودیت سبب استفاده از HTTP kernel مستقیم برای تست backend شد؛ هیچ نتیجه موفق browser interaction گزارش نشده است.
