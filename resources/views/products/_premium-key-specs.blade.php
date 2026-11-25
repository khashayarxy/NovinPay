<h2>مشخصات کلیدی</h2>
@foreach (array_chunk($keyRows, 4) as $index => $rows)
    <dl>
        @foreach ($rows as [$label, $value])
            <div><dt>{{ $publicSpecLabel($label) }}</dt><dd>{{ \App\Support\LatinText::html($value) }}</dd></div>
        @endforeach
    </dl>
@endforeach
<a class="text-link" href="#product-specs">همهٔ مشخصات فنی<x-storefront.icon name="arrow" /></a>
