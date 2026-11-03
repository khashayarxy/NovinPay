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
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

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

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(RepairContactAttempt::class);
    }

    /** A quote is never a charge until the work/outcome is established. */
    public function billableToman(): ?int
    {
        $estimate = $this->estimates()->with('decision')->latest('revision')->first();
        if (! $estimate || (int) $estimate->repair_inspection_id !== (int) $this->inspections()->max('id')) {
            return ! $estimate && in_array($this->status, [RepairStage::ReturnPending, RepairStage::Returned], true) ? (int) $this->inbound_shipping_cost : null;
        }
        if (in_array($this->status, [RepairStage::Repaired, RepairStage::Ready, RepairStage::Delivered], true)
            && $estimate->decisionStatus() === 'approved'
            && $this->workEntries()->where('repair_estimate_id', $estimate->id)->where('is_completion', true)->exists()) {
            return $estimate->total() + (int) $this->inbound_shipping_cost;
        }
        if (in_array($this->status, [RepairStage::ReturnPending, RepairStage::Returned], true) && $estimate->decisionStatus() === 'rejected') {
            return (int) $estimate->diagnosis_fee + (int) $this->inbound_shipping_cost;
        }

        return null;
    }
}
