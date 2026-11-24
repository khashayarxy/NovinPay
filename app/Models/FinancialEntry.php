<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialEntry extends CommerceHistory
{
    protected $hidden = ['note', 'reference', 'reference_digest'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'reference' => 'encrypted', 'charge_delta' => 'integer', 'receipt_delta' => 'integer', 'expense_delta' => 'integer', 'created_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
