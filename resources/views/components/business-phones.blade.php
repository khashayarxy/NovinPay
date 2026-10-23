@props(['linkClass' => ''])
<div {{ $attributes->class(['flex flex-wrap items-center gap-x-5 gap-y-2']) }}>
    <x-business-phone :class="$linkClass" />
    <x-business-phone :secondary="true" :class="$linkClass" />
</div>
