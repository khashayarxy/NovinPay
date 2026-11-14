<?php

namespace App\Http\Controllers;

use App\Enums\ProductCategory;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::enum(ProductCategory::class)],
        ], [
            'q.max' => 'عبارت جست‌وجو نباید بیشتر از ۱۰۰ کاراکتر باشد.',
            'category' => 'دسته‌بندی انتخاب‌شده معتبر نیست.',
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $category = isset($validated['category'])
            ? ProductCategory::from($validated['category'])
            : null;

        $products = Product::query()
            ->published()
            ->when($term !== '', fn ($query) => $query->search($term))
            ->when($category !== null, fn ($query) => $query->category($category))
            ->latest()
            ->paginate(12)
            // Append only the validated filter parameters — never the raw
            // query string — so unrelated or potentially sensitive params
            // (e.g. tracking tokens) are not propagated into page links.
            ->appends(array_filter([
                'q' => $term !== '' ? $term : null,
                'category' => $category?->value,
            ]));

        return view('products.index', [
            'products' => $products,
            'term' => $term,
            'category' => $category,
            'categories' => ProductCategory::cases(),
            'hasFilters' => $term !== '' || $category !== null,
            'canonical' => rtrim(config('app.url'), '/').route('products.index', [], false)
                .($products->currentPage() > 1 ? '?page='.$products->currentPage() : ''),
            'publicRobots' => $term !== '' || $category !== null || array_diff(array_keys($request->query()), ['page'])
                ? 'noindex, follow' : 'index, follow',
        ]);
    }

    public function show(Product $product): View
    {
        abort_unless($product->is_published, 404);

        return view('products.show', [
            'product' => $product,
            'canonical' => rtrim(config('app.url'), '/').route('products.show', $product, false),
            'publicRobots' => $product->is_sample ? 'noindex, follow' : 'index, follow',
        ]);
    }
}
