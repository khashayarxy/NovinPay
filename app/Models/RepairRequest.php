<?php

namespace App\Models;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class RepairRequest extends Model
{
    public const ACCESSORIES = ['charger' => 'شارژر', 'battery' => 'باتری', 'sim' => 'سیم‌کارت'];

    public const PHOTO_SIDES = [
        'front' => 'نمای جلو',
        'back' => 'نمای پشت',
        'right' => 'سمت راست',
        'left' => 'سمت چپ',
    ];

    public const HANDOVER_METHODS = [
        'self_ship' => 'خودم میفرستم (پست / تیپاکس)',
        'in_person' => 'حضوری میآورم',
        'doorstep_pickup' => 'به پیک شما تحویل میدهم',
    ];

    public function photoPath(): string
    {
        return 'repair-requests/'.$this->id.'/'.$this->submission_digest.'.image';
    }

    public function sidePhotoPath(string $side, string $extension = 'webp'): string
    {
        return 'repair-requests/'.$this->id.'/'.$this->submission_digest.'-'.$side.'.'.$extension;
    }

    /**
     * @return array<string, string> Map of side key => stored path on private local disk
     */
    public function existingSidePhotos(): array
    {
        $disk = Storage::disk('local');
        $found = [];
        foreach (array_keys(self::PHOTO_SIDES) as $side) {
            foreach (['webp', 'jpg', 'png'] as $ext) {
                $path = $this->sidePhotoPath($side, $ext);
                if ($disk->exists($path)) {
                    $found[$side] = $path;
                    break;
                }
            }
        }

        return $found;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function physicalCase(): HasOne
    {
        return $this->hasOne(RepairCase::class);
    }

    /**
     * Extract a compact delivery-method badge from the encrypted issue note when loaded.
     *
     * @return array{label: string, tone: string}|null
     */
    public function handoverBadge(): ?array
    {
        if (! array_key_exists('issue', $this->attributes)) {
            return null;
        }

        $issue = (string) $this->issue;
        if (! str_contains($issue, 'نحوه تحویل دستگاه:')) {
            return null;
        }

        if (str_contains($issue, 'حضوری')) {
            return ['label' => 'حضوری', 'tone' => 'info'];
        }
        if (str_contains($issue, 'پست') || str_contains($issue, 'تیپاکس')) {
            return ['label' => 'پستی', 'tone' => 'warning'];
        }
        if (str_contains($issue, 'پیک')) {
            return ['label' => 'پیک', 'tone' => 'success'];
        }

        return null;
    }

    // No public mass-assignment surface; the controller assigns each field.
    protected $guarded = ['*'];

    // Do not expose records through accidental JSON serialization.
    protected $hidden = [
        'id', 'customer_name', 'contact_phone', 'phone_digest', 'device_brand',
        'device_model', 'issue', 'submission_digest', 'created_at', 'updated_at', 'customer_id',
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
