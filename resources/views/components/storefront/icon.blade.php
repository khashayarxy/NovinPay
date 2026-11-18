@props(['name'])
@php
    $paths = [
        'identifier' => 'M3 5v14 M7 5v14 M10 5v14 M15 5v14 M18 5v14 M21 5v14',
        'review' => 'M21 14a3 3 0 0 1-3 3H9l-6 4V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3z M7 8h10 M7 12h6',
        'repair' => 'M14 7a5 5 0 0 0-6 6l-5 5a2 2 0 0 0 3 3l6-6a5 5 0 0 0 6-7l-3 3-3-3 3-3z',
        'home' => 'M3 10l9-7 9 7v11h-6v-7H9v7H3z',
        'shop' => 'M3 9h18l-2-6H5z M4 9v12h16V9 M9 21v-7h6v7',
        'search' => 'M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0 M15 15l6 6',
        'cart' => 'M2 3h3l3 13h11l3-9H6 M10 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0 M20 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0',
        'account' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-3a8 8 0 0 1 16 0v3',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'arrow' => 'M14 6l-6 6 6 6',
        'close' => 'M6 6l12 12 M6 18L18 6',
        'zoom' => 'M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0 M15 15l6 6 M7 10h6 M10 7v6',
        'check' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0 M8 12l3 3 5-6',
        'shield' => 'M12 2l8 3v6c0 5-8 10-8 10S4 16 4 11V5z M8 11l3 3 5-5',
        'phone' => 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z',
    ];
@endphp
<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['arrow'] }}" /></svg>
