@props(['address' => null])
<form method="post" class="np-account-form" action="{{ $address ? route('account.address.update', $address->public_id) : route('account.address') }}">
    @csrf
    @foreach(['recipient' => ['نام تحویل‌گیرنده', 'name'], 'phone' => ['شماره موبایل', 'tel'], 'province' => ['استان', 'address-level1'], 'city' => ['شهر', 'address-level2'], 'address' => ['آدرس کامل', 'street-address'], 'postal_code' => ['کد پستی ده‌رقمی', 'postal-code']] as $field => [$label, $autocomplete])
        <label for="{{ $address?->public_id ?? 'new-address' }}-{{ $field }}">{{ $label }}</label>
        @if($field === 'address')
            <textarea id="{{ $address?->public_id ?? 'new-address' }}-{{ $field }}" name="{{ $field }}" autocomplete="{{ $autocomplete }}" rows="3" maxlength="1000" required>{{ $address?->$field }}</textarea>
        @else
            <input id="{{ $address?->public_id ?? 'new-address' }}-{{ $field }}" name="{{ $field }}" autocomplete="{{ $autocomplete }}" value="{{ $field === 'phone' ? \App\Support\FaNumber::digits($address?->phone ?? '') : $address?->$field }}" maxlength="{{ match($field) { 'postal_code' => 10, 'phone' => 40, default => 100 } }}" @if($field === 'phone') type="tel" dir="ltr" inputmode="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" @elseif($field === 'postal_code') dir="ltr" inputmode="numeric" @endif required>
        @endif
    @endforeach
    <button class="np-account-primary">ذخیره آدرس</button>
</form>
