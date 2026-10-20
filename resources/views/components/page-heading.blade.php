@props(['title', 'lead', 'eyebrow' => 'نوین‌پی؛ همراه کسب‌وکار شما', 'icon' => 'shopping-bag'])
<header class="public-heading">
    <div>
        <nav aria-label="مسیر صفحه" class="public-breadcrumb"><a href="{{ route('home') }}">صفحه اصلی</a><span aria-hidden="true">/</span><span>{{ $title }}</span></nav>
        <span class="np-eyebrow">{{ $eyebrow }}</span>
        <h1>{{ $title }}</h1>
        <p>{{ $lead }}</p>
        {{ $slot }}
    </div>
    <div class="public-heading__icon" aria-hidden="true"><x-home.icon :name="$icon" /></div>
</header>
