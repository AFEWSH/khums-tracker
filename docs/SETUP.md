# نصب و اجرا

> **وضعیت و ترتیب کار:** [PLAN](PLAN.md). نتیجهٔ روز آزمون‌ها در [PROGRESS](PROGRESS.md) است. دادهٔ مالی واقعی تا P9 وارد نمی‌شود؛ صفحات را فقط با دادهٔ ساختگی و روی پایگاه جداگانه بررسی کنید.

## وضعیت نسخه‌ها و سازگاری

| جزء | نسخه/قید | وضعیت |
|---|---|---|
| PHP | 8.4.26 در محیط؛ قید پروژه `~8.4.0` (D34) | موجود؛ Argon2id و pdo_sqlite فعال؛ preflight موفق؛ ارتقا به 8.5 تصمیم جدا می‌خواهد |
| Composer | 2.9.5 | وابستگی‌ها توسط کاربر نصب؛ validate و platform requirements موفق |
| Laravel | 13.35.0؛ قید `^13.0` | نصب و ثبت در composer.lock، runtime موفق |
| Livewire | 4.4.7؛ قید `^4.4.7` | نصب و تست‌های component/update موفق؛ مرور مرورگری در P7 |
| Pest / Laravel plugin | 4.7.8 / 4.1.0 | نصب و اجرا موفق؛ نتیجهٔ روز در PROGRESS |
| Pint | 1.32.1؛ قید `^1.32.1` | نصب در vendor؛ composer format و format:check موفق |
| Node / npm | 22.22.3 / 12.2.0 | موجود؛ با نیاز Node 22.12+ ابزارهای انتخابی سازگار |
| Vite / laravel-vite-plugin | 8.0.16 / 3.1.0 | نصب از کَش، ثبت در package-lock و Build موفق |
| Tailwind / @tailwindcss/vite | 4.3.0 / 4.3.0 | نصب از کَش و Build موفق؛ تنظیم Vite مخصوص Tailwind 4 |
| Alpine | همراه Livewire 4 | dependency مستقل ندارد |
| SQLite | اتصال PDO با foreign keys و busy timeout | PDO SQLite 3.45.1؛ migration جدید و تست‌های FK موفق |
| فونت | Noto Sans Arabic معمولی و ضخیم، محلی | فایل‌ها و مجوز OFL در resources/fonts؛ در Build بسته‌بندی شدند |

وابستگی‌های اصلی انتخابی MIT هستند؛ مجوزهای transitive از composer licenses و فایل‌های vendor بررسی شدند؛ گزینه BSD در مجوز چندگانه Nette انتخاب شد. از جابه‌جایی نسخه اصلی برای رفع نبود کَش یا نادیده‌گرفتن platform requirements استفاده نشده است.

