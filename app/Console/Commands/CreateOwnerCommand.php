<?php

namespace App\Console\Commands;

use App\Actions\Auth\CreateOwner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateOwnerCommand extends Command
{
    protected $signature = 'owner:create {login? : نام کاربری مالک}';

    protected $description = 'ایجاد یک حساب مالک با دریافت مخفی رمز عبور';

    public function handle(CreateOwner $createOwner): int
    {
        if (User::query()->exists()) {
            $this->error('حساب مالک از قبل وجود دارد؛ ساخت حساب دوم مجاز نیست.');

            return self::FAILURE;
        }

        $login = $this->argument('login') ?? $this->ask('نام کاربری (۳ تا ۶۴ نویسه لاتین)');
        $password = $this->secret('رمز عبور (حداقل ۱۲ نویسه)');
        $confirmation = $this->secret('تکرار رمز عبور');

        try {
            $createOwner->handle((string) $login, (string) $password, (string) $confirmation);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info('حساب مالک ایجاد شد. اکنون می‌توانید وارد برنامه شوید.');

        return self::SUCCESS;
    }
}
