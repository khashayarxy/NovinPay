<dl class="space-y-3 text-sm leading-7">
    <div>
        <dt class="font-semibold">نشانی</dt>
        <dd>{{ config('business.address') }}</dd>
    </div>
    <div>
        <dt class="font-semibold">تلفن</dt>
        <dd><x-business-phones link-class="contact-link" /></dd>
    </div>
    <div>
        <dt class="font-semibold">ساعات کاری</dt>
        <dd><x-business-hours /></dd>
    </div>
</dl>
