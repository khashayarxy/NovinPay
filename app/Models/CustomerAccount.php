<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class CustomerAccount extends Authenticatable
{
    protected $guarded = ['*'];

    protected $hidden = ['phone', 'phone_digest', 'email', 'email_digest', 'google_id_digest', 'name', 'password'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'email' => 'encrypted', 'name' => 'encrypted', 'verified_at' => 'datetime', 'email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'is_active' => 'boolean', 'session_version' => 'integer'];
    }
}
