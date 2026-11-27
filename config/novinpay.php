<?php

return [
    'docs_zip_password' => (string) env('DOCS_ZIP_PASSWORD', ''),

    'docs_retention_days' => max(1, (int) env('DOCS_RETENTION_DAYS', 7)),

    'docs_abandoned_days' => max(1, (int) env('DOCS_ABANDONED_DAYS', 30)),

    'docs_max_file_kb' => max(512, (int) env('DOCS_MAX_FILE_KB', 8192)),

    'docs_max_extra_files' => max(0, (int) env('DOCS_MAX_EXTRA_FILES', 4)),

    'activation_document_slots' => [
        'national_card' => [
            'label' => 'تصویر کارت ملی',
            'hint' => 'تصویر واضح از کارت ملی هوشمند یا رسید ثبت‌احوال',
            'zip_prefix' => '01-kart-meli',
            'required' => true,
        ],
        'birth_certificate' => [
            'label' => 'تصویر شناسنامه',
            'hint' => 'تصویر صفحه اول شناسنامه (و صفحه توضیحات در صورت وجود)',
            'zip_prefix' => '02-shenasname',
            'required' => true,
        ],
        'bank_iban' => [
            'label' => 'اطلاعات بانکی / شماره شبا',
            'hint' => 'تصویر تاییدیه شبا، دفترچه بانکی یا کارت متصل به حساب',
            'zip_prefix' => '03-hesab-sheba',
            'required' => true,
        ],
        'postal_address' => [
            'label' => 'کد پستی / مدرک نشانی',
            'hint' => 'تاییدیه کد پستی ۱۰ رقمی، قبض یا مدرک نشانی محل فعالیت',
            'zip_prefix' => '04-kode-posti-adres',
            'required' => true,
        ],
        'tax_code' => [
            'label' => 'کد مالیاتی (اینتاکد)',
            'hint' => 'تصویر یا فایل PDF ثبت پرونده مالیاتی در سامانه my.tax.gov.ir',
            'zip_prefix' => '05-kode-maliati',
            'required' => true,
        ],
    ],
];
