<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Support\AdminHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductFeedbackController extends Controller
{
    public function index(Product $product): View
    {
        $entries = ProductFeedback::query()->where('product_id', $product->id)
            ->with(['reports' => fn ($query) => $query->whereNull('resolved_at')])
            ->orderByDesc('id')->paginate(20);

        return view('admin.products.feedback', compact('product', 'entries'));
    }

    public function update(Request $request, Product $product, ProductFeedback $feedback): RedirectResponse
    {
        abort_unless($feedback->product_id === $product->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'staff_reply' => ['nullable', 'string', 'max:1500'], 'resolve_reports' => ['sometimes', 'boolean'],
            'revision' => ['required', 'string', 'size:64'],
        ]);
        DB::transaction(function () use ($feedback, $data): void {
            $entry = ProductFeedback::query()->lockForUpdate()->findOrFail($feedback->id);
            abort_unless(hash_equals(self::revision($entry), $data['revision']), 409, 'نوشته تغییر کرده است؛ صفحه را تازه کنید.');
            $entry->forceFill(['status' => $data['status'], 'staff_reply' => $data['staff_reply'] ?? null])->save();
            if ($data['resolve_reports'] ?? false) {
                $entry->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);
            }
            AdminHistory::record(AdminAction::ProductFeedbackModerated, 'product_feedback', $entry->id);
        });

        return back()->with('success', 'وضعیت نوشته ذخیره شد.');
    }

    public static function revision(ProductFeedback $entry): string
    {
        return hash('sha256', json_encode([$entry->body, $entry->rating, $entry->verified_buyer, $entry->status, $entry->staff_reply], JSON_THROW_ON_ERROR));
    }
}
