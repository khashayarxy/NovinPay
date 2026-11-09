<?php

namespace App\Http\Middleware;

use App\Services\CustomerLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerSession
{
    public function handle(Request $request, Closure $next)
    {
        $account = Auth::guard('customer')->user();
        if (! $account || ! $account->is_active || $request->session()->get('customer_session_version') !== $account->session_version
            || (! $account->verified_at && ! app(CustomerLogin::class)->testAllowed($account->phone))) {
            Auth::guard('customer')->logout();
            $request->session()->forget(['customer_session_version', 'customer_otp_at', 'customer_password_offer']);
            $destination = match (true) {
                $request->routeIs('cart.checkout') => route('cart.index', [], false),
                $request->routeIs('repair.store') => route('repair', [], false),
                $request->isMethod('GET') && $request->routeIs('account.*', 'payments.document', 'delivery.show') => '/'.$request->path(),
                default => null,
            };
            if ($destination !== null) {
                $request->session()->put('customer_intended', $destination);
            }

            return redirect()->route('account.login');
        }

        return $next($request);
    }
}
