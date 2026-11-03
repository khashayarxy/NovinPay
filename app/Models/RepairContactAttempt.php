<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;

class RepairContactAttempt extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['id', 'created_at'];

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'created_at' => 'datetime'];
    }
}
