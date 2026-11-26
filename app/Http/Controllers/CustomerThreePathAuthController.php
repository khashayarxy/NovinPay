<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Services\CustomerEmailLogin;
use App\Services\CustomerLogin;
use App\Services\GoogleAuthService;
use App\Services\ShoppingCart;
use App\Support\CustomerAuthThrottle;
use App\Support\CustomerLoginFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerThreePathAuthController extends Controller
{
    public function emailStart(Request $request, CustomerEmailLogin $emailLogin): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:254'],
        ], [
            'email.required' => 'نشانی ایمیل را وارد کنید.',
            'email.email' => 'نشانی ایمیل معتبر نیست.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('account.login', ['channel' => 'email'])->withErrors($validator);
        }

        $email = $emailLogin->normalizeEmail($validator->validated()['email']);
        if ($request->session()->get('customer_login_email') !== $email) {
            $request->session()->forget(['customer_email_challenge', 'customer_email_resend_at']);
        }
        $request->session()->put('customer_login_email', $email);

        if ($emailLogin->challengeState($email, $request->session()->get('customer_email_challenge')) !== null) {
            return redirect()->route('account.login', ['channel' => 'email'])
                ->with('success', 'کد تأیید قبلی هنوز معتبر است. همان کد را وارد کنید.');
        }

        return $this->sendEmailOtp($request, $emailLogin, $email);
    }

    public function emailResend(Request $request, CustomerEmailLogin $emailLogin): RedirectResponse
    {
        $email = $request->session()->get('customer_login_email');
        if (! is_string($email) || $email === '') {
            return redirect()->route('account.login', ['channel' => 'email']);
        }

        return $this->sendEmailOtp($request, $emailLogin, $email);
    }

    public function emailRestart(Request $request): RedirectResponse
    {
        $request->session()->forget(['customer_login_email', 'customer_email_challenge', 'customer_email_resend_at']);

        return redirect()->route('account.login', ['channel' => 'email']);
    }

    public function emailVerify(Request $request, CustomerEmailLogin $emailLogin): RedirectResponse
    {
        $rawCode = strtr(trim((string) $request->input('code', '')), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $request->merge(['code' => $rawCode]);

        $validator = Validator::make($request->all(), [
            'challenge' => ['required', 'uuid'],
            'code' => ['required', 'regex:/\A[0-9]{6}\z/'],
        ], [
            'code.required' => 'کد تأیید شش‌رقمی را وارد کنید.',
            'code.regex' => 'کد تأیید باید ۶ رقم باشد.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('account.login', ['channel' => 'email'])->withErrors($validator);
        }

        $data = $validator->validated();
        $sessionChallenge = $request->session()->get('customer_email_challenge');
        if (! is_string($sessionChallenge) || ! hash_equals($sessionChallenge, $data['challenge'])) {
            return redirect()->route('account.login', ['channel' => 'email'])
                ->withErrors(['code' => 'کد تأیید نامعتبر یا منقضی است.']);
        }

        try {
            $account = $emailLogin->verify($data['challenge'], $data['code']);
        } catch (ValidationException $e) {
            return redirect()->route('account.login', ['channel' => 'email'])->withErrors($e->errors());
        }

        $request->session()->forget(['customer_login_email', 'customer_email_challenge', 'customer_email_resend_at']);

        return $this->completeLogin($request, $account);
    }

    public function emailPasswordLogin(Request $request, CustomerEmailLogin $emailLogin): RedirectResponse
    {
        $email = $request->session()->get('customer_login_email');
        if (! is_string($email) || $email === '') {
            $email = $emailLogin->normalizeEmail((string) $request->input('email', ''));
        }

        $validator = Validator::make(['email' => $email, 'password' => $request->input('password')], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('account.login', ['channel' => 'email', 'method' => 'password'])
                ->withErrors($validator);
        }

        $account = $emailLogin->passwordAccount($email, $validator->validated()['password']);
        if (! $account) {
            return redirect()->route('account.login', ['channel' => 'email', 'method' => 'password'])
                ->withErrors(['password' => 'ایمیل یا رمز عبور نادرست است.']);
        }

        $request->session()->forget(['customer_login_email', 'customer_email_challenge', 'customer_email_resend_at']);

        return $this->completeLogin($request, $account);
    }

    public function googleRedirect(Request $request, GoogleAuthService $google, CustomerLogin $login): RedirectResponse
    {
        if (! $google->configured()) {
            $target = $login->authenticatedAccount($request)
                ? route('account.index', ['section' => 'security'])
                : route('account.login');

            return redirect($target)->withErrors([
                'google' => 'ورود با حساب گوگل هنوز پیکربندی و فعال نشده است.',
            ]);
        }

        $state = bin2hex(random_bytes(32));
        $request->session()->put('customer_google_state', $state);
        $request->session()->put('customer_google_linking', $login->authenticatedAccount($request) !== null);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function googleCallback(Request $request, GoogleAuthService $google, CustomerLogin $login): RedirectResponse
    {
        if (! $google->configured()) {
            return redirect()->route('account.login')->withErrors([
                'google' => 'ورود با حساب گوگل در حال حاضر فعال نیست.',
            ]);
        }

        $expectedState = $request->session()->pull('customer_google_state');
        $linking = (bool) $request->session()->pull('customer_google_linking', false);
        $receivedState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $receivedState) || $code === '') {
            return redirect()->route('account.login')->withErrors([
                'google' => 'درخواست ورود با گوگل منقضی یا نامعتبر است. لطفاً دوباره تلاش کنید.',
            ]);
        }

        $currentAccount = $linking ? $login->authenticatedAccount($request) : null;

        try {
            $googleUser = $google->exchangeAndResolveUser($code);
            $account = $google->findOrCreateAccount($googleUser, $currentAccount);
        } catch (HttpException $e) {
            $fallback = $currentAccount ? route('account.index', ['section' => 'security']) : route('account.login');

            return redirect($fallback)->withErrors(['google' => $e->getMessage()]);
        } catch (\Throwable) {
            $fallback = $currentAccount ? route('account.index', ['section' => 'security']) : route('account.login');

            return redirect($fallback)->withErrors(['google' => 'ارتباط با سرویس گوگل برقرار نشد. لطفاً دوباره تلاش کنید.']);
        }

        if ($currentAccount !== null) {
            return redirect()->route('account.index', ['section' => 'security'])
                ->with('success', 'حساب گوگل شما با موفقیت متصل شد.');
        }

        return $this->completeLogin($request, $account);
    }

    private function sendEmailOtp(Request $request, CustomerEmailLogin $emailLogin, string $email): RedirectResponse
    {
        try {
            $challenge = $emailLogin->issue($email);
            $request->session()->put('customer_email_challenge', $challenge);
            $request->session()->put('customer_email_resend_at', now()->addSeconds(CustomerEmailLogin::RESEND_SECONDS)->timestamp);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 429) {
                $wait = (int) ($e->getHeaders()['Retry-After'] ?? CustomerEmailLogin::RESEND_SECONDS);

                return redirect()->route('account.login', ['channel' => 'email'])
                    ->withErrors(['email' => CustomerAuthThrottle::message('resend_cooldown', $wait)]);
            }

            return redirect()->route('account.login', ['channel' => 'email'])
                ->withErrors(['email' => $e->getMessage() ?: 'ارسال کد تأیید ایمیلی ممکن نشد.']);
        }

        return redirect()->route('account.login', ['channel' => 'email'])
            ->with('success', 'کد تأیید شش‌رقمی به ایمیل شما ارسال شد.');
    }

    private function completeLogin(Request $request, CustomerAccount $account): RedirectResponse
    {
        Auth::guard('customer')->login($account);
        $request->session()->regenerate();
        $request->session()->put('customer_session_version', $account->session_version);
        app(ShoppingCart::class)->mergeGuestIntoAccount($request, $account);

        return redirect()->to(CustomerLoginFlow::destination($request));
    }
}
