<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\FaNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const IMAGE_DIRECTORY = 'products';

    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

    public function index(): View
    {
        return view('admin.products.index', [
            'products' => Product::query()->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', [
            'product' => new Product,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizePrice($request);
        $validated = $this->validateProduct($request);

        $data = $this->productData($request, $validated);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store(self::IMAGE_DIRECTORY, 'public');
        }

        Product::create($data);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول جدید با موفقیت ایجاد شد.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.edit', [
            'product' => $product,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->normalizePrice($request);
        $validated = $this->validateProduct($request, $product);

        $data = $this->productData($request, $validated);

        $oldImagePath = $product->image_path;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store(self::IMAGE_DIRECTORY, 'public');
        }

        $product->update($data);

        if (isset($data['image_path']) && $oldImagePath !== null && $oldImagePath !== $data['image_path']) {
            $this->deleteImage($oldImagePath);
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول با موفقیت ویرایش شد.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $imagePath = $product->image_path;

        $product->delete();

        $this->deleteImage($imagePath);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول حذف شد.');
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product?->getKey()),
            ],
            'summary' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'category' => ['required', 'string', Rule::enum(ProductCategory::class)],
            'condition' => ['required', 'string', Rule::enum(ProductCondition::class)],
            'price' => ['nullable', 'integer', 'min:0'],
            'stock_status' => ['required', 'string', Rule::enum(StockStatus::class)],
            'spec_labels' => ['nullable', 'array', 'max:30'],
            'spec_labels.*' => ['nullable', 'string', 'max:100'],
            'spec_values' => ['nullable', 'array', 'max:30'],
            'spec_values.*' => ['nullable', 'string', 'max:200'],
            'image' => self::IMAGE_RULES,
        ], [
            'slug.regex' => 'اسلاگ باید فقط شامل حروف کوچک انگلیسی، اعداد و خط تیره باشد.',
            'slug.unique' => 'این اسلاگ قبلاً برای محصول دیگری استفاده شده است.',
            'image.mimes' => 'فرمت تصویر باید یکی از JPEG، PNG یا WebP باشد.',
            'image.max' => 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.',
        ]);
    }

    private function normalizePrice(Request $request): void
    {
        $price = $request->input('price');

        if (is_string($price) && trim($price) !== '') {
            $request->merge(['price' => FaNumber::normalizeDigits(trim($price))]);
        }
    }

    private function productData(Request $request, array $validated): array
    {
        return [
            'name' => $validated['name'],
            'slug' => $this->resolveSlug($request, $validated),
            'summary' => $validated['summary'] ?? null,
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'condition' => $validated['condition'],
            'price' => $validated['price'] ?? null,
            'stock_status' => $validated['stock_status'],
            'is_published' => $request->boolean('is_published'),
            'is_sample' => $request->boolean('is_sample'),
            'specifications' => $this->specifications($validated),
        ];
    }

    private function resolveSlug(Request $request, array $validated): string
    {
        $slug = trim((string) ($validated['slug'] ?? ''));

        if ($slug !== '') {
            return $slug;
        }

        $base = Str::slug($validated['name']);
        $slug = $base !== '' ? $base : 'product-'.Str::lower(Str::random(8));

        $taken = fn (string $candidate): bool => Product::query()
            ->where('slug', $candidate)
            ->when($request->route('product') instanceof Product, fn ($query) => $query->whereKeyNot($request->route('product')->getKey()))
            ->exists();

        while ($taken($slug)) {
            $slug = ($base !== '' ? $base : 'product').'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function specifications(array $validated): array
    {
        $specifications = [];

        foreach ($validated['spec_labels'] ?? [] as $index => $label) {
            $label = trim((string) $label);

            if ($label === '') {
                continue;
            }

            $specifications[$label] = trim((string) ($validated['spec_values'][$index] ?? ''));
        }

        return $specifications;
    }

    private function deleteImage(?string $path): void
    {
        if ($path === null || ! str_starts_with($path, self::IMAGE_DIRECTORY.'/')) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
