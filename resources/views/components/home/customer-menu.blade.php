<details class="np-customer-menu" data-customer-menu>
    <summary class="np-customer-trigger" aria-expanded="false" aria-controls="mobile-customer-navigation">
        <x-home.icon name="user" />
        حساب کاربری
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
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
