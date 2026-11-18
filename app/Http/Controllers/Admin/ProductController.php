<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Enums\StockStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use App\Support\FinanceTime;
use App\Support\OrderMoney;
use App\Support\ProductSale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    private const IMAGE_DIRECTORY = 'products';

    private const IMAGE_RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

    public function index(): View
    {
        $products = Product::query()->latest()->paginate(15);
        $stockByProduct = null;

        if (Gate::allows('inventory.view')) {
            $stockByProduct = InventoryItem::query()
                ->whereIn('product_id', $products->modelKeys())
                ->select('product_id')
                ->selectRaw('SUM(on_hand) AS physical, SUM(reserved) AS reserved, MAX(is_low_stock) AS low')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');
        }

        return view('admin.products.index', [
            'products' => $products,
            'stockByProduct' => $stockByProduct,
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
        $this->normalizeNumbers($request);
        $validated = $this->validateProduct($request);

        $data = $this->productData($request, $validated);

        $uploads = $this->storeMedia($request, $validated);

        try {
            DB::transaction(function () use ($data, $uploads, $validated): void {
                $product = new Product($data);
                if (! empty($validated['historical_price_date'])) {
                    $this->recordHistoricalPriceDate($product, $validated['historical_price_date']);
                }
                $this->applyMedia($product, $uploads, $validated);
                $product->save();
                AdminHistory::record(AdminAction::ProductCreated, 'product', $product->id);
                if ($product->price_changed_at) {
                    AdminHistory::record(AdminAction::ProductPriceChanged, 'product', $product->id);
                }
            });
        } catch (Throwable $exception) {
            // The database write failed after the upload was persisted;
            // remove the just-stored file so it does not become orphaned.
            $this->deleteUploads($uploads);

            throw $exception;
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول جدید با موفقیت ایجاد شد.');
    }

    public function edit(Product $product): View
    {
        $matchingInventory = Gate::allows('inventory.view')
            ? $product->inventoryItems()->where('condition', $product->condition->value)->get(['id', 'on_hand', 'reserved'])
            : null;

        return view('admin.products.edit', [
            'product' => $product,
            'matchingInventory' => $matchingInventory,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->normalizeNumbers($request);
        $validated = $this->validateProduct($request, $product);

        $data = $this->productData($request, $validated);

        $uploads = $this->storeMedia($request, $validated);

        try {
            $replaced = DB::transaction(function () use ($product, $data, $uploads, $validated): array {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $previous = $this->mediaPaths($locked);
                if (isset($data['product_details'])) {
                    $data['product_details'] = array_replace($locked->product_details ?? [], $data['product_details']);
                }
                $locked->fill($data);
                $changedPrice = $locked->isDirty('price');
                $historical = $validated['historical_price_date'] ?? null;
                if ($historical !== null) {
                    $this->recordHistoricalPriceDate($locked, $historical);
                }
                $this->applyMedia($locked, $uploads, $validated);
                $locked->save();
                AdminHistory::record(AdminAction::ProductUpdated, 'product', $locked->id);
                if ($changedPrice || $historical !== null) {
                    AdminHistory::record($changedPrice ? AdminAction::ProductPriceChanged : AdminAction::ProductPriceDateRecorded,
                        'product', $locked->id);
                }

                return array_diff($previous, $this->mediaPaths($locked));
            });
        } catch (Throwable $exception) {
            // The database write failed after the replacement was persisted;
            // remove the new file so it does not become orphaned. The old
            // image and the record both stay untouched.
            $this->deleteUploads($uploads);

            throw $exception;
        }

        foreach ($replaced as $path) {
            $this->deleteImage($path);
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول با موفقیت ویرایش شد.');
    }

    private function recordHistoricalPriceDate(Product $product, string $value): void
    {
        $date = FinanceTime::parsePersianDate($value);
        if (! $product->exists || $product->isDirty('price') || $product->price_changed_at
            || ! ProductSale::priceConfirmed($product) || ! $date || $date->isFuture()) {
            throw ValidationException::withMessages([
                'historical_price_date' => 'تاریخ مستند شمسی را فقط برای قیمت فعلیِ بدون تاریخ و بدون تغییر قیمت وارد کنید.',
            ]);
        }

        $product->price_changed_at = $date;
    }

    public function destroy(Product $product): RedirectResponse
    {
        $paths = DB::transaction(function () use ($product): array {
            // The catalog association is part of retained stock history.
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            abort_if(InventoryItem::query()->where('product_id', $locked->id)->exists(), 409, 'محصول مرتبط با موجودی قابل حذف نیست؛ انتشار آن را غیرفعال کنید.');
            abort_if(ProductFeedback::query()->where('product_id', $locked->id)->exists(), 409, 'محصول دارای دیدگاه یا پرسش است؛ انتشار آن را غیرفعال کنید.');
            $paths = $this->mediaPaths($locked);
            $product->delete();
            AdminHistory::record(AdminAction::ProductDeleted, 'product', $product->id);

            return $paths;
        });

        foreach ($paths as $path) {
            $this->deleteImage($path);
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'محصول حذف شد.');
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $family = $request->exists('variant_family') ? $request->input('variant_family') : $product?->variant_family;
        $colorRules = ['nullable', 'string', 'max:100'];
        if (is_string($family) && $family !== '') {
            $colorRules = ['required', 'string', 'max:100',
                Rule::unique('products', 'color')->where('variant_family', $family)->ignore($product?->id)];
        }

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
            'price' => ['nullable', 'integer', 'min:0', 'max:'.OrderMoney::MAX_UNIT_MAJOR],
            'historical_price_date' => ['nullable', 'string', 'max:10'],
            'stock_status' => ['required', 'string', Rule::enum(StockStatus::class)],
            'spec_labels' => ['nullable', 'array', 'max:30'],
            'spec_labels.*' => ['nullable', 'string', 'max:100'],
            'spec_values' => ['nullable', 'array', 'max:30'],
            'spec_values.*' => ['nullable', 'string', 'max:200'],
            'image' => self::IMAGE_RULES,
            'image_alt' => ['nullable', 'string', 'max:160'],
            'gallery_images' => ['nullable', 'array', 'max:2'],
            'gallery_images.*' => self::IMAGE_RULES,
            'gallery_alts' => ['nullable', 'array', 'max:2'],
            'gallery_alts.*' => ['nullable', 'string', 'max:160'],
            'gallery_order' => ['nullable', Rule::in(['reverse'])],
            'gallery_revision' => ['required_with:gallery_order', 'nullable', 'string', 'size:64'],
            'currency' => ['sometimes', Rule::in(['TOMAN'])],
            'online_purchasing_enabled' => ['sometimes', 'boolean'],
            'sale_paused' => ['sometimes', 'boolean'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => $colorRules,
            'variant_family' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'color_swatch' => ['nullable', 'string', 'regex:/\A#[a-fA-F0-9]{6}\z/'],
            'warranty_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'content' => ['sometimes', 'array:'.implode(',', array_keys(Product::DETAIL_SECTIONS))],
            'content.*' => ['nullable', 'string', 'max:3000'],
            'groups' => ['sometimes', 'array:'.implode(',', array_keys(Product::SPEC_GROUPS))],
            'groups.*' => ['nullable', 'string', 'max:2000'],
            'technical_source' => ['nullable', 'url:https', 'max:500'],
            'key_features' => ['nullable', 'string', 'max:500'],
            'verification_notes' => ['nullable', 'string', 'max:2500'],
            'seo_title' => ['nullable', 'string', 'max:100'],
            'seo_description' => ['nullable', 'string', 'max:200'],
            'related_slugs' => ['nullable', 'string', 'max:500', 'regex:/\A[a-z0-9,\s-]+\z/'],
        ], [
            'slug.regex' => 'اسلاگ باید فقط شامل حروف کوچک انگلیسی، اعداد و خط تیره باشد.',
            'slug.unique' => 'این اسلاگ قبلاً برای محصول دیگری استفاده شده است.',
            'color.unique' => 'این رنگ قبلاً در این گروه محصول ثبت شده است.',
            'color.required' => 'برای گزینهٔ عضو یک گروه، نام رنگ را وارد کنید.',
            'variant_family.regex' => 'شناسهٔ گروه باید از حروف کوچک انگلیسی، اعداد و خط تیره تشکیل شود.',
            'color_swatch.regex' => 'کد رنگ باید به صورت #RRGGBB باشد.',
            'image.mimes' => 'فرمت تصویر باید یکی از JPEG، PNG یا WebP باشد.',
            'image.max' => 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.',
        ]);
    }

    private function normalizeNumbers(Request $request): void
    {
        foreach (['price', 'warranty_months'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && trim($value) !== '') {
                $request->merge([$field => FaNumber::normalizeDigits(trim($value))]);
            }
        }
    }

    private function productData(Request $request, array $validated): array
    {
        $data = [
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
        $data += array_intersect_key($validated, array_flip([
            'currency', 'online_purchasing_enabled', 'brand', 'model', 'color', 'warranty_months',
            'variant_family', 'color_swatch',
        ]));
        $details = array_intersect_key($validated, array_flip([
            'content', 'groups', 'technical_source', 'key_features', 'verification_notes',
            'seo_title', 'seo_description', 'related_slugs', 'sale_paused',
        ]));
        if ($details !== []) {
            $data['product_details'] = $details;
        }

        return $data;
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

    private function storeMedia(Request $request, array $validated): array
    {
        $primary = $request->file('image');
        $gallery = array_filter($request->file('gallery_images', []));
        $hashes = array_map(fn (UploadedFile $file): string => hash_file('sha256', $file->getPathname()),
            array_filter([$primary, ...array_values($gallery)]));
        if (count(array_unique($hashes)) !== count($hashes)) {
            throw ValidationException::withMessages(['gallery_images' => 'هر تصویر را فقط یک‌بار انتخاب کنید.']);
        }

        $uploads = ['primary' => null, 'gallery' => []];
        try {
            if ($primary !== null) {
                $uploads['primary'] = $this->storeImage($primary, $validated['image_alt'] ?? null);
            }
            foreach ($gallery as $index => $file) {
                $uploads['gallery'][] = $this->storeImage($file, $validated['gallery_alts'][$index] ?? null);
            }
        } catch (Throwable $exception) {
            $this->deleteUploads($uploads);
            throw $exception;
        }

        return $uploads;
    }

    private function storeImage(UploadedFile $file, ?string $alt): array
    {
        [$width, $height] = getimagesize($file->getPathname());
        $path = $file->store(self::IMAGE_DIRECTORY, 'public');
        abort_unless(is_string($path) && $path !== '', 500, 'ذخیرهٔ تصویر در سرور انجام نشد.');

        return compact('path', 'alt', 'width', 'height');
    }

    private function applyMedia(Product $product, array $uploads, array $validated): void
    {
        $details = $product->media_details ?? [];
        if ($uploads['primary'] !== null) {
            ['path' => $product->image_path] = $uploads['primary'];
            $details['primary'] = array_diff_key($uploads['primary'], ['path' => true]);
        }
        if ($product->image_path !== null && array_key_exists('image_alt', $validated)) {
            $details['primary']['alt'] = $validated['image_alt'];
        }
        if ($uploads['gallery'] !== []) {
            if (! empty($validated['gallery_order'])) {
                throw ValidationException::withMessages(['gallery_order' => 'برای جابه‌جایی تصاویر فعلی، بارگذاری تصویر جدید را بردارید.']);
            }
            $details['gallery'] = $uploads['gallery'];
        } elseif (! empty($validated['gallery_order'])) {
            $gallery = $product->galleryImages();
            if (count($gallery) !== 2 || ! hash_equals($product->galleryRevision(), $validated['gallery_revision'])) {
                throw ValidationException::withMessages(['gallery_order' => 'تصاویر گالری تغییر کرده‌اند. صفحه را تازه کنید و دوباره ترتیب را انتخاب کنید.']);
            }
            $details['gallery'] = array_reverse($gallery);
        }
        $product->media_details = $details === [] ? null : $details;
    }

    private function deleteUploads(array $uploads): void
    {
        foreach ([$uploads['primary'], ...$uploads['gallery']] as $image) {
            $this->deleteImage($image['path'] ?? null);
        }
    }

    private function mediaPaths(Product $product): array
    {
        return array_unique(array_filter([$product->image_path, ...array_column($product->galleryImages(), 'path')]));
    }

    private function deleteImage(?string $path): void
    {
        if ($path === null || ! preg_match('~\Aproducts/[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:jpg|jpeg|png|webp)\z~i', $path)) {
            return;
        }

        // Best-effort cleanup: a failed delete must not turn an already
        // successful store/update/destroy into a 500 for the admin. Any
        // leftover file is reported and can be removed manually.
        try {
            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
