<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\RepairCase;
use App\Services\PaymentConnectivity;
use App\Services\ZibalGateway;
use App\Services\ZibalPayments;
use App\Support\RepairPaymentCode;
use App\Support\RepairTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RepairPaymentLinkController extends Controller
{
    public function short(string $code, ZibalGateway $gateway)
    {
        $link = RepairPaymentCode::resolve($code);
        abort_unless($link, 404);

        return $this->paymentView($link['repair'], $gateway, $link['expires']);
    }

    public function show(string $repair, ZibalGateway $gateway)
    {
        return $this->paymentView($repair, $gateway);
    }

    private function paymentView(string $repair, ZibalGateway $gateway, ?int $shortLinkExpiry = null)
    {
        $case = RepairCase::query()->where('public_id', $repair)->firstOrFail();
        $document = FinancialDocument::query()->where('repair_case_id', $case->id)->firstOrFail();
        $balance = max(0, $document->charged_minor - $document->paid_minor);
        $paidTransactions = $shortLinkExpiry ? collect() : PaymentTransaction::query()
            ->where('financial_document_id', $document->id)
            ->latest('id')
            ->get();

        return view('payments.repair-link', [
            'case' => $case,
            'document' => $document,
            'balance' => $balance,
            'isPaid' => $document->charged_minor > 0 && $balance === 0,
            'gatewayAvailable' => $gateway->available(),
            'startUrl' => URL::temporarySignedRoute('payments.repair.link.start', Carbon::createFromTimestamp(min($shortLinkExpiry ?? PHP_INT_MAX, now()->addHours(2)->timestamp)), ['repair' => $case->public_id]),
            'operationKey' => (string) Str::uuid(),
            'transactions' => $paidTransactions,
            'showDevice' => $shortLinkExpiry === null,
        ]);
    }

    public function start(Request $request, string $repair, ZibalPayments $payments)
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $case = RepairCase::query()->where('public_id', $repair)->firstOrFail();
        $attempt = $payments->startForRepairLink($case, $data['operation_key']);

        if ($attempt->state === 'pending' && $attempt->track_id) {
            return redirect()->to(
                URL::temporarySignedRoute('payments.repair.link.connect', now()->addHours(2), [
                    'repair' => $case->public_id,
                    'payment' => $attempt->public_id,
                ]),
                303
            );
        }

        return redirect()->to(
            URL::temporarySignedRoute('payments.repair.link.result', now()->addHours(24), [
                'repair' => $case->public_id,
                'payment' => $attempt->public_id,
            ]),
            303
        );
    }

    public function connect(Request $request, string $repair, string $payment, PaymentConnectivity $connectivity, ZibalPayments $payments, ZibalGateway $gateway)
    {
        [$case, , $attempt] = $this->resolveContext($repair, $payment);
        $canContinue = $this->canContinue($attempt, $payments, $gateway);
        if (! $canContinue) {
            if ($request->expectsJson()) {
                return response()->json([], 409);
            }

            return redirect()->to(URL::temporarySignedRoute('payments.repair.link.result', now()->addHours(24), [
                'repair' => $case->public_id,
                'payment' => $attempt->public_id,
            ]));
        }

        $isIran = $connectivity->isIran($request);
        if ($request->expectsJson()) {
            return response()->json(['ir' => $isIran]);
        }
        $context = ['repair' => $case->public_id, 'payment' => $attempt->public_id];
        $resultUrl = URL::temporarySignedRoute('payments.repair.link.result', now()->addHours(24), $context);
        $direct = $request->boolean('direct');
        $confirm = $request->boolean('confirm');
        if ($confirm || ($isIran !== false && ($direct || $request->headers->get('Sec-Fetch-Mode') !== 'navigate'))) {
            return response()->view('payments.forward', [
                'gatewayUrl' => ZibalGateway::START_URL.$attempt->track_id,
                'indeterminate' => $confirm || $isIran === null,
            ], headers: ['Referrer-Policy' => 'origin']);
        }

        $linkExpiry = now()->addHours(2);
        $checkUrl = URL::temporarySignedRoute('payments.repair.link.connect', $linkExpiry, $context);
        $gatewayRouteUrl = URL::temporarySignedRoute('payments.repair.link.connect', $linkExpiry, $context + ['direct' => 1]);
        $indeterminateUrl = URL::temporarySignedRoute('payments.repair.link.connect', $linkExpiry, $context + ['confirm' => 1]);

        return response()->view('payments.connect', [
            'payment' => $attempt->public_id,
            'checkUrl' => $checkUrl,
            'gatewayRouteUrl' => $gatewayRouteUrl,
            'indeterminateUrl' => $indeterminateUrl,
            'resultUrl' => $resultUrl,
            'verifySplitVpn' => $isIran !== false,
        ], 200, ['Referrer-Policy' => 'no-referrer']);
    }

    public function result(string $repair, string $payment, ZibalPayments $payments, ZibalGateway $gateway)
    {
        [$case, $document, $attempt] = $this->resolveContext($repair, $payment);
        $repairLinkUrl = route('payments.repair.short', ['code' => RepairPaymentCode::issue($case->public_id, now()->addDays(14)->timestamp)]);
        $canContinue = $this->canContinue($attempt, $payments, $gateway);

        return view('payments.result', [
            'attempt' => $attempt,
            'document' => $document,
            'isOrder' => false,
            'sourceCode' => (string) $case->tracking_reference,
            'trackingUrl' => RepairTracking::url($case->tracking_reference),
            'retryUrl' => match (true) {
                $canContinue => URL::temporarySignedRoute('payments.repair.link.connect', now()->addHours(2), ['repair' => $case->public_id, 'payment' => $attempt->public_id]),
                $attempt->state === 'failed' => $repairLinkUrl,
                default => request()->fullUrl(),
            },
        ]);
    }

    private function resolveContext(string $repair, string $payment): array
    {
        $case = RepairCase::query()->where('public_id', $repair)->firstOrFail();
        $document = FinancialDocument::query()->where('repair_case_id', $case->id)->firstOrFail();
        $attempt = PaymentAttempt::query()
            ->where('public_id', $payment)
            ->where('financial_document_id', $document->id)
            ->firstOrFail();

        return [$case, $document, $attempt];
    }

    private function canContinue(PaymentAttempt $attempt, ZibalPayments $payments, ZibalGateway $gateway): bool
    {
        return $attempt->state === 'pending'
            && ZibalGateway::trackNumber($attempt->track_id) !== null
            && $gateway->available()
            && hash_equals($attempt->merchant_digest, $payments->merchantDigest());
    }
}
