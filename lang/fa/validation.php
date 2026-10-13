<?php

return [
    'required' => 'وارد کردن :attribute لازم است.',
    'string' => ':attribute باید متن باشد.',
    'confirmed' => 'تکرار :attribute با مقدار اصلی یکسان نیست.',
    'regex' => ':attribute باید ۳ تا ۶۴ نویسه لاتین، رقم، نقطه، خط تیره یا زیرخط باشد و با حرف یا رقم شروع شود.',
    'min' => ['string' => ':attribute باید حداقل :min نویسه داشته باشد.'],
    'max' => ['string' => ':attribute نباید بیشتر از :max نویسه باشد.'],
    'attributes' => [
        'login' => 'نام کاربری',
        'password' => 'رمز عبور',
        'password_confirmation' => 'تکرار رمز عبور',
    ],
];
