@props(['proof' => null, 'revision' => null])
<input type="hidden" name="cart_context" value="{{ $proof ?? app(\App\Services\ShoppingCart::class)->proof(request()) }}">
@if ($revision !== null)<input type="hidden" name="cart_revision" value="{{ $revision }}">@endif
