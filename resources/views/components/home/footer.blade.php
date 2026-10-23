<footer class="np-footer">
    <div class="np-container">
        <div class="np-footer__grid">
            <div class="np-footer__brand">
                <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark :dark="true" /></a>
                <p>فروش و تعمیر دستگاه‌های کارتخوان در شهریار</p>
            </div>
            <nav aria-label="پیوندهای کاربردی">
                <h2>دسترسی سریع</h2>
                <a href="{{ route('products.index') }}">فروشگاه</a>
                <a href="{{ route('repair') }}">تعمیرات</a>
                <a href="{{ route('contact') }}">تماس با ما</a>
                <a href="{{ route('articles.index') }}">مقاله‌ها</a>
            </nav>
            <div class="np-footer__information">
                <h2>تماس با نوین‌پی</h2>
                <x-business-phones link-class="np-footer__phone" />
            </div>
        </div>
        <div class="np-footer__bottom">
            <p>تمامی حقوق برای {{ config('business.name') }} محفوظ است. © {{ \App\Support\FaNumber::digits(now()->year) }}</p>
        </div>
    </div>
</footer>
