@props([
    'items',
    'products',
    'quantity',
    'unavailable',
    'available',
    'total',
    'separate',
    'proof',
    'revision',
    'excluded',
    'legacyStatus',
    'overLimit',
    'drawer' => false,
])
<section @class(['store-cart', 'cart-sheet-content' => $drawer]) data-storefront-cart data-cart-count="{{ array_sum($quantity) }}" data-cart-empty="{{ $items === [] && $excluded === [] && $legacyStatus === null ? 'true' : 'false' }}">
    @unless ($drawer)
        <h1>سبد خرید</h1>
        <x-admin.errors />
    @endunless
    <div @class(['commerce-columns', 'commerce-columns--single' => $items === []])>
        <div class="commerce-cart-lines">
            @if ($legacyStatus === 'claimable')
                <form method="post" action="{{ route('cart.legacy.claim') }}" class="commerce-notice" data-cart-mutation>
                    @csrf
                    <x-storefront.cart-context :proof="$proof" />
                    <p>انتخاب‌های سبد قبلی به همین دستگاه منتقل می‌شوند. اگر کالایی در هر دو سبد باشد، تعداد بیشتر حفظ می‌شود.</p>
                    <button type="submit" class="commerce-action commerce-action--secondary">انتقال سبد قبلی به این دستگاه</button>
                </form>
            @elseif ($legacyStatus === 'held')
                <p class="commerce-notice" role="status">انتخاب‌های سبد قبلی محفوظ‌اند. انتقال آن‌ها به همان نشست مرورگر نیاز دارد.</p>
            @endif
            <div class="store-cart-items">
                @forelse ($items as $slug => $item)
                    @php($product = $products->get($slug))
                    @php($unavailableItem = in_array($slug, $unavailable, true))
                    <article @class(['store-cart-item', 'store-cart-unavailable' => !$product]) data-cart-item>
                        @if ($product)
                            <a class="store-cart-image" href="{{ route('products.show', $product) }}" aria-label="{{ $product->name }}">
                                <x-product-image :product="$product" />
                            </a>
                        @endif
                        <div class="commerce-cart-item__details">
                            <h2>
                                @if ($product)<a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                                @else کالای خارج از دسترس @endif
                            </h2>
                            @if ($product?->color)<p>رنگ {{ $product->color }}</p>@endif
                            @if ($unavailableItem)
                                <p class="commerce-stock-note commerce-stock-note--notice">قیمت یا موجودی تغییر کرده است. پیش از ثبت سفارش این کالا را بررسی کنید.</p>
                            @else
                                <p>قیمت واحد <x-storefront.money :amount="$product->price" /></p>
                            @endif
                            @if ($separate)
                                <p>این دستگاه {{ \App\Support\FaNumber::digits($item['device']['quantity'] ?? 0) }} عدد، این حساب {{ \App\Support\FaNumber::digits($item['account']['quantity'] ?? 0) }} عدد</p>
                            @endif
                            <div class="store-cart-commands">
                                @if ($product && $available[$slug] > 0)
                                    <x-storefront.cart-quantity :$product :quantity="$item['quantity']" :limit="$available[$slug]" :$proof :$revision />
                                @else
                                    <span class="commerce-stock-note">{{ \App\Support\FaNumber::digits($item['quantity']) }} عدد</span>
                                @endif
                                <form method="post" action="{{ route('cart.remove', $slug) }}" data-cart-mutation>
                                    @csrf
                                    <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                                    <button type="submit" class="commerce-action commerce-action--danger commerce-action--icon" name="scope"
                                        value="{{ $separate ? 'hide' : 'device' }}" data-cart-focus="{{ $slug }}-remove"
                                        aria-label="{{ $separate ? 'حذف از نمای فعلی' : 'حذف از سبد' }}{{ $product ? '، '.$product->name : '' }}">
                                        <x-storefront.icon name="trash" />
                                    </button>
                                </form>
                            </div>
                            @if ($product && $available[$slug] > 0 && $item['quantity'] >= $available[$slug])
                                <p class="commerce-stock-note">
                                    {{ $separate ? 'حداکثر قابل انتخاب در این نما' : 'حداکثر قابل سفارش' }}
                                    <bdi>{{ \App\Support\FaNumber::digits($available[$slug]) }}</bdi> عدد
                                </p>
                            @endif
                            @if ($separate)
                                <details class="commerce-sources">
                                    <summary>مدیریت سبدهای جداگانه</summary>
                                    <form method="post" action="{{ route('cart.remove', $slug) }}" data-cart-mutation>
                                        @csrf
                                        <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                                        @if ($item['device'])
                                            <button type="submit" class="commerce-action commerce-action--compact commerce-action--danger" name="scope" value="device">حذف از سبد این دستگاه</button>
                                        @endif
                                        @if ($item['account'])
                                            <button type="submit" class="commerce-action commerce-action--compact commerce-action--danger" name="scope" value="account">حذف از سبد حساب</button>
                                        @endif
                                    </form>
                                </details>
                            @endif
                        </div>
                        @unless($unavailableItem)
                            <p class="commerce-cart-item__total">جمع ردیف <x-storefront.money :amount="$product->price * $item['quantity']" /></p>
                        @endunless
                    </article>
                @empty
                    <div class="commerce-empty">
                        <span class="empty-cart-icon"><x-storefront.icon name="cart" /></span>
                        <h2>سبد خرید خالی است.</h2>
                        <a class="commerce-action commerce-action--secondary" href="{{ route('products.index') }}">مشاهدهٔ محصولات</a>
                    </div>
                @endforelse
                @foreach ($excluded as $slug)
                    <form method="post" action="{{ route('cart.remove', $slug) }}" class="commerce-notice" data-cart-mutation>
                        @csrf
                        <x-storefront.cart-context :proof="$proof" :revision="$revision" />
                        <p>یک کالا از نمای فعلی پنهان است.</p>
                        <button type="submit" name="scope" value="restore" class="commerce-action commerce-action--secondary">بازگرداندن کالا</button>
                    </form>
                @endforeach
            </div>
        </div>
        @if ($items !== [])
            <aside class="store-cart-total commerce-summary" data-store-cart-total>
                <h2>خلاصهٔ سبد خرید</h2>
                @if ($overLimit)
                    <p class="commerce-notice">برای ثبت سفارش، حداکثر ۳۰ کالا در نمای فعلی نگه دارید.</p>
                @elseif ($unavailable === [])
                    <dl class="commerce-summary__amounts">
                        <div><dt>جمع کالاها</dt><dd><x-storefront.money :amount="$total" /></dd></div>
                    </dl>
                    <p class="commerce-summary__note">هزینهٔ تحویل جداگانه و بر اساس روش انتخابی مشخص می‌شود.</p>
                @else
                    <p class="commerce-notice">برای تکمیل سفارش، کالاهای خارج از دسترس را بررسی کنید.</p>
                @endif
                <div class="commerce-actions">
                    @if (!$overLimit && $unavailable === [])
                        <a class="commerce-action commerce-action--primary commerce-action--prominent" href="{{ route('cart.review') }}">تکمیل سفارش</a>
                    @endif
                    <a class="commerce-action commerce-action--secondary commerce-drawer-link" href="{{ route('cart.index') }}">مشاهدهٔ سبد</a>
                </div>
            </aside>
        @endif
    </div>
</section>
