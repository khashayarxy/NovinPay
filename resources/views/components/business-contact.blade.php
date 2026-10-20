<dl class="space-y-3 text-sm leading-7">
    <div>
        <dt class="font-semibold">نشانی</dt>
        <dd>{{ config('business.address') }}</dd>
    </div>
    <div>
        <dt class="font-semibold">تلفن</dt>
        <dd><a href="{{ config('business.phone_uri') }}" class="contact-link" dir="ltr">{{ config('business.phone') }}</a></dd>
    </div>
    <div>
        <dt class="font-semibold">ساعات کاری</dt>
        <dd>{{ config('business.hours.open') }}<br>{{ config('business.hours.closed') }}</dd>
    </div>
</dl>
