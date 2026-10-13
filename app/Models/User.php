<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    public const OWNER_ID = 1;

    public $incrementing = false;

    protected $fillable = ['login', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function isActiveOwner(): bool
    {
        return (int) $this->getKey() === self::OWNER_ID && $this->disabled_at === null;
    }
}
