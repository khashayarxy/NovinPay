@props(['name', 'label', 'hint' => null, 'required' => true])
<div class="public-field">
    <div class="np-float-field" data-float-field>
        {{ $slot }}
        <label for="{{ $name }}">{{ $label }} @if($required)<span aria-hidden="true">*</span>@endif</label>
    </div>
    @isset($after)
        {{ $after }}
    @endisset
    <div id="{{ $name }}-feedback">
        @if ($hint)<p class="public-hint">{{ $hint }}</p>@endif
        <p class="public-field-error" data-field-error="{{ $name }}" role="alert" @unless($errors->has($name)) hidden @endunless>@error($name){{ $message }}@enderror</p>
    </div>
</div>
