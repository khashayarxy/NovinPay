@props(['method', 'label', 'description', 'cost', 'disabled' => false])
<label class="commerce-choice">
    <input type="radio" name="method" value="{{ $method }}" required @checked(old('method') === $method) @disabled($disabled)
        @if ($disabled || $description !== $cost) aria-describedby="delivery-{{ $method }}-description" @endif>
    <span class="commerce-choice__title">{{ $label }}</span>
    <span class="commerce-choice__cost">{{ $cost }}</span>
    @if ($disabled || $description !== $cost)
    <span class="commerce-choice__note" id="delivery-{{ $method }}-description">
        {{ $disabled ? 'برای این روش، ابتدا نشانی تحویل را ذخیره کنید.' : $description }}
    </span>
    @endif
</label>
