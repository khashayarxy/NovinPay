@props(['name', 'label', 'kind' => 'input', 'type' => 'text', 'value' => '', 'required' => true, 'markRequired' => false, 'maxlength' => 2000, 'retain' => false, 'inputmode' => null, 'autocomplete' => null])
@php($fieldId = 'repair-'.$name.'-'.\Illuminate\Support\Str::random(6))
@php($value = $type === 'password' ? '' : ($retain ? old($name, $value) : $value))
@php($value = is_scalar($value) ? $value : '')
@php($value = $type === 'tel' ? \App\Support\FaNumber::digits(\App\Support\FaNumber::normalizeDigits((string) $value)) : $value)
@php($isSerialField = in_array($name, ['device_serial', 'serials'], true))
<div><label class="label" for="{{ $fieldId }}">{{ $label }}@if($markRequired && $required)<span class="admin-required" aria-hidden="true">*</span>@endif</label>
@if($kind === 'textarea')
    <textarea @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @endif @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>{{ $isSerialField ? strtoupper((string) $value) : $value }}</textarea>
@else
    <input @class(['input', 'pos-serial-input' => $isSerialField]) id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $isSerialField ? strtoupper((string) $value) : $value }}" maxlength="{{ $maxlength }}"
        @if($type === 'number') min="0" max="1000000000" step="1" @endif
        @if($inputmode || $type === 'tel') inputmode="{{ $inputmode ?? 'numeric' }}" @endif
        @if($type === 'tel') data-digits-only="{{ min(11, (int) $maxlength) }}" placeholder="۰۹۱۲۳۴۵۶۷۸۹" @endif
        @if($isSerialField) dir="ltr" autocapitalize="characters" spellcheck="false" data-pos-serial @elseif($inputmode || in_array($type, ['number', 'tel', 'datetime-local'])) dir="ltr" @endif
        autocomplete="{{ $autocomplete ?? ($type === 'password' ? 'current-password' : 'off') }}" @required($required)
        @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
@endif
@error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
