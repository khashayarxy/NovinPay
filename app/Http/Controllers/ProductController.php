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
            'q.max' => 'عبارت جستجو نباید بیش از ۱۰۰ نویسه باشد.',
            'category' => 'دسته‌بندی انتخاب‌شده معتبر نیست.',
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $category = isset($validated['category'])
            ? ProductCategory::from($validated['category'])
            : null;

        $products = Product::query()
            ->when($term !== '', fn ($query) => $query->search($term))
            ->when($category !== null, fn ($query) => $query->category($category))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'term' => $term,
            'category' => $category,
            'categories' => ProductCategory::cases(),
            'hasFilters' => $term !== '' || $category !== null,
        ]);
    }

    public function show(Product $product): View
    {
        return view('products.show', [
            'product' => $product,
        ]);
    }
}
