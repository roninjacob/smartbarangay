@extends('layouts.auth')
@section('shell-class', 'auth-shell-action')
@section('auth-intro')
<div class="auth-heading">
    <div class="auth-action-title">
        <x-auth-symbol :type="$icon ?? 'mail'" />
        <div>
            <span class="section-eyebrow">@yield('eyebrow')</span>
            <h2 id="auth-heading">@yield('heading')</h2>
        </div>
    </div>
    <p class="mb-0">@yield('description')</p>
</div>
@endsection
