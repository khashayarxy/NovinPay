@props(['name', 'label', 'kind' => 'input', 'type' => 'text', 'value' => '', 'required' => true, 'maxlength' => 2000, 'retain' => false, 'inputmode' => null, 'autocomplete' => null])
@php($fieldId = 'repair-'.$name.'-'.\Illuminate\Support\Str::random(6))
@php($value = $type === 'password' ? '' : ($retain ? old($name, $value) : $value))
@php($value = is_scalar($value) ? $value : '')
@php($value = $type === 'tel' ? \App\Support\FaNumber::digits(\App\Support\FaNumber::normalizeDigits((string) $value)) : $value)
<div><label class="label" for="{{ $fieldId }}">{{ $label }}</label>
@if($kind === 'textarea')
    <textarea class="input" id="{{ $fieldId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>{{ $value }}</textarea>
@else
    <input class="input" id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" maxlength="{{ $maxlength }}"
        @if($type === 'number') min="0" max="1000000000" step="1" @endif
        @if($inputmode || $type === 'tel') inputmode="{{ $inputmode ?? 'tel' }}" @endif
        @if($type === 'tel') placeholder="۰۹۱۲۳۴۵۶۷۸۹" @endif
        @if($inputmode || in_array($type, ['number', 'tel', 'datetime-local'])) dir="ltr" @endif
        autocomplete="{{ $autocomplete ?? ($type === 'password' ? 'current-password' : 'off') }}" @required($required)
        @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror>
@endif
@error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
