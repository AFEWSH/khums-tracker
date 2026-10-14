<?php

namespace App\Support\Numbers;

use InvalidArgumentException;

final class DecimalInput
{
    public static function normalize(string $input): string
    {
        $value = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => ',',
        ]);

        if (! preg_match('/^[+-]?(?:[0-9]+|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Invalid decimal input.');
        }

        return str_replace(',', '', $value);
    }
}
