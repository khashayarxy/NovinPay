@props(['name', 'label', 'kind' => 'input', 'type' => 'text', 'value' => '', 'required' => true, 'maxlength' => 2000])
@php($fieldId = 'repair-'.$name.'-'.\Illuminate\Support\Str::random(6))
<div><label class="label" for="{{ $fieldId }}">{{ $label }}</label>
@if($kind === 'textarea')<textarea class="input" id="{{ $fieldId }}" name="{{ $name }}" rows="3" maxlength="{{ $maxlength }}" @required($required)>{{ $value }}</textarea>
@else<input class="input" id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" maxlength="{{ $maxlength }}" @if($type === 'number') min="0" max="1000000000" step="1" dir="ltr" @endif @if(in_array($type, ['tel', 'datetime-local'])) dir="ltr" @endif autocomplete="off" @required($required)>
@endif</div>
