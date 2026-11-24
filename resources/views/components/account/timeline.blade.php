@props(['steps', 'orientation' => 'vertical'])
@php
    $isHorizontal = $orientation === 'horizontal';
@endphp
<ol @class(['np-timeline', 'np-timeline--horizontal' => $isHorizontal]) aria-label="مراحل پیگیری">
    @foreach($steps as $step)
        @php
            $isUpcoming = $step['state'] === 'upcoming';
            $status = match ($step['state']) {
                'current' => $step['note'] ?? 'مرحلهٔ فعلی',
                'complete' => $isHorizontal ? null : 'تکمیل‌شده',
                'upcoming' => $isHorizontal ? null : 'در پیش',
                'cancelled' => 'لغوشده',
                default => null,
            };
        @endphp
        <li data-stage="{{ $step['state'] }}" @if(in_array($step['state'], ['current', 'cancelled'], true)) aria-current="step" @endif>
            <span class="np-timeline__mark" aria-hidden="true">
                @if($step['state'] === 'complete')
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="m3 8 3 3 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($step['state'] === 'cancelled')
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="m4 4 8 8m0-8-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                @endif
            </span>
            <div>
                <strong>{{ $step['label'] }}</strong>
                @if(! $isHorizontal || ! $isUpcoming)
                    @if($status)<span class="np-timeline__status">{{ $status }}</span>@endif
                    @if(! $isHorizontal && ! empty($step['at']))<small><x-finance-time :value="$step['at']" /></small>@endif
                    @if(! $isHorizontal && $step['state'] === 'unrecorded')<small>این مرحله ثبت نشده است.</small>@endif
                @endif
            </div>
        </li>
    @endforeach
</ol>
