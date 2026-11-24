@props(['address' => null])
@php
    $formKey = $address?->public_id ?? 'new';
    $isThisForm = old('address_form_key') === $formKey || ($formKey === 'new' && ! old('address_form_key'));
    $fieldValue = static function (string $field, mixed $fallback = '') use ($isThisForm): string {
        if ($isThisForm) {
            $old = old($field);
            if ($old !== null) {
                return (string) $old;
            }
        }

        return (string) ($fallback ?? '');
    };
    $locations = config('iran_locations', []);
    $selectedProvince = $fieldValue('province', $address?->province);
    $selectedCity = $fieldValue('city', $address?->city);
    $citiesForProvince = $locations[$selectedProvince] ?? [];
    if ($selectedCity !== '' && ! in_array($selectedCity, $citiesForProvince, true)) {
        array_unshift($citiesForProvince, $selectedCity);
    }
    $prefix = $address?->public_id ?? 'new-address';
@endphp
<form method="post" class="np-account-form" data-iran-address-form data-prevent-double-submit novalidate action="{{ $address ? route('account.address.update', $address->public_id) : route('account.address') }}">
    @csrf
    <input type="hidden" name="address_form_key" value="{{ $formKey }}">

    <div class="np-account-form__grid">
        <div class="np-account-form__field">
            <label for="{{ $prefix }}-recipient">نام تحویل‌گیرنده</label>
            <input id="{{ $prefix }}-recipient" name="recipient" autocomplete="name" value="{{ $fieldValue('recipient', $address?->recipient) }}" maxlength="100" @class(['is-invalid' => $isThisForm && $errors->has('recipient')]) @if($isThisForm && $errors->has('recipient')) aria-invalid="true" @endif required>
            @if($isThisForm) @error('recipient')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
        </div>
        <div class="np-account-form__field">
            <label for="{{ $prefix }}-phone">شماره موبایل</label>
            <input id="{{ $prefix }}-phone" name="phone" type="tel" dir="ltr" inputmode="tel" autocomplete="tel" data-normalize-digits value="{{ $fieldValue('phone', $address?->phone ? \App\Support\FaNumber::digits($address->phone) : '') }}" maxlength="40" placeholder="۰۹۱۲۳۴۵۶۷۸۹" @class(['is-invalid' => $isThisForm && $errors->has('phone')]) @if($isThisForm && $errors->has('phone')) aria-invalid="true" @endif required>
            @if($isThisForm) @error('phone')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
        </div>
    </div>

    <div class="np-account-form__grid">
        <div class="np-account-form__field">
            <label for="{{ $prefix }}-province">استان</label>
            <select id="{{ $prefix }}-province" name="province" autocomplete="address-level1" data-iran-province @class(['is-invalid' => $isThisForm && $errors->has('province')]) @if($isThisForm && $errors->has('province')) aria-invalid="true" @endif required>
                <option value="">انتخاب استان</option>
                @foreach(array_keys($locations) as $provinceName)
                    <option value="{{ $provinceName }}" @selected($selectedProvince === $provinceName)>{{ $provinceName }}</option>
                @endforeach
                @if($selectedProvince !== '' && ! array_key_exists($selectedProvince, $locations))
                    <option value="{{ $selectedProvince }}" selected>{{ $selectedProvince }}</option>
                @endif
            </select>
            @if($isThisForm) @error('province')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
        </div>
        <div class="np-account-form__field">
            <label for="{{ $prefix }}-city">شهر</label>
            <select id="{{ $prefix }}-city" name="city" autocomplete="address-level2" data-iran-city data-initial-city="{{ $selectedCity }}" @class(['is-invalid' => $isThisForm && $errors->has('city')]) @if($isThisForm && $errors->has('city')) aria-invalid="true" @endif required>
                <option value="">{{ $selectedProvince !== '' ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید' }}</option>
                @foreach($citiesForProvince as $cityName)
                    <option value="{{ $cityName }}" @selected($selectedCity === $cityName)>{{ $cityName }}</option>
                @endforeach
            </select>
            @if($isThisForm) @error('city')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
        </div>
    </div>

    <div class="np-account-form__field">
        <label for="{{ $prefix }}-address">آدرس کامل</label>
        <textarea id="{{ $prefix }}-address" name="address" autocomplete="street-address" rows="3" minlength="10" maxlength="1000" @class(['is-invalid' => $isThisForm && $errors->has('address')]) @if($isThisForm && $errors->has('address')) aria-invalid="true" @endif required>{{ $fieldValue('address', $address?->address) }}</textarea>
        @if($isThisForm) @error('address')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
    </div>

    <div class="np-account-form__field">
        <label for="{{ $prefix }}-postal_code">کد پستی ده‌رقمی</label>
        <input id="{{ $prefix }}-postal_code" name="postal_code" dir="ltr" inputmode="numeric" autocomplete="postal-code" data-normalize-digits value="{{ $fieldValue('postal_code', $address?->postal_code) }}" maxlength="10" @class(['is-invalid' => $isThisForm && $errors->has('postal_code')]) @if($isThisForm && $errors->has('postal_code')) aria-invalid="true" @endif required>
        @if($isThisForm) @error('postal_code')<p class="np-field-error" role="alert">{{ $message }}</p>@enderror @endif
    </div>

    <button class="np-account-primary" type="submit">ذخیره آدرس</button>
</form>
