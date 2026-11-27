<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class CustomerAccount extends Authenticatable
{
    protected $guarded = ['*'];

    protected $hidden = ['phone', 'phone_digest', 'email', 'email_digest', 'google_id_digest', 'name', 'national_code', 'password'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'name' => 'encrypted',
            'national_code' => 'encrypted',
            'verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_notifications_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(CustomerDevice::class, 'customer_account_id');
    }
}
