@extends('layouts.app')
@section('title', 'Access restricted')

@section('content')
<header class="home-header"><div class="container">@include('partials.brand')</div></header>
<main id="main-content" class="container home-main">
    <section class="card home-card" aria-labelledby="error-heading">
        <div class="card-body">
            <span class="section-eyebrow">ACCESS RESTRICTED</span>
            <h1 id="error-heading">This area is restricted.</h1>
            @if(($exception?->getMessage() ?? '') === \App\Enums\ResidencyStatus::BLOCKED_MESSAGE)
                <p>{{ \App\Enums\ResidencyStatus::BLOCKED_MESSAGE }}</p>
                <a href="{{ route('resident.profile.edit') }}" class="btn btn-primary">Review residency status</a>
            @else
                <p>Your account does not have access to this page.</p>
            @endif
            <a href="{{ route('home') }}" class="btn btn-primary">Return to your home</a>
        </div>
    </section>
</main>
@endsection
