<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Services\PaymentReview;
use App\Support\FinanceMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $documents = FinancialDocument::query()->where(function ($q) use ($request): void {
            $q->whereRaw('1=0');
            if ($request->user()->can('orders.view')) {
                $q->orWhereNotNull('sales_order_id');
            }
            if ($request->user()->can('repairs.view')) {
                $q->orWhereNotNull('repair_case_id');
            }
        })->select('id');

        return view('admin.payments', [
            'receipts' => PaymentReceipt::query()->whereIn('financial_document_id', clone $documents)->where('state', 'pending')->oldest('id')->paginate(20),
            'credits' => DB::table('payment_credit_events')->join('financial_documents', 'financial_documents.id', '=', 'payment_credit_events.financial_document_id')->whereIn('financial_document_id', clone $documents)->selectRaw('financial_documents.public_id, SUM(amount_delta) AS balance')->groupBy('financial_documents.public_id')->havingRaw('SUM(amount_delta) > 0')->paginate(20, ['*'], 'credits_page'),
            'attempts' => PaymentAttempt::query()->whereIn('financial_document_id', clone $documents)->whereIn('state', ['review', 'uncertain', 'requesting', 'pending', 'processing', 'verified'])->latest('id')->paginate(20, ['*'], 'attempts_page'),
        ]);
    }

    public function review(Request $request, string $receipt, PaymentReview $service)
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255'], 'decision' => ['required', 'in:approve,reject'], 'note' => ['required', 'string', 'min:5', 'max:1000'], 'funds_confirmed' => [Rule::when($request->input('decision') === 'approve', ['accepted'], ['nullable'])], 'amount' => ['required_if:decision,approve', 'nullable', 'string', 'max:20'], 'reference' => ['required_if:decision,approve', 'nullable', 'string', 'max:80']]);
        $service->review($request->user(), $data['password'], $receipt, $data['decision'] === 'approve', $data['note'], isset($data['amount']) ? FinanceMoney::minor($data['amount']) : null, $data['reference'] ?? null);

        return back()->with('success', 'نتیجهٔ بررسی ثبت شد.');
    }

    public function resolveAttempt(Request $request, string $attempt, PaymentReview $service)
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255'],
            'note' => ['required', 'string', 'min:10', 'max:1000'], 'unpaid_confirmed' => ['accepted']]);
        $service->resolveUnpaid($request->user(), $data['password'], $attempt, $data['note']);

        return back()->with('success', 'نتیجهٔ بررسی ثبت شد. هر تأیید دیرهنگام درگاه همچنان بررسی می‌شود.');
    }

    public function store(Request $request, PaymentReview $service)
    {
        $data = $request->validate(['document' => ['required', 'uuid'], 'amount' => ['required', 'string', 'max:20'], 'operation_key' => ['required', 'uuid'], 'password' => ['required', 'string', 'max:255'], 'funds_confirmed' => ['accepted']]);
        $service->inStore($request->user(), $data['password'], $data['document'], FinanceMoney::minor($data['amount']), $data['operation_key']);

        return back()->with('success', 'دریافت وجه در فروشگاه ثبت شد.');
    }

    public function refund(Request $request, PaymentReview $service)
    {
        $data = $request->validate(['document' => ['required', 'uuid'], 'amount' => ['required', 'string', 'max:20'], 'reference' => ['required', 'string', 'min:6', 'max:80'], 'operation_key' => ['required', 'uuid'], 'password' => ['required', 'string', 'max:255'], 'refund_confirmed' => ['accepted']]);
        $service->recordRefund($request->user(), $data['password'], $data['document'], FinanceMoney::minor($data['amount']), $data['reference'], $data['operation_key']);

        return back()->with('success', 'بازپرداخت انجام‌شده ثبت شد؛ انتقال وجه خودکار نیست.');
    }
}
