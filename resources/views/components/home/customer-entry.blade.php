@props(['account' => null])

<div data-customer-entry data-customer-state="{{ $account ? 'authenticated' : 'guest' }}">
    @if ($account)
        <x-home.customer-menu />
    @else
        <a href="{{ route('account.login') }}" class="np-customer-trigger" data-customer-guest><x-home.icon name="user" />ورود / ثبت‌نام</a>
    @endif
</div>
