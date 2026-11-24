<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
use App\Models\InventoryItem;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as HostCookie;

class ShoppingCart
{
    public const DEVICE_COOKIE = '__Host-np_cart';

    public const MAX_ITEM_QUANTITY = 1000;

    public const MAX_SERIALIZED_ITEM_QUANTITY = 100;

    public function __construct(private CustomerLogin $login, private StoreCheckout $checkout) {}

    public static function maxQuantityForOffer(?InventoryItem $offer): int
    {
        if (! $offer || $offer->available <= 0) {
            return 0;
        }

        $cap = $offer->serialized ? self::MAX_SERIALIZED_ITEM_QUANTITY : self::MAX_ITEM_QUANTITY;

        return min((int) $offer->available, $cap);
    }

    private function scopes(Request $request, bool $initialize): array
    {
        $device = $request->cookie(self::DEVICE_COOKIE);
        $usable = fn ($token) => is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token);
        if ($initialize && ! $usable($device)) {
            $device = $request->session()->get('store_cart_device_bootstrap');
            if (! $usable($device)) {
                $device = bin2hex(random_bytes(32));
                $request->session()->put('store_cart_device_bootstrap', $device);
            }
            Cookie::queue(new HostCookie(self::DEVICE_COOKIE, $device, now()->addYear(), '/', null, true, true, false, 'lax'));
        } elseif ($initialize) {
            $request->session()->forget('store_cart_device_bootstrap');
        }

        $account = $this->login->authenticatedAccount($request);
        $deviceScope = $usable($device) ? hash('sha256', 'device:'.$device) : null;
        $accountScope = $account ? hash('sha256', 'account:'.$account->id) : null;
        $view = $deviceScope && $accountScope ? hash('sha256', 'combined:'.$deviceScope.':'.$accountScope) : null;

