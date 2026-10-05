@props([
    'name', 'label', 'type' => 'text', 'autocomplete' => null,
    'hint' => null, 'maxlength' => null, 'minlength' => null,
    'placeholder' => null, 'labelAction' => null,
])
<div class="form-field">
    @if($labelAction)
        <div class="form-label-row">
            <label for="{{ $name }}" class="form-label">{{ $label }}</label>
            <a class="auth-forgot-link" href="{{ $labelAction }}">Forgot Password?</a>
        </div>
    @else
        <label for="{{ $name }}" class="form-label">{{ $label }}</label>
    @endif
    <div @class(['password-field' => $type === 'password'])>
        <input
            id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
            @class(['form-control', 'is-invalid' => $errors->has($name)])
            @if($type !== 'password') value="{{ old($name) }}" @endif
            @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if($maxlength) maxlength="{{ $maxlength }}" @endif
            @if($minlength) minlength="{{ $minlength }}" @endif
            @if($placeholder) placeholder="{{ $placeholder }}" @endif
            aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
            @if($hint || $errors->has($name))
                aria-describedby="{{ $hint ? $name.'-hint' : '' }} {{ $errors->has($name) ? $name.'-error' : '' }}"
            @endif
            required
        >
        @if($type === 'password')
            <button type="button" class="password-toggle" data-password-toggle="{{ $name }}"
                aria-controls="{{ $name }}" aria-label="Show {{ strtolower($label) }}" aria-pressed="false" hidden>
                Show
            </button>
        @endif
    </div>
    @if($hint)<div id="{{ $name }}-hint" class="form-text">{{ $hint }}</div>@endif
    @error($name)<div id="{{ $name }}-error" class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
