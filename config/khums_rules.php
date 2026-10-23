<?php

// Source summaries are offline references, not executable rulings. Activation requires a separate reviewed version.
return [
    'version' => 'khums-draft-v0.3-2026-10-23',
    'source_checked_at' => '2026-10-17',
    'rules' => [
        [
            'id' => 'K-EXCESS', 'title' => 'اشتباه در محاسبه و پرداخت اضافی', 'source' => 'R-K-EXCESS',
            'issue' => '۸۵۵ و پاورقی‌های آن', 'url' => 'https://www.sistani.org/persian/book/26576/6865/',
            'kind' => 'فتوا با شرایط و مجوز موردی', 'review' => 'observed', 'status' => 'inactive',
            'observed_at' => '2026-10-23',
            'summary' => 'اضافه‌پرداخت به‌صورت خودکار اعتبار سال بعد نیست؛ احتساب آینده و امکان استرداد به شرایط و بررسی موردی وابسته‌اند.',
            'required_facts' => ['منشأ اشتباه محاسبه', 'استحقاق گیرنده هنگام پرداخت', 'دامنه مجوز معتبر', 'وضعیت استرداد وجه'],
        ],
        [
            'id' => 'K02', 'title' => 'درآمد باقیمانده در پایان سال', 'source' => 'R-K01',
            'issue' => '۵۸۴، ۵۸۵', 'url' => 'https://www.sistani.org/persian/book/26576/6151/',
            'kind' => 'فتوا با استثناهای وابسته', 'review' => 'partial_review', 'status' => 'inactive',
            'summary' => 'درآمد مصرف‌نشده در مؤونه تا پایان سال، با توجه به استثناها و وضعیت واقعی مال بررسی می‌شود.',
            'required_facts' => ['منشأ درآمد', 'باقیمانده واقعی', 'مؤونه و استثناها', 'ساعت مرز'],
        ],
        [
            'id' => 'K01', 'title' => 'اصل مخمّس و تبدیل داخلی', 'source' => 'R-K16',
            'issue' => '۶۵۳', 'url' => 'https://www.sistani.org/persian/book/26576/6363/',
            'kind' => 'فتوا با شرایط', 'review' => 'observed', 'status' => 'inactive',
            'summary' => 'پایهٔ ارزش پس از تخمیس در فرض منبع بررسی می‌شود؛ تبدیل داخلی درآمد تازه نیست.',
            'required_facts' => ['پایهٔ مخمّس تأییدشده', 'زنجیرهٔ منشأ', 'نوع تبدیل'],
        ],
        [
            'id' => 'K04', 'title' => 'افزایش ارزش دارایی تجاری', 'source' => 'R-K04',
            'issue' => '۶۴۵', 'url' => 'https://www.sistani.org/persian/book/26576/6356/',
            'kind' => 'فتوا با استثنا', 'review' => 'observed', 'status' => 'inactive',
            'summary' => 'قصد تجارت و قابلیت فروش یا دریافت عوض در تشخیص اثر افزایش ارزش مؤثرند.',
            'required_facts' => ['قصد تاریخی', 'قابلیت فروش', 'قیمت معتبر'],
        ],
        [
            'id' => 'K11', 'title' => 'اثر تورم بر خمس', 'source' => 'R-K06',
            'issue' => '۶۴۷', 'url' => 'https://www.sistani.org/persian/book/26576/6358/',
            'kind' => 'احتیاط واجب در فرض منبع', 'review' => 'observed', 'status' => 'inactive',
            'summary' => 'گزارش اقتصادی CPI به‌تنهایی معافیت یا حکم قطعی خمس ایجاد نمی‌کند.',
            'required_facts' => ['فرض دقیق مسئله', 'تصمیم مستند دربارهٔ احتیاط'],
        ],
        [
            'id' => 'K12', 'title' => 'مصرف پول مخلوط', 'source' => 'R-K02',
            'issue' => '۶۳۸، ۶۳۹؛ بررسی الگوریتم ناقص', 'url' => 'https://www.sistani.org/persian/book/26576/6351/',
            'kind' => 'برداشت کاربردی، نیازمند بررسی', 'review' => 'partial_review', 'status' => 'inactive',
            'summary' => 'روش حسابداری منشأ پول مخلوط را حکم شرعی عمومی فرض نمی‌کنیم.',
            'required_facts' => ['ماهیت حساب یا عین', 'شواهد اختلاط', 'روش مصرف مورد تأیید'],
        ],
    ],
];
