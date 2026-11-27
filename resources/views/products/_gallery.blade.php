<section class="gallery card" data-product-gallery aria-label="تصاویر محصول">
    @if ($images)
        <div class="photo-wrap">
            <button class="main-photo" type="button" data-gallery-open aria-haspopup="dialog" aria-controls="product-gallery-dialog" aria-label="بزرگ‌نمایی تصویر">
                <x-product-image :product="$product" :image="$images[0]" eager />
                <span class="gallery-zoom-mark" aria-hidden="true"><x-storefront.icon name="zoom" /></span>
            </button>
            @if (count($images) > 1)
                <button type="button" class="gallery-arrow previous icon-button" data-gallery-step="-1" aria-label="تصویر قبلی"><x-storefront.icon name="arrow" /></button>
                <button type="button" class="gallery-arrow next icon-button" data-gallery-step="1" aria-label="تصویر بعدی"><x-storefront.icon name="arrow" /></button>
            @endif
        </div>
    @endif
    @if (count($images) > 1)
        <div class="gallery-footer">
            <div class="thumbs" role="group" aria-label="انتخاب تصویر">
                @foreach ($images as $image)
                    <button type="button" data-gallery-choice aria-pressed="{{ $loop->first ? 'true' : 'false' }}" aria-label="{{ $image['alt'] ?? 'تصویر '.$product->name }}"><x-product-image :product="$product" :image="$image" sizes="56px" /></button>
                @endforeach
            </div>
            <span class="image-count" data-gallery-count aria-live="polite">{{ \App\Support\FaNumber::digits(1) }} از {{ \App\Support\FaNumber::digits(count($images)) }}</span>
        </div>
    @endif
    @if ($images)
        <dialog id="product-gallery-dialog" class="product-lightbox" data-gallery-dialog data-storefront-dialog aria-labelledby="product-lightbox-heading">
            <div class="overlay-heading"><h2 id="product-lightbox-heading">تصاویر {{ $product->name }}</h2><button type="button" data-storefront-close aria-label="بستن تصویر"><x-storefront.icon name="close" /></button></div>
            <img data-gallery-zoom-image alt="">
            @if (count($images) > 1)
                <div class="dialog-nav"><button type="button" data-gallery-step="-1" aria-label="تصویر قبلی"><x-storefront.icon name="arrow" /></button><span data-zoom-count aria-live="polite"></span><button type="button" data-gallery-step="1" aria-label="تصویر بعدی"><x-storefront.icon name="arrow" /></button></div>
            @endif
        </dialog>
    @else
        <div class="product-empty-photo" role="img" aria-label="تصویر واقعی کالا هنوز ثبت نشده است">
            <span class="product-empty-photo__art" aria-hidden="true">
                <svg viewBox="0 0 120 96" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
                    <rect x="34" y="8" width="52" height="80" rx="10" fill="#dbeafe" stroke="#93c5fd" stroke-width="2" />
                    <rect x="41" y="16" width="38" height="22" rx="4" fill="#ffffff" />
                    <rect x="46" y="45" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="58" y="45" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="70" y="45" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="46" y="57" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="58" y="57" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="70" y="57" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="46" y="69" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="58" y="69" width="8" height="7" rx="2" fill="#ffffff" />
                    <rect x="70" y="69" width="8" height="7" rx="2" fill="#ffffff" />
                </svg>
            </span>
            <p class="product-empty-photo__label">تصویر واقعی کالا هنوز ثبت نشده است.</p>
        </div>
    @endif
</section>
