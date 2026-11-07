<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPlan extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['address_snapshot', 'requested_address', 'tracking'];

    protected function casts(): array
    {
        return ['address_snapshot' => 'encrypted:array', 'requested_address' => 'encrypted:array', 'tracking' => 'encrypted', 'shipping_minor' => 'integer', 'version' => 'integer'];
    }
}
