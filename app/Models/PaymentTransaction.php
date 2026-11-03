<?php

namespace App\Models;

class PaymentTransaction extends CommerceHistory
{
    protected $guarded = ['*'];

    protected $hidden = ['reference', 'reference_digest'];

    protected function casts(): array
    {
        return ['reference' => 'encrypted', 'provider_paid_at' => 'encrypted', 'amount_minor' => 'integer', 'allocated_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
