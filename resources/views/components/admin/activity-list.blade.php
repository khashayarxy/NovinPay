@props(['activities'])
@forelse ($activities as $activity)
    <li class="admin-activity"><span class="admin-activity__dot" aria-hidden="true"></span><div><h3>{{ $activity->action->label() }}</h3><p>عامل: {{ $activity->actor_id ? 'کاربر '.\App\Support\FaNumber::digits($activity->actor_id) : 'سامانه' }} · هدف: {{ ['user' => 'حساب', 'product' => 'محصول', 'repair_request' => 'درخواست آنلاین', 'article' => 'مقاله', 'article_version' => 'نسخهٔ مقاله', 'article_category' => 'دستهٔ مقاله'][$activity->target_type] ?? 'رکورد' }} {{ \App\Support\FaNumber::digits($activity->target_id ?? '—') }}</p><time datetime="{{ $activity->created_at->toIso8601String() }}" dir="ltr">{{ \App\Support\FaNumber::digits($activity->created_at->format('Y/m/d H:i')) }} UTC</time></div></li>
@empty
    <li class="admin-empty"><x-home.icon name="clock" /><h3>هنوز فعالیتی ثبت نشده است</h3><p>عملیات مهم مدیریت پس از انجام در این بخش نمایش داده می‌شوند.</p></li>
@endforelse
