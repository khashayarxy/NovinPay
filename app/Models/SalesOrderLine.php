<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];
    }
}
