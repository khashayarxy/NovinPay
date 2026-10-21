<?php

namespace App\Models;

class SalesOrderEvent extends CommerceHistory
{
    protected $hidden = ['reason'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'line_snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
