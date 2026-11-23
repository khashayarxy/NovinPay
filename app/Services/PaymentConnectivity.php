<?php

namespace App\Services;

use Illuminate\Http\Request;

final class PaymentConnectivity
{
    /** True for Iran, false for outside Iran, null when there is insufficient evidence. */
    public function isIran(Request $request): ?bool
    {
        $address = $this->clientAddress($request);
        if (! $address || ! filter_var(inet_ntop($address), FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return null;
        }

        $path = config('payments.connectivity.ranges_path');
        if (! is_string($path) || $path === '') {
            return null;
        }
        $data = @file_get_contents($path, false, null, 0, 262145);
        $snapshot = is_string($data) && strlen($data) <= 262144 ? json_decode($data, true, 8) : null;
        $month = $snapshot['month'] ?? null;
        if (! is_string($month) || ! preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/D', $month)
            || $month > now()->format('Y-m') || $month < now()->subMonthsNoOverflow(2)->format('Y-m')) {
            return null;
        }

        $key = bin2hex($address);
        $ranges = $snapshot[strlen($address) === 4 ? 'v4' : 'v6'] ?? null;
        if (! is_array($ranges) || ! array_is_list($ranges)) {
            return null;
        }
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $range = $ranges[$middle];
            if (! is_array($range) || ! is_string($range[0] ?? null) || ! is_string($range[1] ?? null)
                || strlen($range[0]) !== strlen($key) || strlen($range[1]) !== strlen($key)
                || ! is_bool($range[2] ?? null)) {
                return null;
            }
            if (strcmp($key, $range[0]) < 0) {
                $high = $middle - 1;
            } elseif (strcmp($key, $range[1]) > 0) {
                $low = $middle + 1;
            } else {
                return $range[2];
            }
        }

        return false;
    }

    private function clientAddress(Request $request): ?string
    {
        $peer = $this->pack($request->server('REMOTE_ADDR'));
        $configured = config('payments.connectivity.trusted_proxies', []);
        $serverAddr = $request->server('SERVER_ADDR');
        if (is_string($serverAddr) && $serverAddr !== '') {
            $configured[] = $serverAddr;
        }
        $forwardedHeader = trim((string) ($request->headers->get('X-Forwarded-For') ?: $request->headers->get('X-Real-IP', '')));
        if ($forwardedHeader !== '') {
            $configured[] = '127.0.0.1';
            $configured[] = '::1';
        }
        $proxies = array_filter(array_map($this->pack(...), $configured));
        if (! $peer || ! in_array($peer, $proxies, true)) {
            return $peer;
        }

        // Only an explicitly configured peer can vouch for the rightmost untrusted hop.
        $forwarded = $forwardedHeader;
        if (strlen($forwarded) > 1024) {
            return null;
        }
        $hops = explode(',', $forwarded);
        if (count($hops) > 16) {
            return null;
        }
        foreach (array_reverse($hops) as $hop) {
            $address = $this->pack($hop);
            if (! $address || ! in_array($address, $proxies, true)) {
                return $address;
            }
        }

        return null;
    }

    private function pack(?string $address): ?string
    {
        $address = trim($address ?? '');
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = inet_pton($address);

        return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
            ? substr($packed, 12) : $packed;
    }
}
