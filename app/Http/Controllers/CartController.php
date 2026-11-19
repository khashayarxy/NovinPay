<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Models\Product;
use App\Services\CustomerLogin;
use App\Services\DeliveryManagement;
use App\Services\LegacyCartHold;
use App\Services\ShoppingCart;
use App\Services\StoreCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    public function index(Request $request, ShoppingCart $cart)
    {
        return view('account.cart', $this->details($request, $cart));
    }

    public function review(Request $request, ShoppingCart $cart, CustomerLogin $login)
    {
        $account = $login->authenticatedAccount($request);
        $data = $this->details($request, $cart);
        if ($data['products']->isEmpty() || $data['unavailable'] !== [] || $data['overLimit']) {
            return redirect()->route('cart.index');
        }

        return view('account.checkout', [
            ...$data,
            'account' => $account,
            'addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->latest('id')->get(),
            'operation' => (string) Str::uuid(),
        ]);
    }

    public function add(Request $request, Product $product, ShoppingCart $cart)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:10']]);

        return $this->changed($request, $cart->change($request, $product->slug, 'add', (int) $data['quantity'], $product));
    }

    public function update(Request $request, Product $product, ShoppingCart $cart)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:10']]);

        return $this->changed($request, $cart->change($request, $product->slug, 'quantity', (int) $data['quantity'], $product));
    }

    public function remove(Request $request, string $slug, ShoppingCart $cart)
    {
        $data = $request->validate(['scope' => ['required', Rule::in(['hide', 'account', 'device', 'restore'])]]);

        return $this->changed($request, $cart->change($request, $slug, $data['scope']));
    }

    public function claimLegacy(Request $request, ShoppingCart $cart, LegacyCartHold $legacy)
    {
        return $this->changed($request, $cart->importLegacy($request, $legacy));
    }

    public function checkout(Request $request, ShoppingCart $cart)
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'quote' => ['required', 'string', 'size:64'],
            'method' => ['required', Rule::in(array_keys(DeliveryManagement::CHECKOUT_METHODS))],
            'address' => ['nullable', 'required_unless:method,pickup', 'uuid'],
        ]);

        $cart->place($request, $data['operation_key'], $data['quote'], $data['method'], $data['address'] ?? null);

        return redirect()->route('account.index', ['section' => 'orders'])->with('success', 'درخواست سفارش ثبت شد. پس از تأیید موجودی، صورت‌حساب و روش تحویل در حساب شما نمایش داده می‌شود.');
    }

    private function details(Request $request, ShoppingCart $cart): array
    {
        $state = $cart->read($request);
        $products = Product::query()->published()->with('inventoryItems')->whereIn('slug', array_keys($state['items']))->get()->keyBy('slug');
        $checkout = app(StoreCheckout::class);
        $available = [];
        foreach ($state['items'] as $slug => $entry) {
            $product = $products->get($slug);
            $offer = $product ? $checkout->offer($product) : null;
            $matches = $offer && $offer->id === $entry['inventory']
                && (! $entry['device'] || $entry['device']['inventory'] === $offer->id)
                && (! $entry['account'] || $entry['account']['inventory'] === $offer->id);
            $selectionLimit = $state['separate'] ? max($entry['device']['quantity'] ?? 0, $entry['account']['quantity'] ?? 0) : 10;
            $available[$slug] = $matches ? min($selectionLimit, $offer->available) : 0;
        }
        $unavailable = array_keys(array_filter($available, fn ($limit, $slug) => $limit < $state['items'][$slug]['quantity'], ARRAY_FILTER_USE_BOTH));
        $total = $products->sum(fn ($product) => (int) $product->price * $state['quantity'][$product->slug]);

        return [...$state, 'products' => $products, 'unavailable' => $unavailable, 'available' => $available, 'total' => $total,
            'legacyStatus' => app(LegacyCartHold::class)->status($request), 'overLimit' => count($state['items']) > 30,
            'quote' => $checkout->quote($products),
        ];
    }

    private function changed(Request $request, int $count)
    {
        return $request->expectsJson()
            ? response()->json(['count' => $count])
            : redirect()->route('cart.index');
    }
}
