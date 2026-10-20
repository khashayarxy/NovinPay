<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairEvent extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
