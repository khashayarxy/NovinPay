<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = AdminAccess::account($request->user());
        if (! $account && ! $request->session()->has('admin_session_version')) {
            abort(403);
        }
        if (! $account || $request->session()->get('admin_session_version') !== $account->session_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'نشست مدیریت معتبر نیست؛ دوباره وارد شوید.']);
        }
        if ($account->must_change_password && ! $request->routeIs('admin.password.*')) {
            return redirect()->route('admin.password.edit');
        }

        return $next($request);
    }
}
