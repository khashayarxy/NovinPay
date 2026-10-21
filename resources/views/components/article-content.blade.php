@props(['blocks'])
<div class="article-copy">
@foreach($blocks as $block)
@if($block['type']==='heading')
    @if($block['level']===2)<h2><x-article-inlines :nodes="$block['inlines']"/></h2>@else<h3><x-article-inlines :nodes="$block['inlines']"/></h3>@endif
@elseif($block['type']==='list')
    @if($block['ordered'])<ol>@foreach($block['items'] as $item)<li><x-article-inlines :nodes="$item"/></li>@endforeach</ol>@else<ul>@foreach($block['items'] as $item)<li><x-article-inlines :nodes="$item"/></li>@endforeach</ul>@endif
@else<p><x-article-inlines :nodes="$block['inlines']"/></p>@endif
@endforeach
</div>
