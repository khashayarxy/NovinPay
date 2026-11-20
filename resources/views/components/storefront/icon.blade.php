@props(['name'])
@php
    // Lucide (ISC) outline geometry adapted to the shared inline SVG component.
    $paths = [
        'filter' => 'M4 7h16 M7 12h10 M10 17h4',
        'identifier' => 'M3 5v14 M7 5v14 M10 5v14 M15 5v14 M18 5v14 M21 5v14',
        'review' => 'M21 14a3 3 0 0 1-3 3H9l-6 4V6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3z M7 8h10 M7 12h6',
        'repair' => 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z',
        'home' => 'M9 22v-8h6v8 M3 10a2 2 0 0 1 .71-1.53l7-6a2 2 0 0 1 2.58 0l7 6A2 2 0 0 1 21 10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        'shop' => 'M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5 M3 7l2.43-3.24A2 2 0 0 1 7.03 3h9.94a2 2 0 0 1 1.6.76L21 7 M3 7h18v3a2 2 0 0 1-3 1.73 2 2 0 0 1-3 0 2 2 0 0 1-3 0 2 2 0 0 1-3 0 2 2 0 0 1-3 0A2 2 0 0 1 3 10z M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7',
        'categories' => 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
        'book' => 'M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20',
        'search' => 'M21 21l-4.34-4.34 M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
        'cart' => 'M2 2h2l2.4 12a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L22 6H5 M10 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0 M20 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0',
        'account' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'arrow' => 'M14 6l-6 6 6 6',
        'plus' => 'M5 12h14 M12 5v14',
        'minus' => 'M5 12h14',
        'trash' => 'M3 6h18 M8 6V3h8v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
        'chevron-down' => 'm6 9 6 6 6-6',
        'close' => 'M6 6l12 12 M6 18L18 6',
        'zoom' => 'M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0 M15 15l6 6 M7 10h6 M10 7v6',
        'check' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0 M8 12l3 3 5-6',
        'shield' => 'M12 2l8 3v6c0 5-8 10-8 10S4 16 4 11V5z M8 11l3 3 5-5',
        'phone' => 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z',
    ];
@endphp
<svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['arrow'] }}" /></svg>
