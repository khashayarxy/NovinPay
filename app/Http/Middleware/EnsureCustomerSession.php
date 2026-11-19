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
        if (! app(CustomerLogin::class)->authenticatedAccount($request)) {
            Auth::guard('customer')->logout();
            $request->session()->forget(['customer_session_version', 'customer_otp_at', 'customer_password_offer']);
            $destination = match (true) {
                $request->routeIs('cart.checkout', 'cart.review') => route('cart.review', [], false),
                $request->routeIs('repair.start', 'repair.store') => route('repair', [], false),
                $request->isMethod('GET') && $request->routeIs('account.*', 'payments.document', 'payments.order', 'payments.repair', 'payments.connect', 'payments.result', 'delivery.show') => '/'.$request->path(),
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
