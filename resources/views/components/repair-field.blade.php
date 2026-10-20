@props(['name', 'label', 'hint' => null])
<div class="public-field">
    <label for="{{ $name }}">{{ $label }} <span aria-hidden="true">*</span></label>
    {{ $slot }}
    <div id="{{ $name }}-feedback">
        @if ($hint)<p class="public-hint">{{ $hint }}</p>@endif
        @error($name)<p class="public-field-error">{{ $message }}</p>@enderror
    </div>
</div>
