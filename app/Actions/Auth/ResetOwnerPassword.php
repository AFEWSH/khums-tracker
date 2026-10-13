<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ResetOwnerPassword
{
    public function handle(string $password, string $confirmation): void
    {
        $values = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => ['required', 'string', Password::defaults(), 'max:128', 'confirmed'],
        ])->validate();

        DB::transaction(function () use ($values): void {
            $owner = User::query()->find(User::OWNER_ID);

            if ($owner === null) {
                throw ValidationException::withMessages([
                    'password' => 'ابتدا حساب مالک را با فرمان owner:create ایجاد کنید.',
                ]);
            }

            $owner->update(['password' => $values['password']]);

            DB::table('sessions')->where('user_id', User::OWNER_ID)->delete();
        });
    }
}
