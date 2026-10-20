<?php

namespace App\Models;

use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RepairCase extends Model
{
    protected $guarded = ['*'];

    protected $visible = ['public_id', 'status'];

    protected function casts(): array
    {
        return ['customer_name' => 'encrypted', 'contact_phone' => 'encrypted', 'device_model' => 'encrypted', 'device_serial' => 'encrypted', 'issue' => 'encrypted', 'physical_condition' => 'encrypted', 'accessories' => 'encrypted', 'device_brand' => RepairBrand::class, 'status' => RepairStage::class, 'received_at' => 'datetime', 'version' => 'integer', 'assigned_to' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function onlineRequest(): BelongsTo
    {
        return $this->belongsTo(RepairRequest::class, 'repair_request_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(RepairInspection::class);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(RepairEstimate::class);
    }

    public function workEntries(): HasMany
    {
        return $this->hasMany(RepairWorkEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RepairEvent::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(RepairDelivery::class);
    }

    public function currentEstimate(): HasOne
    {
        return $this->hasOne(RepairEstimate::class)->latestOfMany();
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(RepairInspection::class)->latestOfMany();
    }
}
