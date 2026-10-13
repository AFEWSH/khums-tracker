<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'id' => User::OWNER_ID,
            'login' => 'owner',
            'password' => 'synthetic-passphrase-2026',
            'disabled_at' => null,
        ];
    }
}
