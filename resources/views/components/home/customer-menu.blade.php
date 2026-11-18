<details class="np-customer-menu" data-customer-menu>
    <summary class="np-customer-trigger" aria-expanded="false" aria-controls="mobile-customer-navigation">
        <x-storefront.icon name="account" width="20" height="20" />
        <span class="np-customer-guest-label">حساب کاربری</span>
        <x-storefront.icon name="chevron-down" class="store-account-chevron" width="12" height="12" />
    </summary>
    <nav id="mobile-customer-navigation" aria-label="حساب کاربری">
        <a href="{{ route('account.index') }}">داشبورد حساب</a>
        <a href="{{ route('account.index', ['section' => 'profile']) }}">اطلاعات حساب</a>
        <a href="{{ route('account.index', ['section' => 'orders']) }}">سفارش‌های من</a>
        <a href="{{ route('cart.index') }}">سبد خرید</a>
        <form action="{{ route('account.logout') }}" method="post">
            @csrf
            <button type="submit">خروج از حساب</button>
        </form>
    </nav>
</details>
