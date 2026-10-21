@props(['action', 'version', 'title'])
<section class="admin-panel"><h2>{{ $title }}</h2><form class="admin-form mt-4" method="post" action="{{ $action }}">@csrf<input type="hidden" name="version" value="{{ $version }}">{{ $slot }}</form></section>
