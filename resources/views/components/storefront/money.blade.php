@props(['amount'])
<span {{ $attributes->class('commerce-money') }}><bdi>{{ \App\Support\FaNumber::digits(number_format($amount, 0, '.', '٬')) }}</bdi> <small>تومان</small></span>
