<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Services\ZibalPayments;
use App\Support\FinanceMoney;
use App\Support\OrderMoney;
use App\Support\PaymentDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function start(Request $request, string $document, ZibalPayments $payments)
    {
        $data = $request->validate(['amount' => ['required', 'string', 'max:20'], 'operation_key' => ['required', 'uuid']]);
        $attempt = $payments->start(Auth::guard('customer')->user(), $document, FinanceMoney::minor($data['amount']), $data['operation_key']);
        if ($attempt->state !== 'pending' || ! $attempt->track_id) {
            $page = match ($attempt->state) {
                'settled' => 'success', 'failed' => 'failed', 'review' => 'error', default => 'processing',
            };

            return redirect('/pay/'.$page.'.html?'.http_build_query(['order_id' => $attempt->public_id, 'ref_id' => $attempt->track_id ?? '0']));
        }

        return redirect('/pay/index.html?'.http_build_query(['pay_url' => 'https://gateway.zibal.ir/start/'.$attempt->track_id]));
    }

    public function callback(Request $request, ZibalPayments $payments)
    {
        abort_unless($request->isMethod('GET'), 405);
        $track = $request->query('trackId');
        $order = $request->query('orderId');
        abort_unless(is_string($track) && preg_match('/\A[0-9]{1,20}\z/D', $track), 404);
        $attempt = PaymentAttempt::query()->where('track_id', $track)->where('merchant_digest', $payments->merchantDigest())->firstOrFail();
        abort_if($order !== null && (! is_string($order) || $order !== $attempt->public_id), 404);
        // Browser success/cancellation flags are not proof of payment or failure.
        $attempt = $payments->verify($attempt);
        $page = match ($attempt->state) {
            'settled' => 'success', 'failed' => 'failed', 'review' => 'error', default => 'processing',
        };

        return redirect('/pay/'.$page.'.html?'.http_build_query(['order_id' => $attempt->public_id, 'ref_id' => $track]));
    }

    private function ownedAttempt(Request $request): ?PaymentAttempt
    {
        $account = Auth::guard('customer')->user();
        if (! $account || ! $account->is_active || $request->session()->get('customer_session_version') !== $account->session_version) {
            return null;
        }
        $order = $request->query('order_id');
        $track = $request->query('track_id');
        if (! is_string($order) || ! Str::isUuid($order) || ($track !== null && ! is_string($track))) {
            return null;
        }

        return PaymentAttempt::query()->where('public_id', $order)->where('customer_account_id', $account->id)->when($track !== null && $track !== '0', fn ($q) => $q->where('track_id', $track))->first();
    }

    public function status(Request $request)
    {
        $attempt = $this->ownedAttempt($request);
        $status = $attempt ? match ($attempt->state) {
            'settled' => 'success', 'failed' => 'failed', 'review' => 'error', default => 'processing',
        } : 'error';

        // Compatibility GET polling is read-only; callback and cron do verification.
        return response()->json(['status' => $status, 'phase' => 'gateway', 'code' => $attempt ? 'not_confirmed_support' : 'missing_params'], $attempt ? 200 : 404);
    }

    public function receipt(Request $request)
    {
        $attempt = $this->ownedAttempt($request);
        if (! $attempt || $attempt->state !== 'settled') {
            return response()->json(['ok' => false, 'paid' => false]);
        }
        $transaction = PaymentTransaction::query()->where('payment_attempt_id', $attempt->id)->firstOrFail();

        $data = ['ok' => true, 'paid' => true, 'track' => $attempt->track_id];
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/', $transaction->provider_paid_at ?? '', $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) && (int) $parts[4] < 24 && (int) $parts[5] < 60) {
            [$year, $month, $day] = PaymentDate::gregorianToJalali((int) $parts[1], (int) $parts[2], (int) $parts[3]);
            $data['date'] = sprintf('%04d/%02d/%02d', $year, $month, $day);
            $data['time'] = $parts[4].':'.$parts[5];
        }
        // Exact JSON decimal for the frozen frontend's Number display contract;
        // no float conversion or rounding is used in server money calculations.
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        return response(substr($json, 0, -1).',"amount":'.OrderMoney::decimal($transaction->amount_minor).'}', 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function page(string $page)
    {
        abort_unless(in_array($page, ['index', 'canceled', 'error', 'failed', 'paid', 'processing', 'success'], true), 404);

        return response(file_get_contents(resource_path('payments/pay/'.$page.'.html')), 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Frame-Options' => 'DENY']);
    }
}
