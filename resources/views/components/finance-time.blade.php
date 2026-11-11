@props(['value'])
<time datetime="{{ $value->copy()->utc()->toIso8601String() }}">{{ \App\Support\FinanceTime::display($value) }}</time>
