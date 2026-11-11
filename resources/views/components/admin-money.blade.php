@props(['name', 'label', 'amount' => null, 'disabled' => false])
<div>
    <label class="label" for="money-{{ $name }}">{{ $label }}</label>
    <div class="admin-money">
        <div class="admin-money-number" dir="ltr">
            <input id="money-{{ $name }}" name="{{ $name }}" type="text" inputmode="numeric" autocomplete="off" maxlength="15"
                value="{{ $amount === null ? '' : \App\Support\FaNumber::digits(number_format($amount, 0, '.', '٬')) }}"
                data-money-input aria-label="{{ $label }} به تومان" @disabled($disabled)>
        </div>
        <span class="admin-money-unit" aria-hidden="true">تومان</span>
    </div>
</div>
