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
        $hasOwnReview = $kind === 'review'
            && $feedbackAccount
            && $ownReview
            && (int) $ownReview->customer_account_id === (int) $feedbackAccount->id;
        $hasUnpublishedOwnReview = $hasOwnReview && $ownReview->status !== 'approved';
        $votedIds = $votedFeedbackIds ?? [];
        $openReviewForm = ! $hasOwnReview || $errors->any() || request()->boolean('edit_review');
        $showRatingSummary = $kind === 'review' && ($productRating || $feedback[$kind]->total() > 0);
        $summaryAvg = $productRating['value'] ?? 0;
        $summaryCount = (int) ($productRating['count'] ?? 0);
        $summaryDist = $productRating['distribution'] ?? collect();
    @endphp
    <section class="product-panel product-feedback product-feedback--{{ $kind }}" id="product-{{ $kind }}" aria-labelledby="{{ $kind }}-heading">
        <div class="product-feedback-heading">
            <div class="product-feedback-heading__titles">
                <h2 id="{{ $kind }}-heading">{{ $heading }}</h2>
                @if ($feedback[$kind]->total())
                    <p class="product-feedback-count">{{ \App\Support\FaNumber::digits($feedback[$kind]->total()) }} {{ $kind === 'review' ? 'دیدگاه منتشرشده' : 'پرسش منتشرشده' }}</p>
                @endif
            </div>
            @unless ($feedbackAccount)
                <a class="btn btn-primary" href="{{ route('account.login') }}">{{ $kind === 'review' ? 'ورود و ثبت دیدگاه' : 'طرح پرسش' }}</a>
            @endunless
        </div>

        @if ($showRatingSummary)
            <div class="product-rating-summary" data-rating-summary>
                <div class="product-rating-hero">
                    <strong class="product-score-big">{{ $summaryCount > 0 ? str_replace('.', '٫', \App\Support\FaNumber::digits((string) $summaryAvg)) : '—' }}</strong>
                    <div class="product-rating-hero__meta">
                        <span class="rating-stars rating-stars--summary" dir="ltr" aria-hidden="true">
                            @foreach (range(1, 5) as $starIdx)
                                <span @class(['is-filled' => $summaryCount > 0 && $starIdx <= round((float) $summaryAvg)])>★</span>
                            @endforeach
                        </span>
                        @if ($summaryCount > 0)
                            <p class="product-score">{{ str_replace('.', '٫', \App\Support\FaNumber::digits((string) $summaryAvg)) }} از ۵ · {{ \App\Support\FaNumber::digits($summaryCount) }} امتیاز از خریداران تأییدشده</p>
                        @else
                            <p class="product-score">بر اساس {{ \App\Support\FaNumber::digits($feedback[$kind]->total()) }} دیدگاه ثبت‌شده کاربران</p>
                        @endif
                    </div>
                </div>
                <ul class="product-rating-breakdown" aria-label="توزیع امتیاز خریداران">
                    @foreach (range(5, 1) as $stars)
                        @php
                            $count = (int) $summaryDist->get($stars, 0);
                            $pct = $summaryCount > 0 ? round(($count / $summaryCount) * 100) : 0;
                        @endphp
                        <li>
                            <span class="product-rating-breakdown__label">{{ \App\Support\FaNumber::digits($stars) }} ستاره</span>
                            <span class="product-rating-bar" aria-hidden="true"><i style="width: {{ $pct }}%"></i></span>
                            <meter class="sr-only" min="0" max="{{ max(1, $summaryCount) }}" value="{{ $count }}"
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
            @if ($hasUnpublishedOwnReview)
                <article
                    class="product-entry product-entry--own"
                    @if ($ownReview->status === 'pending') data-review-preview="pending" @endif
                    aria-label="دیدگاه ثبت‌شده شما"
                >
                    <header class="product-entry__header">
                        <div class="product-entry__author">
                            <span class="review-author-icon"><x-storefront.icon name="account" /></span>
                            <div class="product-entry__author-meta">
                                <strong>{{ $feedbackAccount->name ?: ($ownReview->verified_buyer ? 'خریدار تأییدشده' : 'دیدگاه شما') }}</strong>
                                @if ($ownReview->verified_buyer)
                                    <span class="review-role-badge review-role-badge--verified">
                                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>
                                        <span>خریدار محصول</span>
                                    </span>
                                @else
                                    <span class="review-role-badge">کاربر سایت</span>
                                @endif
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
                    <footer class="product-entry__owner-bar" data-own-review-footer>
                        <span class="product-entry__owner-status product-entry__owner-status--{{ $ownReview->status }}">
                            <span class="product-entry__owner-dot" aria-hidden="true"></span>
                            @if ($ownReview->status === 'pending')
                                <span>دیدگاه شما ثبت شده و پس از تأیید مدیریت نمایش داده می‌شود.</span>
                            @else
                                <span>دیدگاه قبلی منتشر نشد؛ می‌توانید متن را ویرایش و دوباره ارسال کنید.</span>
                            @endif
                        </span>
                        <button
                            type="button"
                            class="product-entry__edit-btn"
                            data-toggle-review-edit="submit-{{ $kind }}"
                            aria-expanded="{{ $openReviewForm ? 'true' : 'false' }}"
                            aria-controls="submit-{{ $kind }}"
                        >ویرایش دیدگاه</button>
                    </footer>
                </article>
            @endif

            @forelse ($feedback[$kind] as $entry)
                @php
                    $isOwnPublished = $hasOwnReview && (int) $entry->id === (int) $ownReview->id;
                    $isVoted = in_array((int) $entry->id, $votedIds, true);
                    $helpfulCount = (int) ($entry->helpful_count ?? 0);
                @endphp
                <article class="product-entry">
                    <header class="product-entry__header">
                        @if ($kind === 'review')
                            <div class="product-entry__author">
                                <span class="review-author-icon"><x-storefront.icon name="account" /></span>
                                <div class="product-entry__author-meta">
                                    <strong>{{ $entry->verified_buyer ? 'خریدار تأییدشده' : 'دیدگاه کاربر' }}</strong>
                                    @if ($entry->verified_buyer)
                                        <span class="review-role-badge review-role-badge--verified" data-verified-buyer-badge>
                                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>
                                            <span>خریدار محصول</span>
                                        </span>
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
                        <div class="product-reply" data-official-reply>
                            <div class="product-reply__head">
                                <img src="{{ versioned_asset('brand/novinpay-np-blue.svg') }}" alt="" width="22" height="22" class="product-reply__logo" aria-hidden="true">
                                <strong>پشتیبانی نوین‌پی</strong>
                                <span class="product-reply__tag">پاسخ رسمی نوین‌پی</span>
                            </div>
                            <p class="product-lines">{{ $entry->staff_reply }}</p>
                        </div>
                    @endif

                    <footer class="product-entry__footer">
                        @if ($kind === 'review')
                            <form method="post" action="{{ route('products.feedback.helpful', [$product, $entry]) }}" class="product-helpful-form" data-helpful-form>
                                @csrf
                                <button
                                    type="submit"
                                    @class(['product-helpful-btn', 'is-voted' => $isVoted])
                                    data-helpful-btn
                                    aria-pressed="{{ $isVoted ? 'true' : 'false' }}"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>
                                    <span>مفید بود</span>
                                    <span class="product-helpful-count" data-helpful-count @if($helpfulCount === 0) hidden @endif>{{ \App\Support\FaNumber::digits($helpfulCount) }}</span>
                                </button>
                            </form>
                        @endif

                        <div class="product-entry__footer-end">
                            @if ($isOwnPublished)
                                <button
                                    type="button"
                                    class="product-entry__edit-btn"
                                    data-toggle-review-edit="submit-{{ $kind }}"
                                    aria-expanded="{{ $openReviewForm ? 'true' : 'false' }}"
                                    aria-controls="submit-{{ $kind }}"
                                >ویرایش دیدگاه شما</button>
                            @endif

                            @if ($feedbackAccount && ! $isOwnPublished)
                                <div class="product-report">
                                    <button type="button" class="product-report-toggle" data-toggle-panel="product-report-{{ $entry->id }}" aria-expanded="false" aria-controls="product-report-{{ $entry->id }}">گزارش محتوا</button>
                                </div>
                            @endif
                        </div>
                    </footer>

                    @if ($feedbackAccount && ! $isOwnPublished)
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
                    @endif
                </article>
            @empty
                @unless ($hasUnpublishedOwnReview)
                    <div class="review-empty">
                        <span class="review-empty__icon" aria-hidden="true"><x-storefront.icon name="review" /></span>
                        <div class="review-empty__copy">
                            <p class="product-feedback-empty">{{ $kind === 'review' ? 'هنوز دیدگاهی برای این محصول ثبت نشده است.' : 'هنوز پرسشی برای این محصول ثبت نشده است.' }}</p>
                            <small>تجربهٔ واقعی استفاده از این دستگاه را با دیگر خریداران به اشتراک بگذارید.</small>
                        </div>
                    </div>
                @endunless
            @endforelse
        </div>

        {{ $feedback[$kind]->links() }}

        @if ($feedbackAccount)
            <form
                class="product-feedback-form"
                id="submit-{{ $kind }}"
                tabindex="-1"
                method="post"
                action="{{ route('products.feedback.store', $product) }}"
                data-review-edit-form
                @if (! $openReviewForm) hidden @endif
            >
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <div hidden><label for="website-{{ $kind }}">وب‌سایت</label><input id="website-{{ $kind }}" name="website" tabindex="-1" autocomplete="off"></div>
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
                    @if ($hasOwnReview)
                        <button type="button" class="btn btn-secondary" data-cancel-review-edit="submit-{{ $kind }}">انصراف</button>
                    @endif
                </div>
            </form>
        @endif
    </section>
@endforeach
