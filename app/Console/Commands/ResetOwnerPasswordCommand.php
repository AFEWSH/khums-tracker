<?php

namespace App\Console\Commands;

use App\Actions\Auth\ResetOwnerPassword;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ResetOwnerPasswordCommand extends Command
{
    protected $signature = 'owner:reset-password';

    protected $description = 'بازیابی محلی رمز مالک و ابطال همه نشست‌های او';

    public function handle(ResetOwnerPassword $resetOwnerPassword): int
    {
        $password = $this->secret('رمز عبور جدید (حداقل ۱۲ نویسه)');
        $confirmation = $this->secret('تکرار رمز عبور جدید');

        try {
            $resetOwnerPassword->handle((string) $password, (string) $confirmation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('رمز عبور تغییر کرد و همه نشست‌های مالک باطل شدند.');

        return self::SUCCESS;
    }
}
