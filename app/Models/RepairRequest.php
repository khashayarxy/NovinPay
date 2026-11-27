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
        'self_ship' => 'خودم ارسال میکنم',
        'in_person' => 'خودم حضوری میارم',
        'doorstep_pickup' => 'به پیک شما تحویل میدم',
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

        return match ($this->parsedIssue()['handover_key']) {
            'in_person' => ['label' => 'حضوری', 'tone' => 'info'],
            'self_ship' => ['label' => 'پستی', 'tone' => 'warning'],
            'doorstep_pickup' => ['label' => 'پیک', 'tone' => 'success'],
            default => null,
        };
    }

    /**
     * Parse structured fields stored in the encrypted issue text.
     *
     * @return array{
     *     problem: string,
     *     serial: ?string,
     *     accessories: ?string,
     *     location: ?string,
     *     handover_key: ?string,
     *     handover_label: ?string,
     *     pickup_address: ?string,
     *     pickup_lat: ?string,
     *     pickup_lng: ?string,
     *     responsive_phone: ?string,
     *     pickup_notes: ?string,
     * }
     */
    public function parsedIssue(): array
    {
        $raw = trim((string) ($this->issue ?? ''));
        $lines = preg_split('/\R/u', $raw) ?: [];
        $problemLines = [];
        $serial = null;
        $accessories = null;
        $location = null;
        $handoverLabel = null;
        $pickupAddress = null;
        $pickupLat = null;
        $pickupLng = null;
        $responsivePhone = null;
        $pickupNotes = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($problemLines !== [] && end($problemLines) !== '') {
                    $problemLines[] = '';
                }

                continue;
            }
            if (str_starts_with($trimmed, 'شماره سریال:')) {
                $serial = trim(mb_substr($trimmed, mb_strlen('شماره سریال:')));
            } elseif (str_starts_with($trimmed, 'لوازم همراه:')) {
                $accessories = trim(mb_substr($trimmed, mb_strlen('لوازم همراه:')));
            } elseif (str_starts_with($trimmed, 'استان و شهر:')) {
                $location = trim(mb_substr($trimmed, mb_strlen('استان و شهر:')));
            } elseif (str_starts_with($trimmed, 'نحوه تحویل دستگاه:')) {
                $handoverLabel = trim(mb_substr($trimmed, mb_strlen('نحوه تحویل دستگاه:')));
            } elseif (str_starts_with($trimmed, 'نشانی تحویل در محل:')) {
                $pickupAddress = trim(mb_substr($trimmed, mb_strlen('نشانی تحویل در محل:')));
            } elseif (str_starts_with($trimmed, 'موقعیت نقشه:')) {
                $coords = array_map('trim', explode(',', mb_substr($trimmed, mb_strlen('موقعیت نقشه:'))));
                if (count($coords) === 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
                    $pickupLat = $coords[0];
                    $pickupLng = $coords[1];
                }
            } elseif (str_starts_with($trimmed, 'شماره تماس پاسخگو:')) {
                $responsivePhone = trim(mb_substr($trimmed, mb_strlen('شماره تماس پاسخگو:')));
            } elseif (str_starts_with($trimmed, 'توضیحات برای پیک:')) {
                $pickupNotes = trim(mb_substr($trimmed, mb_strlen('توضیحات برای پیک:')));
            } else {
                $problemLines[] = $trimmed;
            }
        }

        $handoverKey = match (true) {
            $handoverLabel === null => null,
            str_contains($handoverLabel, 'حضوری') => 'in_person',
            str_contains($handoverLabel, 'پیک') => 'doorstep_pickup',
            str_contains($handoverLabel, 'ارسال') || str_contains($handoverLabel, 'پست') || str_contains($handoverLabel, 'تیپاکس') => 'self_ship',
            default => null,
        };

        return [
            'problem' => trim(implode("\n", $problemLines)),
            'serial' => $serial !== '' ? $serial : null,
            'accessories' => $accessories !== '' ? $accessories : null,
            'location' => $location !== '' ? $location : null,
            'handover_key' => $handoverKey,
            'handover_label' => $handoverLabel !== '' ? $handoverLabel : null,
            'pickup_address' => $pickupAddress !== '' ? $pickupAddress : null,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'responsive_phone' => $responsivePhone !== '' ? $responsivePhone : null,
            'pickup_notes' => $pickupNotes !== '' ? $pickupNotes : null,
        ];
    }

    public function updateHandoverMethod(
        string $method,
        ?string $pickupAddress = null,
        ?string $pickupLat = null,
        ?string $pickupLng = null,
        ?string $responsivePhone = null,
        ?string $pickupNotes = null,
    ): void {
        $parsed = $this->parsedIssue();
        $base = $parsed['problem'];
        $meta = [];
        if ($parsed['serial'] !== null) {
            $meta[] = 'شماره سریال: '.$parsed['serial'];
        }
        if ($parsed['accessories'] !== null) {
            $meta[] = 'لوازم همراه: '.$parsed['accessories'];
        }
        if ($parsed['location'] !== null) {
            $meta[] = 'استان و شهر: '.$parsed['location'];
        }
        $meta[] = 'نحوه تحویل دستگاه: '.self::HANDOVER_METHODS[$method];
        if ($method === 'doorstep_pickup') {
            if ($pickupAddress !== null && trim($pickupAddress) !== '') {
                $meta[] = 'نشانی تحویل در محل: '.trim($pickupAddress);
            }
            if ($pickupLat !== null && $pickupLng !== null && is_numeric($pickupLat) && is_numeric($pickupLng)) {
                $meta[] = 'موقعیت نقشه: '.number_format((float) $pickupLat, 6, '.', '').', '.number_format((float) $pickupLng, 6, '.', '');
            }
            if ($responsivePhone !== null && trim($responsivePhone) !== '') {
                $meta[] = 'شماره تماس پاسخگو: '.trim($responsivePhone);
            }
            $effectiveNotes = $pickupNotes ?? $parsed['pickup_notes'];
            if ($effectiveNotes !== null && trim($effectiveNotes) !== '') {
                $meta[] = 'توضیحات برای پیک: '.trim(preg_replace('/\s+/u', ' ', $effectiveNotes) ?? '');
            }
        }

        $this->issue = trim($base.($base !== '' ? "\n\n" : '').implode("\n", $meta));
        $this->save();
    }

    public function isCourierEligible(): bool
    {
        $location = $this->parsedIssue()['location'] ?? null;
        if (! is_string($location) || trim($location) === '') {
            return false;
        }

        $normalize = static function (string $value): string {
            $val = str_replace(['ي', 'ك', "\u{200c}"], ['ی', 'ک', ' '], trim($value));

            return trim((string) preg_replace('/\s+/u', ' ', $val));
        };

        $parts = array_values(array_filter(array_map($normalize, explode('-', $location))));
        if (count($parts) < 2) {
            return false;
        }

        [$province, $city] = $parts;
        $zones = (array) config('delivery.courier_zones', []);
        foreach ($zones as $zoneProvince => $zoneCities) {
            if ($normalize((string) $zoneProvince) !== $province) {
                continue;
            }
            foreach ((array) $zoneCities as $zoneCity) {
                if ($normalize((string) $zoneCity) === $city) {
                    return true;
                }
            }
        }

        return false;
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
