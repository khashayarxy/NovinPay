@props(['title', 'icon' => 'search'])

<div {{ $attributes->class(['admin-empty']) }}>
    <span class="admin-empty-icon"><x-home.icon :name="$icon" /></span>
    <h3>{{ $title }}</h3>
    @if($slot->isNotEmpty())<p>{{ $slot }}</p>@endif
</div>
