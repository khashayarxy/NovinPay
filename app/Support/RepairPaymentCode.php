<?php

namespace App\Support;

final class RepairPaymentCode
{
    public static function issue(string $repair, int $expires): string
    {
        $payload = hex2bin(str_replace('-', '', $repair)).pack('N', $expires);

        return strtr(base64_encode($payload.self::signature($payload)), '+/', '-_');
    }

    /** @return array{repair: string, expires: int}|null */
    public static function resolve(string $code): ?array
    {
        $decoded = base64_decode(strtr($code, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== 36) {
            return null;
        }

        $payload = substr($decoded, 0, 20);
        $expires = unpack('N', substr($payload, 16))[1];
        if ($expires <= now()->timestamp || ! hash_equals(self::signature($payload), substr($decoded, 20))) {
            return null;
        }

        $repair = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(substr($payload, 0, 16)), 4));

        return ['repair' => $repair, 'expires' => $expires];
    }

    private static function signature(string $payload): string
    {
        return substr(hash_hmac('sha256', "novinpay:repair-payment:v1\0".$payload, config('app.key'), true), 0, 16);
    }
}
