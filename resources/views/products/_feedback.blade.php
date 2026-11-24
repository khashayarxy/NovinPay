@if (session('feedback_status')) <p class="product-notice" role="status">{{ session('feedback_status') }}</p> @endif
@if ($errors->any()) <p class="product-notice" role="alert">{{ $errors->first() }}</p> @endif
@foreach (['review' => 'دیدگاه‌ها'] as $kind => $heading)
    <section class="product-panel product-feedback product-feedback--{{ $kind }}" id="product-{{ $kind }}" aria-labelledby="{{ $kind }}-heading">
        <div class="product-feedback-heading">
            <div>
                <h2 id="{{ $kind }}-heading">{{ $heading }}</h2>
                @if ($feedback[$kind]->total())
                    <p>{{ \App\Support\FaNumber::digits($feedback[$kind]->total()) }} {{ $kind === 'review' ? 'دیدگاه منتشرشده' : 'پرسش منتشرشده' }}</p>
                @endif
            </div>
            @unless ($feedbackAccount)
                <a class="btn btn-primary" href="{{ route('account.login') }}">{{ $kind === 'review' ? 'ورود و ثبت دیدگاه' : 'طرح پرسش' }}</a>
            @endunless
        </div>
        @if ($kind === 'review' && $productRating)
            <div class="product-rating-summary">
            <p class="product-score">{{ str_replace('.', '٫', \App\Support\FaNumber::digits((string) $productRating['value'])) }} از ۵ · {{ \App\Support\FaNumber::digits($productRating['count']) }} امتیاز از خریداران تأییدشده</p>
                <ul class="product-rating-breakdown" aria-label="توزیع امتیاز خریداران">
                    @foreach (range(5, 1) as $stars)
                        @php($count = $productRating['distribution']->get($stars, 0))
                        <li>
                            <span>{{ \App\Support\FaNumber::digits($stars) }} امتیاز</span>
                            <meter min="0" max="{{ $productRating['count'] }}" value="{{ $count }}"
                                aria-label="تعداد امتیازهای {{ \App\Support\FaNumber::digits($stars) }}"></meter>
                            <span>{{ \App\Support\FaNumber::digits($count) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($kind === 'review' && $feedback[$kind]->total() > 1)
            <form class="product-review-sort" method="get" action="{{ route('products.show', $product) }}#product-review">
                <label class="label" for="review-sort">ترتیب دیدگاه‌ها</label>
                <select class="input" id="review-sort" name="review_sort">
                    @foreach ($reviewSorts as $key => $label)
                        <option value="{{ $key }}" @selected($reviewSort === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-secondary" type="submit">اعمال ترتیب</button>
            </form>
        @endif
        @forelse ($feedback[$kind] as $entry)
            <article class="product-entry">
                <header>
                    @if ($kind === 'review')
                        <span class="review-author-icon"><x-storefront.icon name="account" /></span><strong>{{ $entry->verified_buyer ? 'خریدار تأییدشده' : 'دیدگاه کاربر' }}</strong>
                        @if ($entry->verified_buyer && $entry->rating)
                            <span class="rating-stars" dir="ltr" role="img" aria-label="{{ $entry->rating }} از ۵">
                                @foreach (range(1, 5) as $number)
                                    <span @class(['is-filled' => $number <= $entry->rating]) aria-hidden="true">★<small>{{ $number }}</small></span>
                                @endforeach
                            </span>
                        @endif
                    @else
                        <strong>پرسش</strong>
                    @endif
                    @if ($posted = \App\Support\FinanceTime::persianDate($entry->created_at))
                        <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $posted }}</time>
                    @endif
                </header>
                <p class="product-lines">{{ $entry->body }}</p>
                @if ($entry->staff_reply)
                    <div class="product-reply"><strong>پاسخ رسمی نوین‌پی</strong><p class="product-lines">{{ $entry->staff_reply }}</p></div>
                @endif
                @if ($feedbackAccount)
                    <div class="product-report">
                        <button type="button" class="product-report-toggle" data-toggle-panel="product-report-{{ $entry->id }}" aria-expanded="false" aria-controls="product-report-{{ $entry->id }}">گزارش محتوا</button>
                        <div id="product-report-{{ $entry->id }}" class="product-report-panel" hidden>
                            <form method="post" action="{{ route('products.feedback.report', [$product, $entry]) }}">
                                @csrf
                                <label class="label" for="report-{{ $entry->id }}">دلیل گزارش</label>
                                <select class="input" name="reason" id="report-{{ $entry->id }}" required>
                                    @foreach (\App\Http\Controllers\ProductFeedbackController::REPORT_REASONS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-secondary">ارسال گزارش</button>
                            </form>
                        </div>
                    </div>
                @endif
            </article>
        @empty
            <div class="review-empty"><x-storefront.icon name="review" /><div><p class="product-feedback-empty">{{ $kind === 'review' ? 'هنوز دیدگاهی برای این محصول ثبت نشده است.' : 'هنوز پرسشی برای این محصول ثبت نشده است.' }}</p></div></div>
        @endforelse
        {{ $feedback[$kind]->links() }}
        @if ($feedbackAccount)
            <form class="product-feedback-form" id="submit-{{ $kind }}" tabindex="-1" method="post" action="{{ route('products.feedback.store', $product) }}">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <div hidden><label for="website-{{ $kind }}">وب‌سایت</label><input id="website-{{ $kind }}" name="website" tabindex="-1" autocomplete="off"></div>
                <label class="label" for="body-{{ $kind }}">{{ $kind === 'question' ? 'پرسش شما' : ($ownReview ? 'ویرایش دیدگاه شما' : 'دیدگاه شما') }}</label>
                @if ($kind === 'review' && $ownReview?->status === 'pending')
                    <p class="product-notice">دیدگاه شما در انتظار بررسی است.</p>
                @elseif ($kind === 'review' && $ownReview?->status === 'rejected')
                    <p class="product-notice">دیدگاه قبلی منتشر نشد. می‌توانید آن را ویرایش و دوباره ارسال کنید.</p>
                @endif
                @if ($kind === 'review' && $canRate)
                    <fieldset class="rating-choice">
                        <legend>امتیاز شما (اختیاری)</legend>
                        <div class="rating-stars rating-stars--input" dir="ltr">
                            @foreach (range(1, 5) as $number)
                                <label title="{{ \App\Support\FaNumber::digits($number) }} ستاره">
                                    <input class="sr-only" type="radio" name="rating" value="{{ $number }}" @checked((int) old('rating', $ownReview?->rating) === $number)>
                                    <span class="rating-star-glyph" aria-hidden="true">★</span>
                                    <small>{{ \App\Support\FaNumber::digits($number) }}</small>
                                </label>
                            @endforeach
                        </div>
                        <label class="rating-none-option"><input type="radio" name="rating" value="" @checked(! old('rating', $ownReview?->rating))> بدون امتیاز</label>
                    </fieldset>
                @endif
                <textarea class="input" id="body-{{ $kind }}" name="body" rows="4" minlength="5" maxlength="1500" placeholder="تجربهٔ خود را از کارکرد و کیفیت محصول بنویسید (بدون شماره تماس یا اطلاعات شخصی)." required>{{ old('kind') === $kind ? old('body') : ($kind === 'review' ? $ownReview?->body : '') }}</textarea>
                <button type="submit" class="btn btn-primary">{{ $kind === 'review' ? ($ownReview ? 'به‌روزرسانی دیدگاه' : 'ارسال دیدگاه') : 'ارسال پرسش' }}</button>
            </form>
        @endif
    </section>
@endforeach
