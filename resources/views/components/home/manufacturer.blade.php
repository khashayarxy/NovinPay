@props(['name'])

@php
    $assets = [
        'PAX' => ['PAX.svg', 1220, 410, null],
        'NewPOS' => ['NewPOS.svg', 656, 187, null],
        'Newland' => ['Newland.svg', 1100, 295, '7edcc7f7a62c'],
        'SZZT' => ['SZZT.png', 501, 86, '5efb8142e6b2'],
        'TOPWISE' => ['TOPWISE.svg', 260, 54, 'c5420012187c'],
        'MoreFun' => ['MoreFun.png', 502, 130, '4566abf5ffde'],
    ];
@endphp

@if (isset($assets[$name]))
    @php([$file, $width, $height, $version] = $assets[$name])
    <img {{ $attributes->class(['np-manufacturer']) }} src="{{ asset('brand/manufacturers/' . $file) }}{{ $version ? '?v='.$version : '' }}"
        alt="{{ $name }}" width="{{ $width }}" height="{{ $height }}" decoding="async">
@endif
