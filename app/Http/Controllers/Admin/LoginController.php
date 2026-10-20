<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FaNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Failed-login ceiling per IP address across all email addresses,
     * checked before request validation so malformed-input floods are
     * bounded too. Overridable via config('admin.login_ip_max_attempts').
     */
    private const IP_MAX_ATTEMPTS = 30;

    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $ipKey = 'login|ip|'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, $this->ipMaxAttempts())) {
            throw ValidationException::withMessages([
                'email' => $this->throttledMessage($ipKey),
            ]);
        }

        try {
            $credentials = $request->validate([
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string'],
            ], [
                'password.required' => 'گذرواژه الزامی است.',
            ]);
        } catch (ValidationException $exception) {
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw $exception;
        }

        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => $this->throttledMessage($throttleKey),
            ]);
        }

        if (! Auth::attemptWhen($credentials, fn (User $user): bool => Gate::forUser($user)->allows('access-admin'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'ایمیل یا گذرواژه معتبر نیست.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($ipKey);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.products.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function ipMaxAttempts(): int
    {
        return (int) config('admin.login_ip_max_attempts', self::IP_MAX_ATTEMPTS);
    }

    private function throttledMessage(string $key): string
    {
        return 'تلاش‌های ناموفق زیاد است. لطفاً '
            .FaNumber::digits(RateLimiter::availableIn($key))
            .' ثانیه دیگر دوباره تلاش کنید.';
    }
}
