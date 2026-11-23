<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Services\PaymentConnectivity;
use App\Services\ZibalGateway;
use App\Services\ZibalPayments;
use App\Support\FinanceMoney;
use App\Support\OrderMoney;
use App\Support\PaymentDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function start(Request $request, string $document, ZibalPayments $payments)
    {
        $data = $request->validate(['amount' => ['required', 'string', 'max:20'], 'operation_key' => ['required', 'uuid']]);
        $attempt = $payments->start(Auth::guard('customer')->user(), $document, FinanceMoney::minor($data['amount']), $data['operation_key']);
        $destination = $attempt->state === 'pending' && $attempt->track_id ? 'payments.connect' : 'payments.result';

        return redirect()->route($destination, $attempt->public_id, 303);
    }

    public function callback(Request $request, ZibalPayments $payments)
    {
        abort_unless($request->isMethod('GET'), 405);
        $track = $request->query('trackId');
        $order = $request->query('orderId');
        abort_unless(is_string($track) && ZibalGateway::trackNumber($track) !== null, 404);
        $attempt = PaymentAttempt::query()->where('track_id', $track)->where('merchant_digest', $payments->merchantDigest())->firstOrFail();
        abort_if($order !== null && (! is_string($order) || $order !== $attempt->public_id), 404);
        // Browser success/cancellation flags are not proof of payment or failure.
        $payments->verify($attempt);

        if (! Auth::guard('customer')->check()) {
            $document = FinancialDocument::query()->find($attempt->financial_document_id);
            if ($document?->repair_case_id) {
                $repairPublicId = RepairCase::query()->whereKey($document->repair_case_id)->value('public_id');
                if ($repairPublicId) {
                    return redirect()->to(
                        URL::temporarySignedRoute('payments.repair.link.result', now()->addHours(24), [
                            'repair' => $repairPublicId,
                            'payment' => $attempt->public_id,
                        ]),
                        303
                    );
                }
            }
        }

        return redirect()->route('payments.result', $attempt->public_id, 303);
    }

    public function connect(Request $request, string $payment, PaymentConnectivity $connectivity, ZibalPayments $payments, ZibalGateway $gateway)
    {
        [$attempt] = $this->paymentContext($payment, $payments);
        if (! $this->canContinue($attempt, $payments, $gateway)) {
            if ($request->expectsJson()) {
                return response()->json([], 409);
            }

            return redirect()->route('payments.result', $attempt->public_id);
        }
        $isIran = $connectivity->isIran($request);
        if ($request->expectsJson()) {
            return response()->json(['ir' => $isIran]);
        }
        $isBrowserNavigation = $request->headers->get('Sec-Fetch-Mode') === 'navigate';
        if ($isIran !== false && ! $isBrowserNavigation) {
            return $this->sendToGateway($attempt, $isIran === null);
        }

        return response()->view('payments.connect', [
            'payment' => $attempt->public_id,
            'verifySplitVpn' => $isIran !== false,
        ], 200, ['Referrer-Policy' => 'no-referrer']);
    }

    public function gateway(Request $request, string $payment, ZibalPayments $payments, ZibalGateway $gateway)
    {
        [$attempt] = $this->paymentContext($payment, $payments);
        if (! $this->canContinue($attempt, $payments, $gateway)) {
            return redirect()->route('payments.result', $attempt->public_id);
        }

        return $this->sendToGateway($attempt, ! $request->boolean('continue'));
    }

    public function result(string $payment, ZibalPayments $payments, ZibalGateway $gateway)
    {
        [$attempt, $document] = $this->paymentContext($payment, $payments);
        $isOrder = (bool) $document->sales_order_id;
        $order = $isOrder ? SalesOrder::query()->whereKey($document->sales_order_id)->first(['public_id']) : null;
        $repair = ! $isOrder && $document->repair_case_id ? RepairCase::query()->whereKey($document->repair_case_id)->first(['public_id', 'tracking_reference']) : null;
        $source = $order?->public_id ?? $repair?->public_id;
        $sourceCode = $isOrder
            ? ($order ? strtoupper(substr($order->public_id, 0, 8)) : strtoupper(substr($document->public_id, 0, 8)))
            : ($repair?->tracking_reference ?? strtoupper(substr($document->public_id, 0, 8)));
        $destination = $isOrder ? 'payments.order' : 'payments.repair';
        $documentUrl = $source ? route($destination, $source) : route('payments.document', $document->public_id);
        $canContinue = $this->canContinue($attempt, $payments, $gateway);

        return view('payments.result', [
            'attempt' => $attempt,
            'document' => $document,
            'isOrder' => $isOrder,
            'sourceCode' => $sourceCode,
            'trackingUrl' => $source
                ? route($isOrder ? 'account.orders.show' : 'account.repairs.show', ['record' => $source])
                : route('account.index', ['section' => $isOrder ? 'orders' : 'repairs']),
            'retryUrl' => match (true) {
                $canContinue => route('payments.connect', $attempt->public_id),
                $attempt->state === 'failed' => $documentUrl,
                default => route('payments.result', $attempt->public_id),
            },
        ]);
    }

    public function paymentStatus(string $payment, ZibalPayments $payments)
    {
        [$attempt] = $this->paymentContext($payment, $payments);

        return response()->json(['status' => $this->attemptStatus($attempt)]);
    }

    private function paymentContext(string $payment, ZibalPayments $payments): array
    {
        $account = Auth::guard('customer')->user();
        $attempt = PaymentAttempt::query()->where('public_id', $payment)->where('customer_account_id', $account->id)->firstOrFail();
        $document = FinancialDocument::findOrFail($attempt->financial_document_id);

        return [$attempt, $payments->ownedDocument($account, $document->public_id)];
    }

    private function canContinue(PaymentAttempt $attempt, ZibalPayments $payments, ZibalGateway $gateway): bool
    {
        return $attempt->state === 'pending' && ZibalGateway::trackNumber($attempt->track_id) !== null
            && $gateway->available() && hash_equals($attempt->merchant_digest, $payments->merchantDigest());
    }

    private function sendToGateway(PaymentAttempt $attempt, bool $indeterminate = false)
    {
        // A same-origin document makes even a cold/direct entry send the merchant origin.
        // The attempt UUID and query string must never be part of the bank's Referer.
        return response()->view('payments.forward', [
            'gatewayUrl' => ZibalGateway::START_URL.$attempt->track_id,
            'indeterminate' => $indeterminate,
        ], headers: ['Referrer-Policy' => 'origin']);
    }

    private function ownedAttempt(Request $request, string $trackKey = 'track_id'): ?PaymentAttempt
    {
        $account = Auth::guard('customer')->user();
        if (! $account || ! $account->is_active || $request->session()->get('customer_session_version') !== $account->session_version) {
            return null;
        }
        $order = $request->query('order_id');
        $track = $request->query($trackKey);
        if (! is_string($order) || ! Str::isUuid($order) || ($track !== null && ! is_string($track))) {
            return null;
        }

        return PaymentAttempt::query()->where('public_id', $order)->where('customer_account_id', $account->id)->when($track !== null && $track !== '0', fn ($q) => $q->where('track_id', $track))->first();
    }

    public function status(Request $request)
    {
        $attempt = $this->ownedAttempt($request);
        $status = $attempt ? $this->attemptStatus($attempt) : 'error';

        // Compatibility GET polling is read-only; callback and cron do verification.
        return response()->json(['status' => $status, 'phase' => 'gateway', 'code' => $attempt ? 'not_confirmed_support' : 'missing_params'], $attempt ? 200 : 404);
    }

    private function attemptStatus(PaymentAttempt $attempt): string
    {
        return match ($attempt->state) {
            'settled' => 'success', 'failed' => 'failed', 'review' => 'error', default => 'processing',
        };
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

    public function page(Request $request, string $page, ZibalPayments $payments)
    {
        abort_unless(in_array($page, ['index', 'canceled', 'error', 'failed', 'paid', 'processing', 'success'], true), 404);
        if ($page === 'index') {
            // Resolve the old URL shape only to a stored, session-owned attempt. Never redirect to the input.
            $target = $request->query('pay_url');
            abort_unless(is_string($target) && preg_match('~\A'.preg_quote(ZibalGateway::START_URL, '~').'([0-9]{1,20})\z~D', $target, $parts), 404);
            $account = Auth::guard('customer')->user();
            abort_unless($account && $account->is_active && $request->session()->get('customer_session_version') === $account->session_version, 404);
            $attempt = PaymentAttempt::query()->where('track_id', $parts[1])->where('merchant_digest', $payments->merchantDigest())
                ->where('customer_account_id', $account->id)->firstOrFail();

            return redirect()->route('payments.connect', $attempt->public_id);
        }

        if ($attempt = $this->ownedAttempt($request, 'ref_id')) {
            return redirect()->route('payments.result', $attempt->public_id);
        }

        return response()->view('payments.issue', ['retryUrl' => route('cart.review')], 200, ['X-Frame-Options' => 'DENY']);
    }
}
