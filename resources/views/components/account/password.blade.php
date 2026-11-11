@props(['id', 'name', 'autocomplete'])
<input type="password" id="{{ $id }}" name="{{ $name }}" autocomplete="{{ $autocomplete }}" maxlength="72" required {{ $attributes }}>
