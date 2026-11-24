<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Services\ProductPurchaseVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductFeedbackController extends Controller
{
    public const REPORT_REASONS = ['privacy' => 'اطلاعات شخصی', 'spam' => 'هرزنامه', 'abuse' => 'محتوای نامناسب'];

    public function store(Request $request, Product $product, ProductPurchaseVerification $purchase): RedirectResponse
    {
        abort_unless($product->is_published && ! $product->is_sample && ! $product->isPrivatePaymentTest(), 404);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['review', 'question'])],
            'body' => ['required', 'string', 'min:5', 'max:1500'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'website' => ['prohibited'],
        ]);
        $account = Auth::guard('customer')->user();
        DB::transaction(function () use ($product, $account, $purchase, $data): void {
            $owner = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($owner->is_active && $owner->session_version === $account->session_version && $owner->verified_at, 403);
            $published = Product::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($published->is_published && ! $published->is_sample && ! $published->isPrivatePaymentTest(), 404);
            $review = $data['kind'] === 'review';
            $verified = $review && $purchase->verified($owner, $product);
            if (isset($data['rating']) && ! $verified) {
                throw ValidationException::withMessages(['rating' => 'امتیاز فقط برای خریدار تأییدشده فعال است.']);
            }
            $entry = $review ? ProductFeedback::query()->where('product_id', $product->id)
                ->where('review_owner_id', $owner->id)->lockForUpdate()->first() : null;
            $entry ??= new ProductFeedback;
            $entry->forceFill([
                'product_id' => $product->id, 'customer_account_id' => $owner->id,
                'review_owner_id' => $review ? $owner->id : null, 'kind' => $data['kind'], 'status' => 'pending',
                'body' => $data['body'], 'rating' => $verified ? ($data['rating'] ?? null) : null,
                'verified_buyer' => $verified, 'staff_reply' => null,
            ])->save();
        }, 3);

        return redirect()->route('products.show', $product)->with('feedback_status', 'نوشتهٔ شما برای بررسی ثبت شد.');
    }

    public function report(Request $request, Product $product, ProductFeedback $feedback): RedirectResponse
    {
        abort_unless($product->is_published && ! $product->is_sample && ! $product->isPrivatePaymentTest()
            && $feedback->product_id === $product->id && $feedback->status === 'approved', 404);
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(self::REPORT_REASONS))]]);
        $account = Auth::guard('customer')->user();
        abort_unless($account->verified_at, 403);
        DB::transaction(function () use ($feedback, $account, $data): void {
            $entry = ProductFeedback::query()->lockForUpdate()->findOrFail($feedback->id);
            abort_unless($entry->status === 'approved', 404);
            $report = $entry->reports()->firstOrNew(['customer_account_id' => $account->id]);
            if (! $report->exists || $report->resolved_at !== null) {
                $report->forceFill(['reason' => $data['reason'], 'resolved_at' => null])->save();
            }
        }, 3);

        return redirect()->route('products.show', $product)->with('feedback_status', 'گزارش شما ثبت شد.');
    }
}
