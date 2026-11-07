<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['path', 'file_hash', 'bank_reference', 'review_note'];

    protected function casts(): array
    {
        return ['path' => 'encrypted', 'bank_reference' => 'encrypted', 'review_note' => 'encrypted', 'amount_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }
}
