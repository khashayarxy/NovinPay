<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairWorkEntry extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['id', 'created_at'];

    protected function casts(): array
    {
        return ['work_performed' => 'encrypted', 'parts_replaced' => 'encrypted', 'internal_notes' => 'encrypted', 'completion_checks' => 'encrypted', 'final_result' => 'encrypted', 'is_completion' => 'boolean', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
