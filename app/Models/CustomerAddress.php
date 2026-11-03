<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $guarded = ['*'];

    protected $visible = ['public_id'];

    protected function casts(): array
    {
        return array_fill_keys(['recipient', 'phone', 'province', 'city', 'address', 'postal_code'], 'encrypted');
    }
}
