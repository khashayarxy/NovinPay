<div class="np-brands__grid" aria-label="برندهای تحت پوشش">
@foreach (['Newland', 'NewPOS', 'PAX'] as $brand)
<a href="{{ route('products.index', ['q' => $brand]) }}" class="np-brand np-brand--{{ strtolower($brand) }}" aria-label="مشاهده دستگاه‌های {{ $brand }}"><img src="{{ asset('brand/manufacturers/'.$brand.'.svg') }}" alt="{{ $brand }}" width="180" height="70" loading="lazy" decoding="async"></a>
@endforeach
</div>
