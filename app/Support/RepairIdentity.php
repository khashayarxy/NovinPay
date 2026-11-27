<?php

namespace App\Support;

use App\Enums\RepairBrand;

final class RepairIdentity
{
    public static function phone(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 120) {
            return '';
        }

        $phone = str_replace([' ', '-'], '', FaNumber::normalizeDigits(trim($value)));

        return preg_replace('/^(?:\+98|0098)/', '0', $phone) ?? '';
    }

    public static function digest(string $purpose, string $value): string
    {
        // A keyed digest avoids a dictionary-searchable index of phone numbers.
        // Keep APP_KEY stable and backed up; rotation needs explicit re-indexing.
        return hash_hmac('sha256', $purpose.':'.$value, config('app.key'));
    }

    public static function deviceLabel(RepairBrand|string|null $brand, ?string $model): string
    {
        $cleanModel = trim((string) $model);
        $brandName = $brand instanceof RepairBrand ? $brand->value : trim((string) $brand);

        if ($brandName !== '' && $brandName !== 'سایر') {
            if ($cleanModel === '') {
                return $brandName;
            }
            if (stripos($cleanModel, $brandName) === 0) {
                return $cleanModel;
            }

            return $brandName.' '.$cleanModel;
        }

        return $cleanModel !== '' ? $cleanModel : ($brandName !== '' ? $brandName : 'دستگاه کارتخوان');
    }
}
