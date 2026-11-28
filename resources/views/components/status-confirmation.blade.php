@props(['title'])
<section class="public-panel public-confirmation" aria-labelledby="confirmation-title">
    <span class="status-confirmation-mark" aria-hidden="true">✓</span>
    <h1 id="confirmation-title" class="text-2xl font-bold mt-4">{{ $title }}</h1>
    <p class="mt-4">{{ $slot }}</p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">{{ $actions }}</div>
</section>
