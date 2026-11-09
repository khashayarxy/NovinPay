@props(['steps'])
<ol class="np-timeline" aria-label="مراحل پیگیری">
    @foreach($steps as $step)
        <li data-stage="{{ $step['state'] }}" @if($step['state'] === 'current') aria-current="step" @endif>
            <span class="np-timeline__mark" aria-hidden="true">
                @if($step['state'] === 'complete')
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="m3 8 3 3 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @endif
            </span>
            <div>
                <strong>{{ $step['label'] }}</strong>
                @if($step['state'] === 'current')<span class="np-account-tag">وضعیت فعلی</span>@endif
                @if($step['at'])<small><x-finance-time :value="$step['at']" /></small>@endif
                @if($step['state'] === 'unrecorded')<small>این مرحله ثبت نشده است.</small>@endif
            </div>
        </li>
    @endforeach
</ol>
