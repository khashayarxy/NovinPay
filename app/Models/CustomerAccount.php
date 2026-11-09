<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class CustomerAccount extends Authenticatable
{
    protected $guarded = ['*'];

    protected $hidden = ['phone', 'phone_digest', 'name', 'password'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'name' => 'encrypted', 'verified_at' => 'datetime', 'is_active' => 'boolean', 'session_version' => 'integer'];
    }
}
