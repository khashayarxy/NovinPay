<section class="product-panel product-faq" id="product-faq" aria-labelledby="faq-heading">
    <h2 id="faq-heading">پرسش‌های متداول</h2>
    @foreach (preg_split('/\R[\p{Zs}\t]*\R+/u', trim($copy), -1, PREG_SPLIT_NO_EMPTY) as $block)
        @php($lines = preg_split('/\R/u', $block, 2))
        @if (isset($lines[1]) && str_ends_with(trim($lines[0]), '؟'))
            <details>
                <summary>{{ \App\Support\LatinText::html($lines[0]) }}<span aria-hidden="true" class="product-accordion-icon"></span></summary>
                <div class="product-faq-answer">
                    @include('products._paragraphs', ['copy' => $lines[1]])
                </div>
            </details>
        @else
            <p class="product-lines">{{ \App\Support\LatinText::html($block) }}</p>
        @endif
    @endforeach
</section>
