<h2>مشخصات کلیدی</h2>
@foreach (array_chunk($keyRows, 4) as $index => $rows)
    @if ($index === 1)
        <details class="more-key-specs">
            <summary><span>نمایش بیشتر</span><span>نمایش کمتر</span></summary>
    @endif
    <dl>
        @foreach ($rows as [$label, $value])
            <div><dt>{{ $publicSpecLabel($label) }}</dt><dd>{{ \App\Support\LatinText::html($value) }}</dd></div>
        @endforeach
    </dl>
    @if ($loop->last && $index > 0)
        </details>
    @endif
@endforeach
<a class="text-link" href="#product-specs">همهٔ مشخصات فنی<x-storefront.icon name="arrow" /></a>
