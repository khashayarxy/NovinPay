<footer class="np-footer">
    <div class="np-container">
        <div class="np-footer__grid">
            <div class="np-footer__information">
                <h2>اطلاعات تماس</h2>
                <p class="np-footer__hours"><x-home.icon name="clock" /><span>{{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}</span></p>
                <a href="{{ config('business.phone_uri') }}" class="np-footer__phone" dir="ltr">{{ config('business.phone') }}</a>
                <p>{{ config('business.address') }}</p>
                @unless (request()->routeIs('home'))
                    <x-map-links class="np-footer__maps" />
                @endunless
            </div>
            <nav aria-label="محصولات نوین‌پی">
                <h2>محصولات</h2>
                <a href="{{ route('products.index') }}">دستگاه‌های نو و کارکرده</a>
                @foreach (['PAX', 'NewPOS', 'Newland'] as $brand)
                    <a href="{{ route('products.index', ['q' => $brand]) }}" lang="en" dir="ltr">{{ $brand }}</a>
                @endforeach
            </nav>
            <nav aria-label="پیوندهای کاربردی">
                <h2>دسترسی سریع</h2>
                <a href="{{ route('home') }}">صفحه اصلی</a>
                <a href="{{ route('products.index') }}">محصولات</a>
                <a href="{{ route('repair') }}">ثبت درخواست تعمیر</a>
                <a href="{{ route('repair.track') }}">پیگیری درخواست</a>
                <a href="{{ route('contact') }}">تماس با ما</a>
            </nav>
            <div class="np-footer__brand">
                <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark :dark="true" /></a>
                <p>فروش و تعمیر دستگاه‌های کارتخوان<br>در شهریار</p>
                <a href="{{ config('business.phone_uri') }}" class="np-footer__consult">مشاورهٔ خرید و تعمیر <x-home.icon name="arrow-left" /></a>
            </div>
        </div>
        <div class="np-footer__bottom">
            <div><a href="{{ route('terms') }}">قوانین و مقررات</a><span aria-hidden="true">|</span><a href="{{ route('privacy') }}">حریم خصوصی</a></div>
            <p>تمامی حقوق برای {{ config('business.name') }} محفوظ است. © {{ \App\Support\FaNumber::digits(now()->year) }}</p>
        </div>
    </div>
</footer>
