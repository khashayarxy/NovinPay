<?php

namespace App\Http\Controllers;

use App\Enums\ProductCategory;
use App\Enums\ProductCondition;
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Services\CustomerLogin;
use App\Services\ProductPurchaseVerification;
use App\Services\ShoppingCart;
use App\Services\StoreCheckout;
use App\Support\ProductSale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    private const REVIEW_SORTS = [
        'newest' => 'تازه‌ترین',
        'highest' => 'بیشترین امتیاز',
        'lowest' => 'کمترین امتیاز',
    ];

    public function index(Request $request, StoreCheckout $checkout): View|RedirectResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::enum(ProductCategory::class)],
            'condition' => ['nullable', Rule::enum(ProductCondition::class)],
            'sort' => ['nullable', Rule::in(['price-low', 'price-high'])],
        ], [
            'q.max' => 'عبارت جست‌وجو نباید بیشتر از ۱۰۰ کاراکتر باشد.',
            'category' => 'دسته‌بندی انتخاب‌شده معتبر نیست.',
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $category = isset($validated['category'])
            ? ProductCategory::from($validated['category'])
            : null;

        $sort = $validated['sort'] ?? null;
        $condition = $validated['condition'] ?? null;
        $products = Product::query()
            ->published()
            ->with('inventoryItems')
            ->when($term !== '', fn ($query) => $query->search($term))
            ->when($category !== null, fn ($query) => $query->category($category))
            ->when($condition, fn ($query) => $query->where('condition', $condition))
            ->when($sort, fn ($query) => $query->orderByRaw('CASE WHEN price > 0 AND is_sample = 0 THEN 0 ELSE 1 END')
                ->orderBy('price', $sort === 'price-low' ? 'asc' : 'desc'))
            ->latest()->orderByDesc('id')
            ->paginate(12)
            // Append only the validated filter parameters — never the raw
            // query string — so unrelated or potentially sensitive params
            // (e.g. tracking tokens) are not propagated into page links.
            ->appends(array_filter([
                'q' => $term !== '' ? $term : null,
                'category' => $category?->value,
                'condition' => $condition,
                'sort' => $sort,
            ]));

        $stock = $products->getCollection()->mapWithKeys(fn (Product $product) => [$product->id => $checkout->confirmedInventory($product)?->available])->all();

        return view('products.index', [
            'products' => $products,
            'stock' => $stock,
            'sales' => $products->getCollection()->mapWithKeys(fn (Product $product) => [
                $product->id => ProductSale::resolve($product, isset($stock[$product->id]) ? $stock[$product->id] > 0 : null),
            ])->all(),
            'sort' => $sort,
            'condition' => $condition,
            'term' => $term,
            'category' => $category,
            'categories' => ProductCategory::storefrontCases(),
            'hasFilters' => $term !== '' || $category !== null || $condition !== null || $sort !== null,
            'canonical' => rtrim(config('app.url'), '/').route('products.index', [], false)
                .($products->currentPage() > 1 ? '?page='.$products->currentPage() : ''),
            'publicRobots' => $term !== '' || $category !== null || array_diff(array_keys($request->query()), ['page'])
                ? 'noindex, follow' : 'index, follow',
        ]);
    }

    public function show(Request $request, Product $product, StoreCheckout $checkout, CustomerLogin $login, ProductPurchaseVerification $purchase, ShoppingCart $cart): Response
    {
        abort_unless($product->is_published, 404);

        $product->loadMissing('inventoryItems');
        $inventory = $checkout->confirmedInventory($product);
        $matchingItems = $product->inventoryItems->where('condition', $product->condition->value);
        $sku = ! $product->is_sample && $matchingItems->count() === 1 ? $matchingItems->first()->sku : null;
        $variants = collect();
        if ($product->variant_family && ! $product->is_sample) {
            $variants = Product::query()->published()->where('is_sample', false)
                ->where('variant_family', $product->variant_family)
                ->where('category', $product->category->value)->whereNotNull('color')->whereKeyNot($product->id)
                ->with('inventoryItems')->orderBy('id')->get();
        }
        $related = collect();
        $feedback = collect();
        $rating = null;
        $reviewSort = $request->query('review_sort');
        $reviewSort = is_string($reviewSort) && isset(self::REVIEW_SORTS[$reviewSort]) ? $reviewSort : 'newest';
        $account = null;
        $ownReview = null;
        if (! $product->is_sample) {
            $manual = preg_split('/[,\s]+/', $product->product_details['related_slugs'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
            $relatedQuery = Product::query()->published()->where('is_sample', false)
                ->whereKeyNot($product->id)->with('inventoryItems')
                ->when($product->variant_family, fn ($query, $family) => $query
                    ->where(fn ($other) => $other->whereNull('variant_family')->orWhere('variant_family', '!=', $family)));
            $related = $manual !== [] ? (clone $relatedQuery)->whereIn('slug', $manual)->limit(4)->get() : collect();
            if ($related->isEmpty()) {
                $related = $relatedQuery->where('category', $product->category->value)
                    ->orderByRaw("CASE WHEN stock_status = 'out_of_stock' THEN 1 ELSE 0 END")
                    ->when($product->brand, fn ($query) => $query->orderByRaw('CASE WHEN brand = ? THEN 0 ELSE 1 END', [$product->brand]))
                    ->when($product->price, fn ($query) => $query->orderByRaw('CASE WHEN price IS NULL THEN 1 ELSE 0 END')
                        ->orderByRaw('ABS(price - ?)', [$product->price]))
                    ->latest()->limit(4)->get();
            }
            $public = ProductFeedback::query()->where('product_id', $product->id)->where('status', 'approved');
            $reviews = (clone $public)->where('kind', 'review');
            if ($reviewSort !== 'newest') {
                $reviews->orderByRaw('CASE WHEN verified_buyer = 1 AND rating BETWEEN 1 AND 5 THEN 0 ELSE 1 END')
                    ->orderByRaw('CASE WHEN verified_buyer = 1 AND rating BETWEEN 1 AND 5 THEN rating ELSE NULL END '
                        .($reviewSort === 'highest' ? 'DESC' : 'ASC'));
            }
            $feedback = collect([
                'review' => $reviews->latest('id')->paginate(8, ['*'], 'review_page')->fragment('product-review'),
            ]);
            if ($reviewSort !== 'newest') {
                $feedback->each(fn ($page) => $page->appends(['review_sort' => $reviewSort]));
            }
            $counts = (clone $public)->where('kind', 'review')->where('verified_buyer', true)
                ->whereBetween('rating', [1, 5])->selectRaw('rating, COUNT(*) AS total')->groupBy('rating')
                ->pluck('total', 'rating');
            $count = (int) $counts->sum();
            if ($count > 0) {
                $weighted = $counts->sum(fn ($number, $stars) => $number * $stars);
                $rating = ['count' => $count, 'value' => round($weighted / $count, 1), 'distribution' => $counts];
            }
            $account = $login->authenticatedAccount($request);
            if (! $account?->verified_at) {
                $account = null;
            }
            $ownReview = $account ? ProductFeedback::query()->where('product_id', $product->id)
                ->where('review_owner_id', $account->id)->first() : null;
        }
        $sale = ProductSale::resolve($product, $inventory ? $inventory->available > 0 : null);
        $variantSales = $this->sales($variants, $checkout) + [$product->id => $sale];
        $cartState = $cart->read($request);

        $response = response()->view('products.show', [
            'product' => $product,
            'sku' => $sku,
            'variants' => $variants->prepend($product),
            'variantSales' => $variantSales,
            'sale' => $sale,
            'inventory' => $inventory,
            'canAddToCart' => $sale === ProductSale::Online,
            'cartQuantity' => (int) ($cartState['quantity'][$product->slug] ?? 0),
            'cartProof' => $cartState['proof'],
            'cartRevision' => $cartState['revision'],
            'cartSeparate' => (bool) $cartState['separate'],
            'related' => $related,
            'relatedSales' => $this->sales($related, $checkout),
            'feedback' => $feedback,
            'productRating' => $rating,
            'reviewSort' => $reviewSort,
            'reviewSorts' => self::REVIEW_SORTS,
            'feedbackAccount' => $account,
            'canRate' => $account && $purchase->verified($account, $product),
            'ownReview' => $ownReview,
            'canonical' => rtrim($request->getHost() === 'novinpaypos.test' ? config('business.public_url') : config('app.url'), '/')
                .route('products.show', $product, false),
            'publicRobots' => $product->is_sample ? 'noindex, follow' : 'index, follow',
        ]);

        if ($account) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    public function compare(Request $request): View
    {
        $data = $request->validate(['products' => ['sometimes', 'array', 'max:4'],
            'products.*' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'distinct'],
            'add' => ['nullable', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/']]);
        $slugs = array_slice(array_unique(array_filter([...($data['products'] ?? []), $data['add'] ?? null])), 0, 4);
        $products = Product::query()->published()->where('is_sample', false)
            ->whereIn('slug', $slugs)->get()->keyBy('slug');
        $products = collect($slugs)->map(fn ($slug) => $products->get($slug))->filter()->values();
        $choices = Product::query()->published()->where('is_sample', false)
            ->whereNotIn('slug', $slugs)->orderBy('name')->get(['slug', 'name']);
        $specs = $products->mapWithKeys(function (Product $product) {
            $rows = ['برند' => $product->brand, 'مدل' => $product->model, 'رنگ' => $product->color];
            foreach (($product->product_details['groups'] ?? []) as $group) {
                foreach (Product::specificationRows($group) as [$label, $value]) {
                    $rows[$label] = $value;
                }
            }

            return [$product->slug => $rows];
        });

        return view('products.compare', ['products' => $products, 'choices' => $choices, 'specs' => $specs,
            'publicRobots' => 'noindex, follow']);
    }

    private function sales(iterable $products, StoreCheckout $checkout): array
    {
        $sales = [];
        foreach ($products as $product) {
            $inventory = $checkout->confirmedInventory($product);
            $sales[$product->id] = ProductSale::resolve($product, $inventory ? $inventory->available > 0 : null);
        }

        return $sales;
    }
}
