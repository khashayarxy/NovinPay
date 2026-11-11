<div {{ $attributes->class(['np-enamad']) }}>
    <a referrerpolicy="origin"
       target="_blank"
       href="https://trustseal.enamad.ir/?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">
        <img
            referrerpolicy="origin"
            src="https://trustseal.enamad.ir/logo.aspx?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n"
            alt="نماد اعتماد الکترونیکی نوین‌پی"
            code="bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">
    </a>
    <a class="np-enamad__fallback"
       referrerpolicy="origin"
       target="_blank"
       href="https://trustseal.enamad.ir/?id=8644634&Code=bkoTwmLFJ1lp9goDGHf1cuve1Tgw8t6n">استعلام نماد اعتماد الکترونیکی</a>
</div>
<script>
    (function () {
        var seal = document.querySelector('.np-enamad');
        var image = seal.querySelector('img');

        function showFallback() {
            var wasFocused = document.activeElement === image.parentNode;
            seal.setAttribute('data-enamad-image-failed', '');
            if (wasFocused) {
                seal.querySelector('.np-enamad__fallback').focus();
            }
        }

        image.addEventListener('error', showFallback);
        if (image.complete && image.naturalWidth === 0) {
            showFallback();
        }
    }());
</script>
