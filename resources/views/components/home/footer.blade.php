<footer class="np-footer">
    <div class="np-container">
        <div class="np-footer__grid">
            <div class="np-footer__brand">
                <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark :dark="true" /></a>
                <p>فروش و تعمیر دستگاه‌های کارتخوان در شهریار</p>
            </div>
            <nav aria-label="پیوندهای کاربردی">
                <h2>دسترسی سریع</h2>
                <a href="{{ route('home') }}">صفحه اصلی</a>
                <a href="{{ route('products.index') }}">محصولات</a>
                <a href="{{ route('repair') }}">خدمات تعمیرات</a>
                <a href="{{ route('contact') }}">تماس با ما</a>
            </nav>
            <div class="np-footer__information">
                <h2>تماس با نوین‌پی</h2>
                <a href="{{ config('business.phone_uri') }}" class="np-footer__phone" dir="ltr">{{ config('business.phone') }}</a>
                <address>{{ config('business.address') }}</address>
                <p class="np-footer__hours">{{ str_replace('پنجشنبه', 'پنجشنبه،', config('business.hours.open')) }} | {{ config('business.hours.closed') }}</p>
            </div>
        </div>
        <div class="np-footer__bottom">
            <p>تمامی حقوق برای {{ config('business.name') }} محفوظ است. © {{ \App\Support\FaNumber::digits(now()->year) }}</p>
        </div>
    </div>
</footer>
