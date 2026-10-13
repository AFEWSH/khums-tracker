<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CreateOwner
{
    public function handle(string $login, string $password, string $confirmation): User
    {
        $values = Validator::make([
            'login' => Str::lower(trim($login)),
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'login' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{2,63}\z/'],
            'password' => ['required', 'string', Password::defaults(), 'max:128', 'confirmed'],
        ])->validate();

        try {
            return DB::transaction(function () use ($values): User {
                if (User::query()->exists()) {
                    $this->rejectExistingOwner();
                }

                // A fixed primary key prevents two concurrent commands creating owners.
                return User::query()->forceCreate([
                    'id' => User::OWNER_ID,
                    'login' => $values['login'],
                    'password' => $values['password'],
                ]);
            });
        } catch (QueryException $exception) {
            if (User::query()->exists()) {
                $this->rejectExistingOwner();
            }

            throw $exception;
        }
    }

    private function rejectExistingOwner(): never
    {
        throw ValidationException::withMessages([
            'login' => 'حساب مالک از قبل وجود دارد؛ ساخت حساب دوم مجاز نیست.',
        ]);
    }
}
