@props(['name', 'label', 'hint' => null, 'required' => true])
<div class="public-field">
    <label for="{{ $name }}">{{ $label }} @if($required)<span aria-hidden="true">*</span>@endif</label>
    {{ $slot }}
    <div id="{{ $name }}-feedback">
        @if ($hint)<p class="public-hint">{{ $hint }}</p>@endif
        @error($name)<p class="public-field-error">{{ $message }}</p>@enderror
    </div>
</div>
