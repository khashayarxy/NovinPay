@props(['value'])
<time datetime="{{ $value->copy()->utc()->toIso8601String() }}" title="{{ $value->copy()->utc()->format('Y-m-d H:i:s') }} UTC">{{ \App\Support\FinanceTime::display($value) }}</time>
