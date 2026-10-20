<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RepairEstimate extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['id', 'created_at'];

    protected function casts(): array
    {
        return ['customer_summary' => 'encrypted', 'service_cost' => 'integer', 'parts_cost' => 'integer', 'revision' => 'integer', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(RepairEstimateDecision::class);
    }

    public function decisionStatus(): string
    {
        return $this->decision?->decision ?? 'pending';
    }

    public function total(): int
    {
        return $this->service_cost + $this->parts_cost;
    }
}
