<?php

namespace App\Models;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RepairRequest extends Model
{
    public function physicalCase(): HasOne
    {
        return $this->hasOne(RepairCase::class);
    }

    // No public mass-assignment surface; the controller assigns each field.
    protected $guarded = ['*'];

    // Do not expose records through accidental JSON serialization.
    protected $hidden = [
        'id', 'customer_name', 'contact_phone', 'phone_digest', 'device_brand',
        'device_model', 'issue', 'submission_digest', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_name' => 'encrypted',
            'contact_phone' => 'encrypted',
            'device_model' => 'encrypted',
            'issue' => 'encrypted',
            'device_brand' => RepairBrand::class,
            'status' => RepairRequestStatus::class,
        ];
    }
}
