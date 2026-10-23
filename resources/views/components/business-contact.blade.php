<dl class="space-y-3 text-sm leading-7">
    <div>
        <dt class="font-semibold">نشانی</dt>
        <dd>{{ config('business.address') }}</dd>
    </div>
    <div>
        <dt class="font-semibold">تلفن</dt>
        <dd><x-business-phone class="contact-link" /></dd>
    </div>
    <div>
        <dt class="font-semibold">ساعات کاری</dt>
        <dd>{{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}</dd>
    </div>
</dl>
