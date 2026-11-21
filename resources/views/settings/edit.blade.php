@extends('layouts.authenticated')

@section('dashboard')
@php($savedTheme = auth()->user()->preferredTheme())
<div class="user-preferences">
    <x-page-intro subtitle="Your appearance preference follows your account when you sign in again."/>
    <div class="ac-grid is-settings">
        <section class="card dashboard-panel" aria-labelledby="appearance-heading">
            <div class="dashboard-panel-heading"><h2 id="appearance-heading"><span class="panel-icon is-indigo" aria-hidden="true"><x-app-icon name="dashboard"/></span>Appearance</h2></div>
            <div class="ac-card-body">
                <p class="app-note mt-0">Choose a light or dark interface. Document previews and printed certificates keep their original appearance.</p>
                @if($errors->any())
                    <div class="alert alert-danger" role="alert"><strong>Your preference was not saved.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                <form method="POST" action="{{ route(auth()->user()->role->value.'.settings.update') }}" data-preferences-form>
                    @csrf
                    @method('PUT')
                    <fieldset class="m-0 p-0 border-0">
                        <legend class="visually-hidden">Interface theme</legend>
                        <div class="rv-options ac-theme-options">
                            <x-option-card name="theme_preference" value="light" id="light-mode" :checked="$savedTheme !== 'dark'" describedby="theme-help">
                                <span class="ac-theme-preview is-light" aria-hidden="true"></span>
                                <span class="rv-option-title">Light mode</span>
                                <span class="rv-option-text">Bright surfaces for daytime use.</span>
                            </x-option-card>
                            <x-option-card name="theme_preference" value="dark" id="dark-mode" :checked="$savedTheme === 'dark'" describedby="theme-help">
                                <span class="ac-theme-preview is-dark" aria-hidden="true"></span>
                                <span class="rv-option-title">Dark mode</span>
                                <span class="rv-option-text">Navy surfaces that are easier at night.</span>
                            </x-option-card>
                        </div>
                    </fieldset>
                    <p class="form-text" id="theme-help">Preview the appearance here, then save to apply it to your account.</p>
                    <div class="ac-form-actions">
                        <p class="app-note me-auto mb-0" role="status" aria-live="polite" data-theme-feedback>Saved appearance: {{ ucfirst($savedTheme) }}.</p>
                        <button type="submit" class="btn btn-primary">Save appearance</button>
                    </div>
                </form>
            </div>
        </section>
        <div class="ac-stack">
            <section class="card dashboard-panel" aria-labelledby="about-settings-heading">
                <div class="dashboard-panel-heading"><h2 id="about-settings-heading"><span class="panel-icon is-teal" aria-hidden="true"><x-app-icon name="globe"/></span>About SmartBarangay</h2></div>
                <div class="ac-card-body">
                    <p class="mt-0">A web-based barangay document reservation system for Barangay Calayo, Nasugbu, Batangas.</p>
                    <dl class="ac-about"><dt>What you can do</dt><dd>Request document services, follow reservation updates, and present your QR ticket for authorized verification.</dd><dt>Account roles</dt><dd>Resident and Admin</dd></dl>
                    <a class="btn btn-outline-primary" href="{{ route('home') }}">Visit public website</a>
                </div>
            </section>
            @if(auth()->user()->role === \App\Enums\UserRole::Admin)
                <section class="card dashboard-panel" aria-labelledby="system-controls-heading">
                    <div class="dashboard-panel-heading"><h2 id="system-controls-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="services"/></span>System controls</h2></div>
                    <div class="ac-card-body"><p class="app-note m-0">Reserved for future approved system controls. No operational switches are available in this phase.</p></div>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
