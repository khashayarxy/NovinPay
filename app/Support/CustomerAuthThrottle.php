<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerAuthThrottle
{
    public static function respond(Request $request, array $headers, string $action, string $reason = 'rate_limit', int $status = 429): Response
    {
        $wait = max(1, (int) ($headers['Retry-After'] ?? 1));
        $headers['Retry-After'] = (string) $wait;
        $message = self::message($reason, $wait);
        $phone = $request->session()->get('customer_login_phone');
        if ($action === 'otp' && ! $phone) {
            $input = RepairIdentity::phone($request->input('phone'));
            if (preg_match('/\A09[0-9]{9}\z/D', $input)) {
                $request->session()->put('customer_login_phone', $input);
            }
        }
        $key = 'customer_retry_until.'.$action;
        $until = now()->timestamp + $wait;
        if ($until >= (int) $request->session()->get($key, 0)) {
            $request->session()->put([$key => $until, 'customer_retry_reason.'.$action => $reason]);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $wait, 'reason' => $reason], $status, $headers);
        }

        $differentPhone = $request->routeIs('account.start') && $phone && RepairIdentity::phone($request->input('phone')) !== $phone;
        $staleForm = $request->has('flow') && ! CustomerLoginFlow::matches($request, $request->input('flow'));
        $method = $action === 'password' ? 'password' : ($request->boolean('recover') ? 'recovery' : null);
        $parameters = $differentPhone || $staleForm ? [] : CustomerLoginFlow::parameters($request, $method);

        return redirect()->route('account.login', $parameters)->withHeaders($headers)->withErrors([$action === 'password' ? 'password' : 'code' => $message]);
    }

    public static function message(string $reason, int $wait): string
    {
        $prefix = match ($reason) {
            'resend_cooldown' => 'هنوز برای درخواست کد جدید زود است.',
            'daily_sms_limit' => 'به سقف روزانهٔ درخواست کد رسیده‌اید.',
            'delivery_unconfirmed' => 'ارسال کد تأیید نشد.',
            default => 'درخواست‌های زیادی ثبت شده است.',
        };
        $duration = match (true) {
            $wait >= 3600 => FaNumber::digits((int) ceil($wait / 3600)).' ساعت',
            $wait > 60 => FaNumber::digits((int) ceil($wait / 60)).' دقیقه',
            default => FaNumber::digits($wait).' ثانیه',
        };

        return $prefix.' '.$duration.' دیگر دوباره تلاش کنید.';
    }
}
