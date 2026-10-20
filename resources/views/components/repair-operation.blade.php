@props(['case', 'action', 'title'])
<form method="post" action="{{ route('admin.repair-cases.'.$action, $case) }}" class="admin-panel admin-form repair-operation">
    @csrf<input type="hidden" name="version" value="{{ $case->version }}">
    <h2>{{ $title }}</h2>
    {{ $slot }}
</form>
