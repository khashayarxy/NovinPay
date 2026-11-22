@props(['compact' => false])
<footer @class(['np-footer', 'np-footer--compact' => $compact])>
    @if ($compact)
        <div class="np-container np-footer__transaction">
            <a href="{{ route('home') }}">{{ config('business.name') }}</a>
            <a href="{{ route('terms') }}">شرایط خرید</a>
            <x-business-phones link-class="np-footer__phone" />
        </div>
    @else
    <div class="np-container">
        <div class="np-footer__grid">
            <div class="np-footer__brand">
                <div class="np-footer__identity">
                    <a href="{{ route('home') }}" class="np-logo-link" aria-label="صفحهٔ اصلی {{ config('business.name') }}"><x-home.wordmark /></a>
                    <p>فروش و تعمیر کارتخوان در شهریار</p>
                </div>
                <div class="np-footer__marks" aria-label="نمادهای اعتماد و مجوزها">
                    <div class="np-footer__badge">
                        <x-home.enamad />
                    </div>
                    <a class="np-footer__badge np-footer__badge--link"
                       href="https://gateway.zibal.ir/trustMe/novinpaypos.com"
                       target="_blank"
                       rel="noopener noreferrer nofollow"
                       title="پرداخت آنلاین زیبال">
                        <img class="np-footer__zibal" src="{{ asset('images/zibal-trust.png') }}" width="54" height="76" alt="پرداخت آنلاین زیبال" loading="lazy" decoding="async">
                    </a>
                    <div class="np-footer__badge">
                        <img class="np-footer__nezam" src="{{ asset('brand/trust/NezamSenfi.svg') }}" width="64" height="51" alt="سازمان نظام صنفی رایانه‌ای" loading="lazy" decoding="async">
                    </div>
                    @if (config('business.show_footer_affiliation_marks', false))
                        <div class="np-footer__badge">
                            <img class="np-footer__shaparak" src="{{ asset('brand/trust/Shaparak.svg') }}" width="76" height="50" alt="شاپرک، شبکهٔ پرداخت" loading="lazy" decoding="async">
                        </div>
                    @endif
                </div>
            </div>
            <nav aria-label="پیوندهای کاربردی">
                <h2>دسترسی سریع</h2>
                <a href="{{ route('account.index') }}">حساب کاربری</a>
                <a href="{{ route('cart.index') }}">سبد خرید</a>
                <a href="{{ route('products.index') }}">فروشگاه</a>
                <a href="{{ route('repair') }}">تعمیرات</a>
                <a href="{{ route('contact') }}">تماس با ما</a>
                <a href="{{ route('articles.index') }}">مقاله‌ها</a>
            </nav>
            <div class="np-footer__information">
                <h2>تماس با نوین‌پی</h2>
                <x-business-phones class="np-footer__phones" link-class="np-footer__phone" />
            </div>
        </div>
        <div class="np-footer__bottom">
            <p>تمامی حقوق برای {{ config('business.name') }} محفوظ است. © {{ \App\Support\FaNumber::digits(now()->year) }}</p>
        </div>
    </div>
    @endif
</footer>
