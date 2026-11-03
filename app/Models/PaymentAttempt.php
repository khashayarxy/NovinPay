<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['merchant_digest'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'amount_rial' => 'integer', 'lease_until' => 'datetime', 'retry_at' => 'datetime', 'verified_at' => 'datetime'];
    }
}
