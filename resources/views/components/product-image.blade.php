@props(['product'])

@if ($product->image_path)
    <img src="{{ asset('storage/' . $product->image_path) }}" alt="تصویر {{ $product->name }}" loading="lazy"
        class="h-full w-full object-contain">
@else
    <div class="design-product-identity"><x-site.manufacturer :name="$product->name"/><div><span>کارتخوان · {{ $product->condition->label() }}</span><strong dir="auto">{{ preg_match('/\b(?:PAX|NewPOS|Newland)\s+[a-z0-9-]+/i', $product->name, $model) ? $model[0] : $product->name }}</strong><p>{{ $product->category->label() }}</p></div></div>
@endif
