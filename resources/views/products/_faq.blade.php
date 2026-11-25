<section class="product-panel product-faq" id="product-faq" aria-labelledby="faq-heading">
    <h2 id="faq-heading">پرسش‌های متداول</h2>
    @foreach (preg_split('/\R[\p{Zs}\t]*\R+/u', trim($copy), -1, PREG_SPLIT_NO_EMPTY) as $block)
        @php($lines = preg_split('/\R/u', $block, 2))
        @if (isset($lines[1]) && str_ends_with(trim($lines[0]), '؟'))
            <details>
                <summary><span class="product-faq-question">{{ \App\Support\LatinText::html($lines[0]) }}</span><svg class="product-faq-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></summary>
                <div class="product-faq-answer">
                    @include('products._paragraphs', ['copy' => $lines[1]])
                </div>
            </details>
        @else
            <p class="product-lines">{{ \App\Support\LatinText::html($block) }}</p>
        @endif
    @endforeach
</section>
