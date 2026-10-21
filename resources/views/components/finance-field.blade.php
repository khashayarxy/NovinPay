@props(['name','label','kind'=>'input','type'=>'text','value'=>'','required'=>true,'maxlength'=>2000,'inputmode'=>null])
@php($fieldId = 'finance-'.$name)
<div>
    <label class="label" for="{{ $fieldId }}">{{ $label }}</label>
    @if($kind === 'textarea')
        <textarea class="input" id="{{ $fieldId }}" name="{{ $name }}" rows="4" maxlength="{{ $maxlength }}" autocomplete="off" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>{{ $value }}</textarea>
    @else
        <input class="input" id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" maxlength="{{ $maxlength }}" autocomplete="{{ $type === 'password' ? 'current-password' : 'off' }}" @if($type === 'number') min="1" step="1" @endif @if($inputmode) inputmode="{{ $inputmode }}" @endif dir="ltr" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>
    @endif
    @error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
