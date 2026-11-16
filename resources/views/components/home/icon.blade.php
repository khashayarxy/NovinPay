@props(['name'])

@if (in_array($name, ['home', 'book'], true))
    <svg {{ $attributes->class(['np-nav-icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @if ($name === 'home')
            <path d="M3 10l9-7 9 7M5 9v11h14V9M10 20v-6h4v6" />
        @else
            <path d="M12 6C9 4 6 4 3 5v14c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1v14" />
        @endif
    </svg>
@else
    @php
        $icons = ['arrow-left', 'arrow-up', 'shopping-bag', 'tool', 'headphones', 'truck', 'shield-check', 'tag', 'user', 'building-bank', 'printer', 'power', 'antenna-bars-5', 'battery-charging', 'settings', 'map-pin', 'check', 'diamond', 'photo', 'phone', 'search', 'clock', 'world', 'layout-dashboard'];
        $name = in_array($name, $icons, true) ? $name : 'check';
    @endphp

    <span {{ $attributes->class(['np-icon']) }} style="--icon: url('{{ asset('vendor/tabler/' . $name . '.svg') }}')" aria-hidden="true"></span>
@endif
