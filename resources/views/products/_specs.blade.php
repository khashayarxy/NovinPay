@php
    $groups = [];
    foreach (\App\Models\Product::SPEC_GROUPS as $key => $heading) {
        $rows = $specRows[$key] ?? [];
        if (!$rows) {
            continue;
        }
        $groups[$key] = compact('heading', 'rows');
    }
    if ($product->specifications) {
        $groups['legacy'] = ['heading' => 'مشخصات تکمیلی',
            'rows' => array_map(null, array_keys($product->specifications), array_values($product->specifications))];
    }
    $labelKey = static fn (string $label) => match ($label) {
        'نوع نمایشگر' => 'نمایشگر',
        'نوع چاپ' => 'چاپگر',
        default => $label,
    };
    $listed = collect($groups)->pluck('rows')->collapse()->map(fn ($row) => $labelKey($row[0]))->all();
    $unlistedHighlights = array_filter($highlights,
        fn ($row) => !in_array($labelKey($row[0]), $listed, true));
    if ($unlistedHighlights) {
        $groups['highlights'] = ['heading' => 'ویژگی‌های کلیدی', 'rows' => $unlistedHighlights];
    }
@endphp

<div class="spec-groups">
    @foreach ($groups as $group)
        <section>
            <h3>{{ $group['heading'] }}</h3>
            <dl>
                @foreach ($group['rows'] as [$label, $value])
                    <div><dt>{{ $publicSpecLabel($label) }}</dt><dd><bdi>{{ $value }}</bdi></dd></div>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
