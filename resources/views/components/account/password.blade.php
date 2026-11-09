@props(['id', 'name', 'autocomplete'])
<div class="np-password">
    <input type="password" id="{{ $id }}" name="{{ $name }}" autocomplete="{{ $autocomplete }}" maxlength="72" required {{ $attributes }}>
    <button type="button" class="np-account-link" data-account-password="{{ $id }}" aria-controls="{{ $id }}" hidden>نمایش</button>
</div>
