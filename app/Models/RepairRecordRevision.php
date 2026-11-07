<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;

class RepairRecordRevision extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'created_at' => 'datetime'];
    }
}
