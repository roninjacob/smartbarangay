@props(['name', 'value', 'id', 'checked' => false, 'disabled' => false, 'required' => false, 'describedby' => null])
{{-- A native radio styled as a selectable card. Keyboard, form submission and validation stay native. --}}
<label {{ $attributes->class(['rv-option', 'is-disabled' => $disabled]) }} for="{{ $id }}">
    <input id="{{ $id }}" type="radio" name="{{ $name }}" value="{{ $value }}" class="rv-option-input" @checked($checked) @disabled($disabled) @required($required) @if($describedby) aria-describedby="{{ $describedby }}" @endif>
    <span class="rv-option-body">{{ $slot }}</span>
    <span class="rv-option-check" aria-hidden="true"></span>
</label>
