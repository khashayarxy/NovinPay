<?php

namespace App\Http\Middleware;

use App\Support\FaNumber;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Normalizes Persian/Arabic numerals to ASCII digits across numeric form fields before validation. */
class NormalizeNumericDigits
{
    /** Scalar/nested keys whose values represent phone numbers, OTPs, codes, quantities, or measurements. */
    private const NUMERIC_KEYS = [
        'phone',
        'mobile',
        'contact_phone',
        'code',
        'otp',
        'tracking_reference',
        'tracking_code',
        'quantity',
        'postal_code',
        'national_code',
        'device_serial',
        'rating',
        'amount',
        'bank_reference',
        'reference',
        'latitude',
        'longitude',
    ];

    /** Keys where whitespace around digits (e.g. spaced OTP/phone/postal input) should also be removed. */
    private const COMPACT_KEYS = [
        'phone',
        'mobile',
        'contact_phone',
        'code',
        'otp',
        'tracking_reference',
        'tracking_code',
        'quantity',
        'postal_code',
        'national_code',
        'rating',
        'latitude',
        'longitude',
    ];

    /** Sensitive credential/freeform keys that must never be altered by digit normalization. */
    private const SKIP_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        '_token',
        'signature',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $normalized = $this->normalizePayload($request->all());
        if ($normalized !== $request->all()) {
            $request->merge($normalized);
        }

        return $next($request);
    }

    private function normalizePayload(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_string($key) || in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->normalizePayload($value);

                continue;
            }

            if (is_string($value) && in_array($key, self::NUMERIC_KEYS, true)) {
                $cleaned = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{200C}]/u', '', $value) ?? $value;
                $cleaned = FaNumber::normalizeDigits($cleaned);
                if (in_array($key, self::COMPACT_KEYS, true)) {
                    $cleaned = preg_replace('/\s+/u', '', $cleaned) ?? $cleaned;
                }
                $data[$key] = $cleaned;
            }
        }

        return $data;
    }
}
