<?php

return [
    'docs_zip_password' => (string) env('DOCS_ZIP_PASSWORD', ''),

    'docs_retention_days' => max(1, (int) env('DOCS_RETENTION_DAYS', 7)),

    'docs_abandoned_days' => max(1, (int) env('DOCS_ABANDONED_DAYS', 30)),

    'docs_max_file_kb' => max(512, (int) env('DOCS_MAX_FILE_KB', 8192)),

    'docs_max_extra_files' => max(0, (int) env('DOCS_MAX_EXTRA_FILES', 4)),

    'activation_document_slots' => [
        'national_card_front' => [
            'label' => 'تصویر روی کارت ملی',
            'hint' => 'تصویر واضح از روی کارت ملی هوشمند یا رسید ثبت‌احوال',
            'zip_prefix' => '01-kart-meli-roo',
            'required' => true,
        ],
        'national_card_back' => [
            'label' => 'تصویر پشت کارت ملی',
            'hint' => 'تصویر واضح از پشت کارت ملی هوشمند',
            'zip_prefix' => '02-kart-meli-posht',
            'required' => true,
        ],
        'birth_certificate' => [
            'label' => 'تصویر صفحه اول شناسنامه',
            'hint' => 'تصویر کامل و خوانا از صفحه اول شناسنامه',
            'zip_prefix' => '03-shenasname',
            'required' => true,
        ],
        'bank_card_1' => [
            'label' => 'تصویر کارت بانکی اول',
            'hint' => 'تصویر روی کارت بانکی اصلی متصل به حساب شبا',
            'zip_prefix' => '04-kart-banki-1',
            'required' => true,
        ],
        'bank_card_2' => [
            'label' => 'تصویر کارت بانکی دوم',
            'hint' => 'در صورت نیاز به ثبت حساب بانکی دوم (اختیاری)',
            'zip_prefix' => '05-kart-banki-2',
            'required' => false,
        ],
        'tax_code' => [
            'label' => 'کد مالیاتی (اینتاکد)',
            'hint' => 'تصویر یا فایل PDF ثبت پرونده مالیاتی در سامانه my.tax.gov.ir',
            'zip_prefix' => '06-kode-maliati',
            'required' => true,
        ],
    ],
];