منابع بررسی: [Laravel 13](https://laravel.com/framework/docs/13.x/releases)، [manifest اسکلت رسمی](https://github.com/laravel/laravel/blob/master/composer.json)، [manifest Livewire 4.4.7](https://github.com/livewire/livewire/blob/v4.4.7/composer.json)، [Pest](https://pestphp.com/docs/installation)، [manifest افزونه Laravel برای Pest](https://github.com/pestphp/pest-plugin-laravel/blob/4.x/composer.json)، [Tailwind و Vite](https://tailwindcss.com/docs/installation/using-vite). اطلاعات package.json نصب‌شده Vite/Tailwind نیز برای نسخه، engines و MIT خوانده شد. این بررسی جای اجرای runtime هدف را نمی‌گیرد.

## پیش‌نیازهای محیط

در Ubuntu، بسته سازگار `php8.4-sqlite3` باید برای همان PHP CLI و در صورت استفاده، PHP-FPM فعال باشد. اسکریپت `php scripts/check-environment.php` افزونه‌های لازم و Argon2id را بدون اتصال به داده شخصی بررسی می‌کند. `ext-intl` و `ext-sodium` نیز لازم‌اند؛ `composer check:environment` و `composer check-platform-reqs` آن‌ها را بررسی می‌کنند.

نصب اولیه Composer و npm به دسترسی به مخازن بسته‌ها یا کَش کامل همان نسخه‌ها نیاز دارد. استفاده معمول برنامه پس از نصب و Build به اینترنت یا API خارجی وابسته نیست.

## راه‌اندازی جدید

فرمان‌های زیر پس از رفع پیش‌نیازها، برای یک پایگاه جدید و بدون داده شخصی هستند. برای پایگاه موجود، قبل از migration پشتیبان سازگار و بازیابی آزموده لازم است.

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

برنامه در `http://127.0.0.1:8000` باز می‌شود. owner:create نام کاربری و رمز/تکرار مخفی را دریافت می‌کند؛ رمز در آرگومان فرمان یا فایل نمونه قرار نمی‌گیرد. نام کاربری ۳ تا ۶۴ نویسه لاتین/رقم/نقطه/خط تیره/زیرخط، و رمز حداقل ۱۲ و حداکثر ۱۲۸ نویسه است؛ عبارت طولانی مجاز است. مالک دوم ساخته نمی‌شود.

composer.lock و package-lock.json در Git نگهداری می‌شوند و مبنای نصب تکرارپذیرند.

برای توسعهٔ frontend، `npm run dev` سرور Vite را روی `127.0.0.1:5175` با `strictPort` اجرا می‌کند (D35)؛ اگر پورت اشغال باشد خطا می‌دهد و پورت دیگری برنمی‌دارد.

## ورود، نشست و بازیابی دسترسی

GET /login فرم Livewire را نشان می‌دهد؛ POST /login مسیر عادی بدون JavaScript با همان Action ورود است. هر دو اعتبارسنجی سرور و محدودسازی مشترک دارند. فرم POST و CSRF دارد تا نبود JavaScript باعث ارسال رمز در URL نشود. پس از ورود تنها به dashboard هدایت می‌شود؛ redirect خارجی از ورودی کاربر پذیرفته نمی‌شود.

در یک دقیقه، پنج تلاش برای ترکیب نام کاربری نرمال‌شده/IP و بیست تلاش کل برای IP مجاز است؛ محدودکننده file cache در اجرای معمول بین درخواست‌ها پایدار می‌ماند. پیام نام کاربری ناشناخته، رمز غلط یا مالک غیرفعال یکسان است. رفتار سقف پنج تلاش، تغییر نام کاربری برای سقف IP و cooldown در Pest موفق‌اند؛ این نتیجه ضمانت سقف مطلق در تمام همزمانی‌های distributed نیست و برنامه محلی است.

نشست database ثابت است تا ابطال تمام نشست‌های مالک در بازیابی رمز معنی مشخص داشته باشد. payload با APP_KEY رمز می‌شود؛ lifetime سی دقیقه، انقضا با بستن مرورگر، HttpOnly و SameSite=strict است. ورود session ID را می‌چرخاند؛ خروج POST، session و CSRF را باطل می‌کند. middleware `auth.session` تغییر رمز را نیز بررسی می‌کند. middleware مالک و نشست روی updateهای Livewire دوباره اجرا می‌شوند.

```bash
php artisan owner:reset-password
```

این فرمان با رمز مخفی، رمز مالک را عوض و نشست‌های database او را در همان تراکنش حذف می‌کند؛ مالک غیرفعال را خودکار فعال نمی‌کند. دسترسی به shell/APP_KEY/پایگاه فقط برای مالک دستگاه باشد. حساب و password_hash در log یا خروجی فرمان چاپ نمی‌شوند.

## فایل‌های خصوصی و اجرای آینده روی سرور

SQLite پیش‌فرض در `storage/app/private/database.sqlite` است؛ DB_DATABASE می‌تواند مسیر خصوصی مطلق تعیین کند. local disk قابل serve نیست و لینک public/storage نداریم. `.env`، SQLite/WAL، cache نشست، log، backup، receipt و export در ignore قرار دارند؛ اطلاعات محرمانه در `.env.example` نیست.

اجرای توسعه فقط روی loopback است. برای استقرار آینده، public تنها web root و HTTPS الزامی است؛ APP_ENV=production باعث اجبار Secure cookie و غیرفعال‌شدن debug در provider می‌شود. تنظیم APP_URL، دسترسی فایل‌های خصوصی و کاربر PHP-FPM باید متناسب همان میزبان بررسی شود. استقرار سرور خارج از [PLAN](PLAN.md) و معوق است.

## پشتیبان و بازیابی

پشتیبان‌گیری فعلاً از خط فرمان محلی انجام می‌شود. گذرواژهٔ پشتیبان دست‌کم ۱۶ بایت باشد و مستقل از رمز ورود و `APP_KEY`، در محل امن و جدا نگهداری شود. فرمان‌ها گذرواژه را مخفی می‌خوانند؛ آن را به‌صورت آرگومان shell ننویسید:

```bash
php artisan backup:create
php artisan backup:verify storage/app/private/backups/NAME.khfb
php artisan backup:restore-empty storage/app/private/backups/NAME.khfb
```

نام فایل را از خروجی `backup:create` بردارید. بازیابی به `storage/app/private/restores/` و **فایل جدید** انجام می‌شود و DB فعال دست‌نخورده می‌ماند. برای اتکا به پشتیبان، verify و دست‌کم یک restore آزمایشی لازم‌اند. جایگزینی خودکار DB فعال یا برنامهٔ نگهداری دوره‌ای هنوز وجود ندارد (P8). snapshot موقت در پوشهٔ موقت سیستم با مجوز 0600 ساخته و بعد حذف می‌شود و رمز/کلید در bundle ذخیره نمی‌شود. فایل `.khfb` در Git نادیده گرفته می‌شود.

پیش از هر migration باید پشتیبان رمزدار و بازیابی آزمایشی انجام شود، و هر schema تازه باید در `SqliteBackup` پشتیبانی و آزموده شود. پشتیبان‌های پیش از migrationهای قبلی در `storage/app/private/backups/before-*.khfb` و کلیدهای بازیابی آن‌ها در `storage/app/private/recovery-before-*.key` با مجوز 0600 و خارج از Git هستند. **محتوای کلید را چاپ یا وارد Git نکنید** و نسخه‌ای امن جدا از دستگاه نگه دارید.

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

Blade در تست‌ها در `storage/framework/testing/views` کامپایل می‌شود، نه مسیر برنامه؛ `phpunit.xml` این مسیر را با `VIEW_COMPILED_PATH` اجباری می‌کند و پوشه خودکار ساخته می‌شود. این کار جلوی خطای `touch(): Utime failed` را می‌گیرد که در sandbox روی `storage/framework/views` دیده شده بود، و cache برنامه را از تست جدا نگه می‌دارد. مسیر نسبی است، پس تست را از ریشهٔ پروژه اجرا کنید.

## اجرای همین محیط

برای شروع روی همین سیستم، `owner:create` (اگر هنوز مالک وجود ندارد) و serve لازم‌اند:

```bash
cd /home/irantech/Projects/khums-tracker
php artisan owner:create
php artisan serve --host=127.0.0.1
```

برنامه روی http://127.0.0.1:8000 باز می‌شود. با سرور محلی روشن و اینترنت قطع، صفحات و فونت باید بارگیری شوند.

مسیرهای خصوصی معوق: `/cash/import` فقط CSV UTF-8 تا ۲۰۰ سطر و ۲۵۶ KiB را روی disk خصوصی می‌پذیرد، و `/cpi` فقط پس از ورود مالک در دسترس است. درگاه ناشر CPI `https://amar.org.ir/` است و برنامه هیچ دادهٔ CPI واقعی واکشی یا seed نمی‌کند.

در sandbox نشست‌های قبلی، Chrome به permission در setsockopt و Firefox به محدودیت snap برخورد کرد؛ بنابراین مرور بصری در مرورگر کاربر انجام می‌شود (P7).
