<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CustomerLoginFlow
{
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
        $token = $request->session()->get('customer_login_flow');
        if (! is_string($token)) {
            $token = Str::random(32);
            $request->session()->put('customer_login_flow', $token);
        }

        return $method === null ? ['flow' => $token] : ['flow' => $token, 'method' => $method];
    }
}
