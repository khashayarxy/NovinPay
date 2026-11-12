@props(['value', 'withTime' => true])
@if($value)
<time dir="ltr" datetime="{{ $value->copy()->utc()->toIso8601String() }}">{{ \App\Support\FinanceTime::display($value, withTime: $withTime) }}</time>
@else
—
@endif
