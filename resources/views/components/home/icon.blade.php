@props(['name'])

@php
    $icons = ['arrow-left', 'arrow-up', 'shopping-bag', 'tool', 'headphones', 'truck', 'shield-check', 'tag', 'user', 'building-bank', 'printer', 'power', 'antenna-bars-5', 'battery-charging', 'settings', 'map-pin', 'check', 'diamond', 'photo', 'phone', 'search', 'clock'];
    $name = in_array($name, $icons, true) ? $name : 'check';
@endphp

<span {{ $attributes->class(['np-icon']) }} style="--icon: url('{{ asset('vendor/tabler/' . $name . '.svg') }}')" aria-hidden="true"></span>