        return [
            'device' => $deviceScope,
            'account' => $accountScope,
            'view' => $view,
            'proof' => hash_hmac('sha256', json_encode([$deviceScope, $accountScope, $account?->session_version], JSON_THROW_ON_ERROR), config('app.key')),
        ];
    }

    public function count(Request $request): int
    {
        if (! $request->attributes->has('storefront-cart-count')) {
            $request->attributes->set('storefront-cart-count', array_sum($this->read($request, false)['quantity']));
        }

        return $request->attributes->get('storefront-cart-count');
    }

    public function proof(Request $request): string
    {
        return $this->scopes($request, true)['proof'];
    }

    public function read(Request $request, bool $initialize = true): array
    {
        if ($request->attributes->has('storefront-cart-committed')) {
            return $request->attributes->get('storefront-cart-committed');
        }

        $scopes = $this->scopes($request, $initialize);
        $states = $this->states($scopes, false);
        $items = $this->combined($scopes, $states);

        return [
            'items' => $items,
            'quantity' => array_column($items, 'quantity', 'slug'),
            'revision' => $this->revision($items),
            'proof' => $scopes['proof'],
            'separate' => $scopes['account'] !== null,
            'excluded' => array_keys(array_intersect_key(array_filter($states[$scopes['view']] ?? [], fn ($item) => $item === 0),
                ($states[$scopes['device']] ?? []) + ($states[$scopes['account']] ?? []))),
        ];
    }

    public function change(Request $request, string $slug, string $action, int $quantity = 0, ?Product $product = null): int
    {
        $scopes = $this->verifiedScopes($request);

        $committed = DB::transaction(function () use ($request, $scopes, $slug, $action, $quantity, $product): array {
            $this->lockOwner($request);
            $states = $this->states($scopes, true);
            $originalStates = $states;
            $items = $this->combined($scopes, $states);
            $scope = $scopes['account'] ?? $scopes['device'];
            $view = $scopes['view'];
            if ($action !== 'add') {
                $this->checkRevision($request, $items);
            }

            if ($action === 'add' || $action === 'quantity') {
                $existing = $items[$slug] ?? null;
                $owned = $states[$scope][$slug] ?? null;
                abort_if($action === 'quantity' && ! $existing, 404);
                $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                $offer = $this->checkout->offer($product, true);
                $maxQty = self::maxQuantityForOffer($offer);
                if ($action === 'add') {
                    $increment = $quantity;
                    $visibleBefore = $existing['quantity'] ?? 0;
                    $targetVisible = $visibleBefore + $increment;
                    abort_unless($offer && $targetVisible <= $maxQty, 409, 'موجودی برای این تعداد کافی نیست.');
                    $targetOwned = ($owned['quantity'] ?? 0) + $increment;
                    if ($view && array_key_exists($slug, $states[$view])) {
                        $quantity = min($targetOwned, $maxQty);
                        $states[$view][$slug] = $targetVisible;
                    } else {
                        $quantity = max($targetVisible, $targetOwned);
                        abort_unless($quantity <= $maxQty, 409, 'موجودی برای این تعداد کافی نیست.');
                    }
                } else {
                    abort_unless($offer && $quantity <= $maxQty, 409, 'موجودی برای این تعداد کافی نیست.');
                }
                if ($action === 'add' || $quantity !== $existing['quantity'] || ($owned && $owned['inventory'] !== $offer->id)) {
                    abort_if(! $owned && count($states[$scope]) >= 30, 422);
                    $states[$scope][$slug] = ['inventory' => $offer->id, 'quantity' => $quantity, 'version' => (string) Str::uuid()];
                    if ($view && $action === 'quantity') {
                        $states[$view][$slug] = $quantity;
                    }
                }
            } elseif ($action === 'hide') {
                abort_unless($view && isset($items[$slug]), 404);
                $states[$view][$slug] = 0;
            } elseif ($action === 'restore') {
                abort_unless($view && ($states[$view][$slug] ?? null) === 0, 404);
                unset($states[$view][$slug]);
            } else {
                $scope = $scopes[$action];
                abort_unless($scope && isset($states[$scope][$slug]), 404);
                unset($states[$scope][$slug]);
                if ($view && ! isset($states[$scopes['device']][$slug]) && ! isset($states[$scopes['account']][$slug])) {
                    unset($states[$view][$slug]);
                }
            }

            $next = $this->combined($scopes, $states);
            foreach ($states as $key => $state) {
                if (! array_key_exists($key, $originalStates) || $originalStates[$key] !== $state) {
                    $this->save($key, $state);
                }
            }

            $quantities = array_column($next, 'quantity', 'slug');

            return [
                'count' => array_sum($quantities),
                'state' => [
                    'items' => $next,
                    'quantity' => $quantities,
                    'revision' => $this->revision($next),
                    'proof' => $scopes['proof'],
                    'separate' => $scopes['account'] !== null,
                    'excluded' => array_keys(array_intersect_key(array_filter($states[$scopes['view']] ?? [], fn ($item) => $item === 0),
                        ($states[$scopes['device']] ?? []) + ($states[$scopes['account']] ?? []))),
                ],
            ];
        }, 3);

        $request->attributes->set('storefront-cart-committed', $committed['state']);
        $request->attributes->set('storefront-cart-count', $committed['count']);

        return $committed['count'];
    }

    public function importLegacy(Request $request, LegacyCartHold $legacy): int
    {
        $scopes = $this->verifiedScopes($request);

        $count = DB::transaction(function () use ($request, $legacy, $scopes): int {
            $device = $scopes['device'];
            $this->lockOwner($request);
            $states = $this->states($scopes, true);
            $legacy->claim($request, $device, function (array $selections) use ($device, &$states): void {
                $products = Product::query()->published()->with('inventoryItems')->whereIn('slug', array_keys($selections))->get()->keyBy('slug');
                foreach ($selections as $slug => $quantity) {
                    $item = $states[$device][$slug] ?? null;
                    if ($item && $item['quantity'] >= $quantity) {
                        continue;
                    }
                    $product = $products->get($slug);
                    $stock = $product ? $this->checkout->confirmedInventory($product) : null;
                    $states[$device][$slug] = ['quantity' => $quantity,
                        'inventory' => $item['inventory'] ?? $stock?->id, 'version' => (string) Str::uuid()];
                }
            });
            foreach ($states as $key => $state) {
                $this->save($key, $state);
            }

            return array_sum(array_column($this->combined($scopes, $states), 'quantity'));
        }, 3);

        $request->attributes->remove('storefront-cart-committed');
        $request->attributes->remove('storefront-cart-count');

        return $count;
    }

    public function place(Request $request, string $key, string $quote, string $method, ?string $address): SalesOrder
    {
        $scopes = $this->verifiedScopes($request);
        $account = $this->login->authenticatedAccount($request);
        abort_unless($account, 403);

        return DB::transaction(function () use ($request, $key, $quote, $method, $address, $scopes, $account): SalesOrder {
            $this->lockOwner($request);
            $states = $this->states($scopes, true);
            $items = $this->combined($scopes, $states);
            $this->checkRevision($request, $items);
            abort_if(count($items) > 30, 422, 'هر سفارش می‌تواند حداکثر ۳۰ کالا داشته باشد.');
            $order = $this->checkout->create($account, array_column($items, 'quantity', 'slug'), $key, $quote);
            foreach (array_filter($states, fn ($state) => $state === []) as $scope => $state) {
                $this->save($scope, $state);
            }
            $delivery = [
                'method' => $method,
                'address_id' => $method === 'pickup' ? null : $address,
            ];
            if (! $order->wasRecentlyCreated) {
                $previous = DB::table('cart_purchase_snapshots')->where('sales_order_id', $order->id)->value('delivery');
                abort_if($previous !== null && array_intersect_key(decrypt($previous), $delivery) !== $delivery, 409);

                return $order;
            }

            $delivery['address'] = app(DeliveryManagement::class)->checkoutAddress($account, $delivery['address_id'], $method);
            $lines = $order->lines()->get()->keyBy('inventory_item_id');
            $snapshot = [];
            foreach ($items as $slug => $entry) {
                $line = $lines->get($entry['inventory']);
                abort_unless($line && $line->quantity === $entry['quantity'], 409, 'موجودی کالا تغییر کرده است؛ سبد را بررسی کنید.');
                foreach ([$scopes['device'], $scopes['account']] as $scope) {
                    $source = $states[$scope][$slug] ?? null;
                    abort_if($source && $source['inventory'] !== $entry['inventory'], 409, 'شناسهٔ کالا تغییر کرده است؛ کالا را دوباره به سبد اضافه کنید.');
                    if ($source) {
                        $snapshot[$scope][$slug] = ['line' => $line->id, 'inventory' => $entry['inventory'], 'ordered' => $line->quantity,
                            'quantity' => min($line->quantity, $source['quantity']), 'version' => $source['version']];
                    }
                }
            }
            DB::table('cart_purchase_snapshots')->insert(['sales_order_id' => $order->id,
                'sources' => $this->encode(['rows' => $snapshot, 'view' => $scopes['view'],
                    'preferences' => array_intersect_key($states[$scopes['view']] ?? [], $items)]),
                'delivery' => encrypt($delivery)]);

            return $order;
        }, 3);
    }

    /** Called inside the authoritative settlement transaction, with the document locked. */
    public function consume(FinancialDocument $document): void
    {
        if (! $document->sales_order_id || $document->charged_minor <= 0 || $document->paid_minor !== $document->charged_minor) {
            return;
        }

        $snapshot = DB::table('cart_purchase_snapshots')->where('sales_order_id', $document->sales_order_id)->lockForUpdate()->first();
        if (! $snapshot || $snapshot->consumed_at) {
            return;
        }
        $order = SalesOrder::query()->find($document->sales_order_id);
        if (! $order || ! in_array($order->status, ['confirmed', 'fulfilled'], true)) {
            return;
        }
        $allocated = PaymentTransaction::query()->where('financial_document_id', $document->id)->sum('allocated_minor');
        if ((int) $allocated < $document->charged_minor) {
            return;
        }

        $source = json_decode($snapshot->sources, true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'];
        $states = $this->states([...array_keys($rows), $source['view']], true);
        $lines = $order->lines()->get()->keyBy('id');
        foreach ($rows as $scope => $purchased) {
            foreach ($purchased as $slug => $entry) {
                $line = $lines->get($entry['line']);
                $current = $states[$scope][$slug] ?? null;
                if (($states[$source['view']][$slug] ?? null) !== ($source['preferences'][$slug] ?? null)) {
                    continue;
                }
                if (! $current || $current['version'] !== $entry['version'] || ! $line ||
                    (int) $line->inventory_item_id !== $entry['inventory'] || $line->quantity !== $entry['ordered']) {
                    continue;
                }
                $left = $current['quantity'] - $entry['quantity'];
                if ($left > 0) {
                    $states[$scope][$slug] = [...$current, 'quantity' => $left, 'version' => (string) Str::uuid()];
                } else {
                    unset($states[$scope][$slug]);
                }
            }
        }
        foreach ($states as $key => $state) {
            $this->save($key, $state);
        }
        DB::table('cart_purchase_snapshots')->where('sales_order_id', $order->id)->update(['consumed_at' => now()]);
    }

    private function lockOwner(Request $request): void
    {
        $account = $this->login->authenticatedAccount($request);
        if ($account) {
            $fresh = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($fresh->is_active && $fresh->session_version === $account->session_version, 403);
        }
    }

    private function verifiedScopes(Request $request): array
    {
        $scopes = $this->scopes($request, true);
        $proof = $request->input('cart_context');
        abort_unless(is_string($proof) && hash_equals($scopes['proof'], $proof), 409, 'نشست خرید تغییر کرده است؛ صفحه را تازه کنید.');

        return $scopes;
    }

    private function checkRevision(Request $request, array $items): void
    {
        $revision = $request->input('cart_revision');
        abort_unless(is_string($revision) && hash_equals($this->revision($items), $revision), 409, 'سبد در جای دیگری تغییر کرده است؛ آن را دوباره بررسی کنید.');
    }

    private function revision(array $items): string
    {
        return hash_hmac('sha256', $this->encode($this->canonicalize($items)), config('app.key'));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function states(array $scopes, bool $lock): array
    {
        $keys = array_values(array_unique(array_filter(array_diff_key($scopes, ['proof' => true]))));
        if ($keys === []) {
            return [];
        }
        sort($keys, SORT_STRING);
        $query = DB::table('shopping_carts')->whereIn('scope', $keys)->orderBy('scope');
        $rows = ($lock ? $query->lockForUpdate() : $query)->pluck('state', 'scope');

        if ($lock && $rows->count() < count($keys)) {
            $missing = array_values(array_diff($keys, $rows->keys()->all()));
            foreach ($missing as $key) {
                DB::table('shopping_carts')->insertOrIgnore(['scope' => $key, 'state' => '{}']);
            }
            $rows = DB::table('shopping_carts')->whereIn('scope', $keys)->orderBy('scope')->lockForUpdate()->pluck('state', 'scope');
        }

        return $rows->map(fn ($state) => json_decode($state, true, 512, JSON_THROW_ON_ERROR))->all();
    }

    private function combined(array $scopes, array $states): array
    {
        $device = $states[$scopes['device']] ?? [];
        $account = $states[$scopes['account']] ?? [];
        $preferences = $states[$scopes['view']] ?? [];
        $items = [];
        foreach ($device + $account as $slug => $entry) {
            if (($preferences[$slug] ?? null) === 0) {
                continue;
            }
            $fromDevice = $device[$slug] ?? null;
            $fromAccount = $account[$slug] ?? null;
            $quantity = max($fromDevice['quantity'] ?? 0, $fromAccount['quantity'] ?? 0);
            $entry['quantity'] = min($quantity, $preferences[$slug] ?? $quantity);
            $items[$slug] = ['slug' => $slug, ...$entry, 'device' => $fromDevice, 'account' => $fromAccount];
        }
        ksort($items);

        return $items;
    }

    private function save(string $scope, array $state): void
    {
        $cart = DB::table('shopping_carts')->where('scope', $scope);
        if ($state === []) {
            $cart->delete();
        } else {
            $cart->update(['state' => $this->encode($state)]);
        }
    }

    public function stateData(Request $request): array
    {
        $state = $this->read($request);
        $products = $state['items'] === []
            ? collect()
            : Product::query()->published()->with('inventoryItems')->whereIn('slug', array_keys($state['items']))->get()->keyBy('slug');
        $available = [];
        $stockAvailable = [];
        foreach ($state['items'] as $slug => $entry) {
            $product = $products->get($slug);
            $offer = $product ? $this->checkout->offer($product) : null;
            $available[$slug] = self::maxQuantityForOffer($offer);
            $stockAvailable[$slug] = $offer ? max(0, (int) $offer->available) : 0;
        }
        $unavailable = array_keys(array_filter($available, fn ($limit, $slug) => $limit < $state['items'][$slug]['quantity'], ARRAY_FILTER_USE_BOTH));
        $prices = $products->mapWithKeys(fn (Product $product) => [$product->slug => (int) $product->price])->all();
        $total = $products->sum(fn ($product) => (int) $product->price * $state['quantity'][$product->slug]);

        return [
            ...$state,
            'products' => $products,
            'unavailable' => $unavailable,
            'available' => $available,
            'stockAvailable' => $stockAvailable,
            'prices' => $prices,
            'total' => $total,
            'overLimit' => count($state['items']) > 30,
        ];
    }

    public function viewData(Request $request): array
    {
        $data = $this->stateData($request);

        return [
            ...$data,
            'legacyStatus' => app(LegacyCartHold::class)->status($request),
            'quote' => $this->checkout->quote($data['products']),
        ];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
