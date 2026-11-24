@props(['activities', 'meta' => []])
@php
    $actors = $meta['actors'] ?? [];
    $targets = $meta['targets'] ?? [];
@endphp
@forelse ($activities as $activity)
    @php
        $actorLabel = $activity->actor_id
            ? ($actors[$activity->actor_id] ?? 'کاربر '.\App\Support\FaNumber::digits($activity->actor_id))
            : 'سامانه';
        $targetKey = ($activity->target_type ?? '').':'.($activity->target_id ?? 'none');
        $target = $targets[$targetKey] ?? null;
    @endphp
    <li class="admin-activity">
        <span class="admin-activity__dot" aria-hidden="true"></span>
        <div>
            <h3>{{ $activity->action->label() }}</h3>
            <p>
                {{ $actorLabel }}
                @if($target)
                    ·
                    @if($target['is_html'])
                        {!! $target['label'] !!}
                    @else
                        {{ $target['label'] }}
                    @endif
                @endif
            </p>
            <x-finance-time :value="$activity->created_at" />
        </div>
    </li>
@empty
    <li class="admin-empty"><x-home.icon name="clock" /><h3>هنوز فعالیتی ثبت نشده است</h3><p>عملیات مهم مدیریت پس از انجام در این بخش نمایش داده می‌شوند.</p></li>
@endforelse
