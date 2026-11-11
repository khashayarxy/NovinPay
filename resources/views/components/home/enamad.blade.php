@props(['useProviderImage' => false])

<div {{ $attributes->class(['np-enamad']) }}>
    <a referrerpolicy="origin"
       target="_blank"
       href="https://trustseal.enamad.ir/?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">
        <img
            referrerpolicy="origin"
            src="{{ $useProviderImage ? 'https://trustseal.enamad.ir/logo.aspx?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n' : asset('brand/trust/enamad-8644634.png') }}"
            alt="نماد اعتماد الکترونیکی نوین‌پی"
            code="bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">
    </a>
    <a class="np-enamad__fallback"
       referrerpolicy="origin"
       target="_blank"
       href="https://trustseal.enamad.ir/?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">استعلام نماد اعتماد الکترونیکی</a>
</div>
<script src="{{ asset('brand/trust/enamad-fallback.js') }}" defer></script>
