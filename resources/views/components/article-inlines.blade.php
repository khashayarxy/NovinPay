@props(['nodes'])
@foreach($nodes as $node)
@if($node['type']==='strong')<strong>{{ $node['text'] }}</strong>@elseif($node['type']==='em')<em>{{ $node['text'] }}</em>@elseif($node['type']==='link')<a href="{{ $node['url'] }}" rel="noopener noreferrer">{{ $node['text'] }}</a>@else{{ $node['text'] }}@endif
@endforeach
