@extends('layouts.app')
@section('title', $area.' Area')

@section('content')
<header class="home-header">
    <div class="container d-flex align-items-center justify-content-between gap-3">
        @include('partials.brand')
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary">Logout</button>
        </form>
    </div>
</header>
<main id="main-content" class="container home-main">
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    <section class="card home-card" aria-labelledby="home-heading">
        <div class="card-body">
            <span class="area-badge">{{ $area }} Area</span>
            <h1 id="home-heading">Welcome, {{ auth()->user()->name }}</h1>
            <p class="home-location">Barangay Calayo <span aria-hidden="true">·</span> Nasugbu, Batangas</p>
            <p class="mb-0">The complete {{ $area }} Dashboard will be implemented in the next development phase.</p>
        </div>
    </section>
</main>
@endsection
