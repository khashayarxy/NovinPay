<?php

namespace App\Support;

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
}
