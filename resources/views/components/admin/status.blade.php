@props(['tone' => 'neutral'])

<span {{ $attributes->class(['admin-pill', 'admin-pill--'.$tone]) }}>{{ $slot }}</span>
