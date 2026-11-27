@if (session('feedback_status'))
    <div class="product-notice product-notice--success" role="status">
        <span>{{ session('feedback_status') }}</span>
    </div>
@endif
@if ($errors->any())
    <div class="product-notice product-notice--error" role="alert">
        <span>{{ $errors->first() }}</span>
    </div>
@endif
@foreach (['review' => 'دیدگاه‌ها'] as $kind => $heading)
    @php
        $hasPendingPreview = $kind === 'review'
            && $feedbackAccount
            && $ownReview
            && $ownReview->status === 'pending'
            && (int) $ownReview->customer_account_id === (int) $feedbackAccount->id;
    @endphp
    <section class="product-panel product-feedback product-feedback--{{ $kind }}" id="product-{{ $kind }}" aria-labelledby="{{ $kind }}-heading">
        <div class="product-feedback-heading">
            <div class="product-feedback-heading__titles">
                <h2 id="{{ $kind }}-heading">{{ $heading }}</h2>
                @if ($feedback[$kind]->total())
                    <p class="product-feedback-count">{{ \App\Support\FaNumber::digits($feedback[$kind]->total()) }} {{ $kind === 'review' ? 'دیدگاه منتشرشده' : 'پرسش منتشرشده' }}</p>
                @else
                    <p class="product-feedback-count">تجربهٔ واقعی خریداران و کاربران محصول</p>
                @endif
            </div>
            @unless ($feedbackAccount)
                <a class="btn btn-primary" href="{{ route('account.login') }}">{{ $kind === 'review' ? 'ورود و ثبت دیدگاه' : 'طرح پرسش' }}</a>
            @endunless
        </div>

        @if ($kind === 'review' && $productRating)
            <div class="product-rating-summary">
                <div class="product-rating-hero">
                    <strong class="product-score-big">{{ str_replace('.', '٫', \App\Support\FaNumber::digits((string) $productRating['value'])) }}</strong>
                    <div class="product-rating-hero__meta">
                        <span class="rating-stars rating-stars--summary" dir="ltr" aria-hidden="true">
                            @foreach (range(1, 5) as $starIdx)
                                <span @class(['is-filled' => $starIdx <= round((float) $productRating['value'])])>★</span>
                            @endforeach
                        </span>
                        <p class="product-score">{{ str_replace('.', '٫', \App\Support\FaNumber::digits((string) $productRating['value'])) }} از ۵ · {{ \App\Support\FaNumber::digits($productRating['count']) }} امتیاز از خریداران تأییدشده</p>
                    </div>
                </div>
                <ul class="product-rating-breakdown" aria-label="توزیع امتیاز خریداران">
                    @foreach (range(5, 1) as $stars)
                        @php
                            $count = (int) $productRating['distribution']->get($stars, 0);
                            $pct = $productRating['count'] > 0 ? round(($count / $productRating['count']) * 100) : 0;
                        @endphp
                        <li>
                            <span class="product-rating-breakdown__label">{{ \App\Support\FaNumber::digits($stars) }} ستاره</span>
                            <span class="product-rating-bar" aria-hidden="true"><i style="width: {{ $pct }}%"></i></span>
                            <meter class="sr-only" min="0" max="{{ $productRating['count'] }}" value="{{ $count }}"
                                aria-label="تعداد امتیازهای {{ \App\Support\FaNumber::digits($stars) }}"></meter>
                            <span class="product-rating-breakdown__count">{{ \App\Support\FaNumber::digits($count) }}</span>
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

        <div class="product-entries-list">
            @if ($hasPendingPreview)
                <article class="product-entry product-entry--pending" data-review-preview="pending" aria-label="پیش‌نمایش دیدگاه ثبت‌شده شما">
                    <div class="product-entry__pending-banner">
                        <span class="product-entry__pending-dot" aria-hidden="true"></span>
                        <strong>پیش‌نمایش دیدگاه شما</strong>
                        <span class="product-entry__pending-badge">پس از تأیید مدیریت نمایش داده می‌شود</span>
                    </div>
                    <header class="product-entry__header">
                        <div class="product-entry__author">
                            <span class="review-author-icon"><x-storefront.icon name="account" /></span>
                            <div class="product-entry__author-meta">
                                <strong>{{ $feedbackAccount->name ?: 'دیدگاه شما' }}</strong>
                                <span @class(['review-role-badge', 'review-role-badge--verified' => $ownReview->verified_buyer])>
                                    {{ $ownReview->verified_buyer ? 'خریدار تأییدشده' : 'کاربر سایت' }}
                                </span>
                            </div>
                        </div>
                        <div class="product-entry__meta-end">
                            @if ($ownReview->verified_buyer && $ownReview->rating)
                                <span class="rating-stars rating-stars--display" dir="ltr" role="img" aria-label="{{ $ownReview->rating }} از ۵">
                                    @foreach (range(1, 5) as $number)
                                        <span @class(['is-filled' => $number <= $ownReview->rating]) aria-hidden="true">★</span>
                                    @endforeach
                                </span>
                            @endif
                            @if ($pendingDate = \App\Support\FinanceTime::persianDate($ownReview->updated_at ?? $ownReview->created_at))
                                <time datetime="{{ ($ownReview->updated_at ?? $ownReview->created_at)->toIso8601String() }}">{{ $pendingDate }}</time>
                            @endif
                        </div>
                    </header>
                    <p class="product-lines product-entry__body">{{ $ownReview->body }}</p>
                </article>
            @endif

            @forelse ($feedback[$kind] as $entry)
                <article class="product-entry">
                    <header class="product-entry__header">
                        @if ($kind === 'review')
                            <div class="product-entry__author">
                                <span class="review-author-icon"><x-storefront.icon name="account" /></span>
                                <div class="product-entry__author-meta">
                                    <strong>{{ $entry->verified_buyer ? 'خریدار تأییدشده' : 'دیدگاه کاربر' }}</strong>
                                    @if ($entry->verified_buyer)
                                        <span class="review-role-badge review-role-badge--verified">خرید تأییدشده</span>
                                    @endif
                                </div>
                            </div>
                            <div class="product-entry__meta-end">
                                @if ($entry->verified_buyer && $entry->rating)
                                    <span class="rating-stars rating-stars--display" dir="ltr" role="img" aria-label="{{ $entry->rating }} از ۵">
                                        @foreach (range(1, 5) as $number)
                                            <span @class(['is-filled' => $number <= $entry->rating]) aria-hidden="true">★<small class="sr-only">{{ $number }}</small></span>
                                        @endforeach
                                    </span>
                                @endif
                                @if ($posted = \App\Support\FinanceTime::persianDate($entry->created_at))
                                    <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $posted }}</time>
                                @endif
                            </div>
                        @else
                            <strong>پرسش</strong>
                            @if ($posted = \App\Support\FinanceTime::persianDate($entry->created_at))
                                <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $posted }}</time>
                            @endif
                        @endif
                    </header>
                    <p class="product-lines product-entry__body">{{ $entry->body }}</p>
                    @if ($entry->staff_reply)
                        <div class="product-reply">
                            <div class="product-reply__head">
                                <strong>پاسخ رسمی نوین‌پی</strong>
                            </div>
                            <p class="product-lines">{{ $entry->staff_reply }}</p>
                        </div>
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
                @unless ($hasPendingPreview)
                    <div class="review-empty">
                        <x-storefront.icon name="review" />
                        <div>
                            <p class="product-feedback-empty">{{ $kind === 'review' ? 'هنوز دیدگاهی برای این محصول ثبت نشده است.' : 'هنوز پرسشی برای این محصول ثبت نشده است.' }}</p>
                            <small>اولین نفری باشید که تجربهٔ استفاده از این محصول را ثبت می‌کند.</small>
                        </div>
                    </div>
                @endunless
            @endforelse
        </div>

        {{ $feedback[$kind]->links() }}

        @if ($feedbackAccount)
            <form class="product-feedback-form" id="submit-{{ $kind }}" tabindex="-1" method="post" action="{{ route('products.feedback.store', $product) }}">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <div hidden><label for="website-{{ $kind }}">وب‌سایت</label><input id="website-{{ $kind }}" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="product-feedback-form__head">
                    @if ($kind === 'review' && $ownReview?->status === 'pending')
                        <span class="product-feedback-status-pill product-feedback-status-pill--pending">دیدگاه شما در انتظار بررسی مدیریت است</span>
                    @elseif ($kind === 'review' && $ownReview?->status === 'rejected')
                        <p class="product-notice product-notice--warning">دیدگاه قبلی منتشر نشد. می‌توانید آن را ویرایش و دوباره ارسال کنید.</p>
                    @endif
                </div>
                @if ($kind === 'review' && $canRate)
                    <fieldset class="rating-choice">
                        <legend>امتیاز شما به این محصول (اختیاری)</legend>
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
                <div class="np-float-field" data-float-field>
                    <textarea class="input product-feedback-textarea" id="body-{{ $kind }}" name="body" rows="4" minlength="5" maxlength="1500" placeholder="تجربهٔ خود را از کارکرد، کیفیت ساخت، آنتن‌دهی و باتری محصول بنویسید (بدون شماره تماس یا اطلاعات شخصی)." required>{{ old('kind') === $kind ? old('body') : ($kind === 'review' ? $ownReview?->body : '') }}</textarea>
                    <label class="label" for="body-{{ $kind }}">{{ $kind === 'question' ? 'پرسش شما' : ($ownReview ? 'ویرایش دیدگاه شما' : 'ثبت دیدگاه شما') }}</label>
                </div>
                <div class="product-feedback-form__actions">
                    <button type="submit" class="btn btn-primary">{{ $kind === 'review' ? ($ownReview ? 'به‌روزرسانی دیدگاه' : 'ارسال دیدگاه') : 'ارسال پرسش' }}</button>
                </div>
            </form>
        @endif
    </section>
@endforeach
