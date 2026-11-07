@props(['name', 'label', 'amount' => 0])
@php($exact = $amount % 1000 !== 0)
<div>
    <label class="label" for="money-{{ $name }}">{{ $label }}</label>
    <div class="admin-money">
        <div class="admin-money-number" dir="ltr">
            <input id="money-{{ $name }}" name="{{ $name }}" type="text" inputmode="numeric" autocomplete="off" required maxlength="15" value="{{ \App\Support\FaNumber::digits(number_format($exact ? $amount : intdiv($amount, 1000))) }}" data-money-input aria-label="{{ $label }}{{ $exact ? ' به تومان' : ' به هزار تومان' }}">
            @unless($exact)<span class="admin-money-zeroes" aria-hidden="true">٬۰۰۰</span>@endunless
        </div>
        <span class="admin-money-unit" aria-hidden="true">تومان</span>
    </div>
    <input type="hidden" name="money_scale" value="{{ $exact ? 1 : 1000 }}">
</div>
