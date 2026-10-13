<?php

return [
    'name' => env('APP_NAME', 'مدیریت مالی شخصی'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://127.0.0.1:8000'),
    'timezone' => 'UTC',
    'locale' => env('APP_LOCALE', 'fa'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fa'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'fa_IR'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    'maintenance' => ['driver' => 'file'],
];
