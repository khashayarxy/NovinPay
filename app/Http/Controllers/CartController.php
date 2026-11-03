<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StoreCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $cart = $request->session()->get('store_cart', []);

        return view('account.cart', ['cart' => $cart, 'products' => Product::query()->published()->whereIn('slug', array_keys($cart))->get()]);
    }

    public function add(Request $request, Product $product, StoreCheckout $checkout)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:10']]);
        abort_unless($checkout->offer($product), 409, 'این کالا هنوز برای خرید آنلاین آماده نیست.');
        $cart = $request->session()->get('store_cart', []);
        abort_if(! isset($cart[$product->slug]) && count($cart) >= 30, 422);
        $cart[$product->slug] = min(10, ($cart[$product->slug] ?? 0) + (int) $data['quantity']);
        $request->session()->put('store_cart', $cart);

        return redirect()->route('cart.index');
    }

    public function remove(Request $request, string $slug)
    {
        $cart = $request->session()->get('store_cart', []);
        unset($cart[$slug]);
        $request->session()->put('store_cart', $cart);

        return back();
    }

    public function checkout(Request $request, StoreCheckout $checkout)
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $checkout->create(Auth::guard('customer')->user(), $request->session()->get('store_cart', []), $data['operation_key']);
        $request->session()->forget('store_cart');

        return redirect()->route('account.index')->with('success', 'سفارش ثبت شد؛ پس از تأیید موجودی، سند و روش تحویل در حساب شما نمایش داده می‌شود.');
    }
}
