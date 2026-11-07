<?php

namespace App\Http\Controllers\Payments;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Services\ZibalPayments;
use App\Support\AdminHistory;
use App\Support\FinanceAccess;
use App\Support\FinanceMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReceiptController extends Controller
{
    public function show(string $document, ZibalPayments $payments)
    {
        $document = $payments->ownedDocument(Auth::guard('customer')->user(), $document);

        return view('account.payment', ['document' => $document, 'deliveryPlan' => DeliveryPlan::query()->where('financial_document_id', $document->id)->first(), 'receipts' => PaymentReceipt::query()->where('financial_document_id', $document->id)->where('customer_account_id', Auth::guard('customer')->id())->latest('id')->get(), 'payments' => PaymentTransaction::query()->where('financial_document_id', $document->id)->latest('id')->get(), 'credit' => DB::table('payment_credit_events')->where('financial_document_id', $document->id)->sum('amount_delta')]);
    }

    public function store(Request $request, string $document, ZibalPayments $payments)
    {
        abort_unless(config('payments.bank.enabled') && preg_match('/\A[0-9]{16}\z/', (string) config('payments.bank.card_number')), 503, 'اطلاعات کارت بانکی هنوز آماده نشده است.');
        $data = $request->validate(['receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'], 'amount' => ['required', 'string', 'max:20'], 'bank_reference' => ['nullable', 'string', 'max:80']]);
        $amount = FinanceMoney::minor($data['amount']);
        abort_unless($amount > 0, 422);
        $path = null;
        try {
            DB::transaction(function () use ($payments, $document, $amount, $data, $request, &$path): void {
                $account = CustomerAccount::query()->lockForUpdate()->findOrFail(Auth::guard('customer')->id());
                abort_unless($account->is_active && $account->session_version === $request->session()->get('customer_session_version'), 403);
                $document = $payments->ownedDocument($account, $document, true);
                ZibalPayments::shippingReady($document);
                abort_unless($amount <= $document->charged_minor - $document->paid_minor, 409);
                abort_if(PaymentReceipt::query()->where('financial_document_id', $document->id)->where('state', 'pending')->exists(), 409, 'رسید قبلی هنوز بررسی نشده است.');
                $hash = hash_file('sha256', $request->file('receipt')->getRealPath());
                abort_if(PaymentReceipt::query()->where('file_hash', $hash)->exists(), 409, 'این تصویر قبلاً ارسال شده است.');
                $path = $request->file('receipt')->store('payment-receipts', 'local');
                abort_unless(is_string($path), 503);
                $receipt = new PaymentReceipt;
                foreach (['public_id' => (string) Str::uuid(), 'financial_document_id' => $document->id, 'customer_account_id' => $account->id, 'amount_minor' => $amount, 'path' => $path, 'file_hash' => $hash, 'bank_reference' => $data['bank_reference'] ?? null] as $key => $value) {
                    $receipt->$key = $value;
                }
                $receipt->save();
            }); // File writes must not be replayed by automatic DB deadlock retries.
        } catch (\Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'رسید برای بررسی ارسال شد؛ پرداخت هنوز تأیید نشده است.');
    }

    public function image(Request $request, string $receipt)
    {
        $receipt = PaymentReceipt::query()->where('public_id', $receipt)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        $admin = Auth::guard('web')->user();
        if ($request->routeIs('admin.payments.image')) {
            abort_unless($admin, 404);
            FinanceAccess::authorize($admin, 'receipt');
            $document = FinancialDocument::findOrFail($receipt->financial_document_id);
            FinanceAccess::source($admin, $document->sales_order_id ? 'order' : 'repair');
            AdminHistory::record(AdminAction::FinanceEvidenceViewed, 'payment_receipt', $receipt->id, $admin->id);
        } else {
            abort_unless($customer && $customer->is_active && $customer->session_version === $request->session()->get('customer_session_version') && $receipt->customer_account_id === $customer->id, 404);
        }
        $path = $receipt->path;
        abort_unless(is_string($path) && preg_match('~\Apayment-receipts/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)\z~D', $path), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY']);
    }
}
