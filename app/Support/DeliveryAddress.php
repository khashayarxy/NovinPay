<?php

namespace App\Support;

final class DeliveryAddress
{
    public static function format(?array $address): string
    {
        if (! $address) {
            return '';
        }

        $normalize = static fn (?string $value): string => preg_replace(
            '/[\s\x{200c}]+/u',
            '',
            strtr(trim((string) $value), ['ي' => 'ی', 'ك' => 'ک'])
        ) ?? '';

        $province = trim((string) ($address['province'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $street = trim((string) ($address['address'] ?? ''));

        $parts = [];
        if ($province !== '') {
            $parts[] = $province;
        }
        if ($city !== '' && $normalize($city) !== $normalize($province)) {
            $parts[] = $city;
        }

        if ($street !== '') {
            $candidates = array_values(array_unique(array_filter([$province, $city])));
            foreach ($candidates as $prefix) {
                $pattern = '/\A'.preg_quote($prefix, '/').'\s*(?:[،,\-–]\s*)?/u';
                $stripped = preg_replace($pattern, '', $street, 1);
                if (is_string($stripped) && $stripped !== $street) {
                    $street = trim($stripped);
                }
            }
            if ($street !== '' && ! in_array($normalize($street), array_map($normalize, $parts), true)) {
                $parts[] = $street;
            }
        }

        return implode('، ', $parts);
    }
}
