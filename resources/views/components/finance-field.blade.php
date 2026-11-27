@props(['name','label','kind'=>'input','type'=>'text','value'=>'','required'=>true,'maxlength'=>2000,'inputmode'=>null,'direction'=>'ltr'])
@php($fieldId = 'finance-'.$name)
<div>
    @if($type === 'password')
        <div class="admin-autofill-trap" aria-hidden="true">
            <input type="text" tabindex="-1" autocomplete="username">
            <input type="password" tabindex="-1" autocomplete="current-password">
        </div>
    @endif
    <label class="label" for="{{ $fieldId }}">{{ $label }}</label>
    @if($kind === 'textarea')
        <textarea class="input" id="{{ $fieldId }}" name="{{ $name }}" rows="4" maxlength="{{ $maxlength }}" autocomplete="off" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>{{ $value }}</textarea>
    @else
        <input class="input" id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" maxlength="{{ $maxlength }}" autocomplete="{{ $type === 'password' ? 'new-password' : 'off' }}" @if($type !== 'password') data-lpignore="true" data-1p-ignore="true" @else data-admin-reauth="1" @endif @if($type === 'number') min="1" step="1" @endif @if($inputmode) inputmode="{{ $inputmode }}" @endif dir="{{ $direction }}" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @endif>
    @endif
    @error($name)<p class="finance-field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
