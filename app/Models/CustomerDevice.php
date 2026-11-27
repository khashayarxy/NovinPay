<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDevice extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['customer_account_id', 'device_serial', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'device_serial' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
