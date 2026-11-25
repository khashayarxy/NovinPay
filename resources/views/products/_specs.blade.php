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

@php
    $groupIcons = [
        'general' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 9v5m0-8h.01"/>',
        'hardware' => '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9zM9 1v4M15 1v4M9 19v4M15 19v4M1 9h4M1 15h4M19 9h4M19 15h4"/>',
        'display' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'connectivity' => '<path d="M5 12.55a11 11 0 0 1 14.08 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/>',
        'payment' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'power' => '<rect x="2" y="7" width="18" height="10" rx="2"/><path d="M22 11v2M11 10l-2 2h4l-2 2"/>',
        'printer' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'physical' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>',
        'legacy' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'highlights' => '<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.8 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>',
    ];
@endphp

<div class="spec-groups spec-groups--table">
    @foreach ($groups as $key => $group)
        <section class="spec-category">
            <h3 class="spec-category__head">
                <span class="spec-category__icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $groupIcons[$key] ?? $groupIcons['legacy'] !!}</svg>
                </span>
                <span>{{ $group['heading'] }}</span>
            </h3>
            <dl class="spec-table">
                @foreach ($group['rows'] as [$label, $value])
                    <div class="spec-table__row">
                        <dt>{{ $publicSpecLabel($label) }}</dt>
                        <dd>{{ \App\Support\LatinText::html($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
