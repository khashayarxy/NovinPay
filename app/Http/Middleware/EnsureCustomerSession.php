<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerSession
{
    public function handle(Request $request, Closure $next)
    {
        $account = Auth::guard('customer')->user();
        if (! $account || ! $account->is_active || $request->session()->get('customer_session_version') !== $account->session_version) {
            Auth::guard('customer')->logout();
            $request->session()->forget('customer_session_version');

            return redirect()->route('account.login');
        }

        return $next($request);
    }
}
