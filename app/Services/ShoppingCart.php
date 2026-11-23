<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\FinancialDocument;
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

    public function __construct(private CustomerLogin $login, private StoreCheckout $checkout) {}

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

        return DB::transaction(function () use ($request, $scopes, $slug, $action, $quantity, $product): int {
            $this->lockOwner($request);
            $states = $this->states($scopes, true);
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
                if ($action === 'add') {
                    $increment = $quantity;
                    $quantity += $owned['quantity'] ?? 0;
                }
                abort_unless($offer && $quantity <= min(10, $offer->available), 409, 'موجودی برای این تعداد کافی نیست.');
                if ($action === 'add' || $quantity !== $existing['quantity'] || ($owned && $owned['inventory'] !== $offer->id)) {
                    abort_if(! $owned && count($states[$scope]) >= 30, 422);
                    $states[$scope][$slug] = ['inventory' => $offer->id, 'quantity' => $quantity, 'version' => (string) Str::uuid()];
                    if ($view) {
                        if ($action === 'quantity') {
                            $states[$view][$slug] = $quantity;
                        } elseif (array_key_exists($slug, $states[$view])) {
                            $states[$view][$slug] += $increment;
                        }
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
                $this->save($key, $state);
            }

            return array_sum(array_column($next, 'quantity'));
        }, 3);
    }

    public function importLegacy(Request $request, LegacyCartHold $legacy): int
    {
        $scopes = $this->verifiedScopes($request);

        return DB::transaction(function () use ($request, $legacy, $scopes): int {
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
        return hash_hmac('sha256', $this->encode($items), config('app.key'));
    }

    private function states(array $scopes, bool $lock): array
    {
        $keys = array_values(array_unique(array_filter(array_diff_key($scopes, ['proof' => true]))));
        if ($keys === []) {
            return [];
        }
        sort($keys, SORT_STRING);
        if ($lock) {
            foreach ($keys as $key) {
                DB::table('shopping_carts')->insertOrIgnore(['scope' => $key, 'state' => '{}']);
            }
        }
        $query = DB::table('shopping_carts')->whereIn('scope', $keys)->orderBy('scope');

        return ($lock ? $query->lockForUpdate() : $query)->pluck('state', 'scope')
            ->map(fn ($state) => json_decode($state, true, 512, JSON_THROW_ON_ERROR))->all();
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

    public function viewData(Request $request): array
    {
        $state = $this->read($request);
        $products = $state['items'] === []
            ? collect()
            : Product::query()->published()->with('inventoryItems')->whereIn('slug', array_keys($state['items']))->get()->keyBy('slug');
        $available = [];
        foreach ($state['items'] as $slug => $entry) {
            $product = $products->get($slug);
            $offer = $product ? $this->checkout->offer($product) : null;
            $available[$slug] = $offer ? min(10, $offer->available) : 0;
        }
        $unavailable = array_keys(array_filter($available, fn ($limit, $slug) => $limit < $state['items'][$slug]['quantity'], ARRAY_FILTER_USE_BOTH));
        $total = $products->sum(fn ($product) => (int) $product->price * $state['quantity'][$product->slug]);

        return [...$state, 'products' => $products, 'unavailable' => $unavailable, 'available' => $available, 'total' => $total,
            'legacyStatus' => app(LegacyCartHold::class)->status($request), 'overLimit' => count($state['items']) > 30,
            'quote' => $this->checkout->quote($products),
        ];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
