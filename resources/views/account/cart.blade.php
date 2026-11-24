@extends('layouts.app')
@section('title', 'سبد خرید')
@section('content')
<div class="np-container commerce-page">
    <x-storefront.cart-content
        :$items
        :$products
        :$quantity
        :$unavailable
        :$available
        :$stockAvailable
        :$total
        :$separate
        :$proof
        :$revision
        :$excluded
        :$legacyStatus
        :$overLimit
    />
</div>
@endsection
