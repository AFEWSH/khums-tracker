<?php

$problems = [];

if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
    $problems[] = 'این پروژه در فاز ۱ به PHP 8.4 نیاز دارد.';
}

foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'session', 'tokenizer', 'xml'] as $extension) {
    if (! extension_loaded($extension)) {
        $problems[] = 'افزونه لازم فعال نیست: '.$extension;
    }
}

if (! in_array('argon2id', password_algos(), true)) {
    $problems[] = 'PHP باید از Argon2id پشتیبانی کند.';
}

foreach ($problems as $problem) {
    fwrite(STDERR, $problem.PHP_EOL);
}

if ($problems !== []) {
    exit(1);
}

fwrite(STDOUT, 'پیش‌نیازهای PHP فاز ۱ آماده‌اند.'.PHP_EOL);
