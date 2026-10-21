<?php

namespace App\Models;

class StockMovement extends CommerceHistory
{
    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'unit_ids' => 'array', 'created_at' => 'datetime'];
    }
}
