@props(['name', 'label', 'amount' => null, 'disabled' => false])
<div>
    <label class="label" for="money-{{ $name }}">{{ $label }}</label>
    <div class="admin-money">
        <input id="money-{{ $name }}" name="{{ $name }}" class="input" dir="ltr" type="text" inputmode="numeric" autocomplete="off" maxlength="15"
            value="{{ $amount === null ? '' : \App\Support\FaNumber::digits(number_format($amount, 0, '.', '٬')) }}"
            data-money-input aria-describedby="money-unit-{{ $name }}" @disabled($disabled)>
        <span class="admin-money-unit" id="money-unit-{{ $name }}">تومان</span>
    </div>
</div>
