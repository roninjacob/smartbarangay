@extends('layouts.app')
@section('title', 'Access restricted')

@section('content')
<header class="home-header"><div class="container">@include('partials.brand')</div></header>
<main id="main-content" class="container home-main">
    <section class="card home-card" aria-labelledby="error-heading">
        <div class="card-body">
            <span class="section-eyebrow">ACCESS RESTRICTED</span>
            <h1 id="error-heading">This area is restricted.</h1>
            <p>Your account does not have access to this page.</p>
            <a href="{{ route('home') }}" class="btn btn-primary">Return to your home</a>
        </div>
    </section>
</main>
@endsection
