<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRepairRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairDelivery extends Model
{
    use ImmutableRepairRecord;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $visible = ['id', 'created_at'];

    protected function casts(): array
    {
        return ['recipient_name' => 'encrypted', 'outcome_notes' => 'encrypted', 'confirmation_reference' => 'encrypted', 'delivered_at' => 'datetime', 'service_cost' => 'integer', 'parts_cost' => 'integer', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
