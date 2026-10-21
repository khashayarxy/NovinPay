<?php

namespace App\Models;

class FinancialEntry extends CommerceHistory
{
    protected $hidden = ['note', 'reference', 'reference_digest'];

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'reference' => 'encrypted', 'charge_delta' => 'integer', 'receipt_delta' => 'integer', 'expense_delta' => 'integer', 'created_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
