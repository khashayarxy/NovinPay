<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CustomerLoginFlow
{
    public static function destination(Request $request): string
    {
        $destination = $request->session()->pull('customer_intended');
        if (is_string($destination) && preg_match('~\A/shop/orders/([a-f0-9-]{36})/payment\z~iD', $destination, $parts)) {
            return route('account.orders.show', ['record' => $parts[1]], false);
        }

        return $destination ?? route('account.index');
    }

    public static function token(Request $request): string
    {
        $token = $request->session()->get('customer_login_flow');
        if (! is_string($token)) {
            $token = Str::random(32);
            $request->session()->put('customer_login_flow', $token);
        }

        return $token;
    }

    public static function matches(Request $request, mixed $value): bool
    {
        $token = $request->session()->get('customer_login_flow');

        return is_string($token) && is_string($value) && hash_equals($token, $value);
    }

    public static function parameters(Request $request, ?string $method = null): array
    {
        if (! is_string($request->session()->get('customer_login_phone'))) {
            return [];
        }
        $token = self::token($request);

        return $method === null ? ['flow' => $token] : ['flow' => $token, 'method' => $method];
    }
}
