@foreach (preg_split('/\R[\p{Zs}\t]*\R+/u', trim($copy), -1, PREG_SPLIT_NO_EMPTY) as $paragraph)
    <p class="product-lines">{{ $paragraph }}</p>
@endforeach
