<ul {{ $attributes->class(['flex flex-wrap gap-3']) }} aria-label="مسیریابی فروشگاه">
    @foreach (config('business.maps') as $label => $url)
        <li><a href="{{ $url }}" class="contact-link inline-flex min-h-11 items-center" target="_blank" rel="noopener noreferrer">{{ $label }}<span class="sr-only"> (در زبانهٔ جدید باز می‌شود)</span></a></li>
    @endforeach
</ul>
