@props(['name'])
@php($brand = preg_match('/newland/i', $name) ? 'Newland' : (preg_match('/newpos/i', $name) ? 'NewPOS' : (preg_match('/\bpax\b/i', $name) ? 'PAX' : null)))
@if($brand)<img class="design-manufacturer design-manufacturer--{{ strtolower($brand) }}" src="{{ asset('brand/manufacturers/'.$brand.'.svg') }}" alt="{{ $brand }}" width="150" height="70" loading="lazy" decoding="async">@endif
