@props(['name', 'label', 'kind' => 'input', 'type' => 'text', 'value' => '', 'required' => true, 'markRequired' => false, 'maxlength' => 2000, 'retain' => false, 'inputmode' => null, 'autocomplete' => null, 'floating' => false])
@php($fieldId = 'repair-'.$name.'-'.\Illuminate\Support\Str::random(6))
@php($value = $type === 'password' ? '' : ($retain ? old($name, $value) : $value))
@php($value = is_scalar($value) ? $value : '')
@php($value = $type === 'tel' ? \App\Support\FaNumber::digits(\App\Support\FaNumber::normalizeDigits((string) $value)) : $value)
@php($isSerialField = in_array($name, ['device_serial', 'serials'], true))
@if($floating)
<div class="public-field">
    <div class="np-float-field" data-float-field>
        @if($kind === 'textarea')
            <textarea @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" placeholder=" " @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @endif @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>{{ $isSerialField ? strtoupper((string) $value) : $value }}</textarea>
        @else
            <input @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $isSerialField ? strtoupper((string) $value) : $value }}" maxlength="{{ $maxlength }}"
                @if($type === 'number') min="0" max="1000000000" step="1" @endif
                @if($inputmode || $type === 'tel') inputmode="{{ $inputmode ?? 'numeric' }}" @endif
                @if($type === 'tel') data-digits-only="{{ min(11, (int) $maxlength) }}" placeholder="۰۹۱۲۳۴۵۶۷۸۹" @else placeholder=" " @endif
                @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @elseif($inputmode || in_array($type, ['number', 'tel', 'datetime-local'])) dir="ltr" @endif
                autocomplete="{{ $autocomplete ?? ($type === 'password' ? 'current-password' : 'off') }}" @required($required)
                @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
        @endif
        <label class="label" for="{{ $fieldId }}">{{ $label }}@if($markRequired && $required)<span class="admin-required" aria-hidden="true">*</span>@endif</label>
    </div>
    @error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
@else
<div>
@if($type === 'password' && $autocomplete === null)
    <div class="admin-autofill-trap" aria-hidden="true">
        <input type="text" tabindex="-1" autocomplete="username">
        <input type="password" tabindex="-1" autocomplete="current-password">
    </div>
@endif
<label class="label" for="{{ $fieldId }}">{{ $label }}@if($markRequired && $required)<span class="admin-required" aria-hidden="true">*</span>@endif</label>
@if($kind === 'textarea')
    <textarea @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" autocomplete="off" @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @endif @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>{{ $isSerialField ? strtoupper((string) $value) : $value }}</textarea>
@else
    <input @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $isSerialField ? strtoupper((string) $value) : $value }}" maxlength="{{ $maxlength }}"
        @if($type === 'number') min="0" max="1000000000" step="1" @endif
        @if($inputmode || $type === 'tel') inputmode="{{ $inputmode ?? 'numeric' }}" @endif
        @if($type === 'tel') data-digits-only="{{ min(11, (int) $maxlength) }}" placeholder="۰۹۱۲۳۴۵۶۷۸۹" @endif
        @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @elseif($inputmode || in_array($type, ['number', 'tel', 'datetime-local'])) dir="ltr" @endif
        autocomplete="{{ $autocomplete ?? ($type === 'password' ? 'new-password' : 'off') }}"
        @if($type !== 'password') data-lpignore="true" data-1p-ignore="true" @else data-admin-reauth="1" @endif
        @required($required)
        @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
@endif
@error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
@endif
